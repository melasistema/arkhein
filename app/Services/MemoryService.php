<?php

namespace App\Services;

use App\Models\Knowledge;
use App\Models\Setting;
use App\Models\ManagedFolder;
use Centamiv\Vektor\Core\Config;
use Centamiv\Vektor\Services\Indexer;
use Centamiv\Vektor\Services\Searcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MemoryService
{
    protected string $basePath;
    protected ?int $currentFolderId = null;

    public function __construct()
    {
        $this->basePath = storage_path('app/vektor');
        Log::debug("Arkhein Vektor: Base path set to " . $this->basePath);
    }

    protected bool $isScoped = false;

    /**
     * Wrap an operation in a partition-safe scope.
     */
    public function withScope(?int $folderId, callable $callback, bool $shadow = false)
    {
        // Re-entrant check: if we are already in a scope, just run the callback
        if ($this->isScoped) {
            return $callback($this->getPartitionPath($folderId, $shadow));
        }

        $execution = function() use ($folderId, $callback, $shadow) {
            $this->isScoped = true;
            try {
                $path = $this->getPartitionPath($folderId, $shadow);
                
                if (!File::isDirectory($path)) {
                    File::makeDirectory($path, 0755, true);
                }

                // Set static Vektor config right before execution
                Config::setDataDir((string) realpath($path));
                
                return $callback($path);
            } finally {
                $this->isScoped = false;
            }
        };

        // Shadow partitions are process-isolated and don't require locking the primary
        if ($shadow) {
            return $execution();
        }

        return $this->withLock($folderId, $execution);
    }

    protected function getPartitionPath(?int $folderId, bool $shadow = false): string
    {
        $suffix = $shadow ? '_shadow' : '';
        return $folderId 
            ? $this->basePath . DIRECTORY_SEPARATOR . 'folder_' . $folderId . $suffix
            : $this->basePath . DIRECTORY_SEPARATOR . 'global' . $suffix;
    }

    protected function withLock(?int $folderId, callable $callback)
    {
        $path = $this->getPartitionPath($folderId);
        File::ensureDirectoryExists($path);
        
        $lockPath = $path . DIRECTORY_SEPARATOR . 'vektor_atomic.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new \RuntimeException("Arkhein Critical: Could not create lock file for partition [{$folderId}]");
        }

        $timeout = config('arkhein.memory.lock_timeout', 10); // 10s default
        $start = microtime(true);

        try {
            // Attempt non-blocking lock with exponential backoff
            $retryCount = 0;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) - $start >= $timeout) {
                    Log::error("Arkhein Memory: Lock timeout for partition [{$folderId}]");
                    throw new \RuntimeException("Timeout acquiring lock for partition [{$folderId}] after {$timeout}s. Another process may be indexing.");
                }
                
                // Exponential backoff: 10ms, 20ms, 40ms, ... up to 250ms
                $wait = min(250000, 10000 * pow(2, $retryCount));
                usleep($wait);
                $retryCount++;
            }
            
            return $callback();
        } finally {
            if ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Prepare a shadow directory for zero-downtime rebuilds.
     */
    public function prepareShadow(?int $folderId = null): void
    {
        $this->withScope($folderId, function() use ($folderId) {
            $shadowPath = $this->getPartitionPath($folderId, true);
            if (File::isDirectory($shadowPath)) {
                File::deleteDirectory($shadowPath);
            }
            File::makeDirectory($shadowPath, 0755, true);
            $this->clearBinaryFiles($shadowPath);
        });
    }

    /**
     * Swap the shadow index with the primary index.
     */
    public function swapShadow(?int $folderId = null): void
    {
        $this->withScope($folderId, function() use ($folderId) {
            $primaryPath = $this->getPartitionPath($folderId);
            $shadowPath = $this->getPartitionPath($folderId, true);

            if (!File::isDirectory($shadowPath)) {
                throw new \RuntimeException("Cannot swap: Shadow directory does not exist.");
            }

            // Perform atomic swap
            $this->swapDirectories($shadowPath, $primaryPath);
        });
    }

    protected function swapDirectories(string $source, string $target): void
    {
        $temp = $target . '_old_' . time();
        
        // 1. Target -> Temp
        if (File::isDirectory($target)) {
            File::moveDirectory($target, $temp);
        }

        // 2. Source -> Target
        File::moveDirectory($source, $target);

        // 3. Cleanup Temp
        if (File::isDirectory($temp)) {
            File::deleteDirectory($temp);
        }
    }

    /**
     * Ensure the index is ready and matches the current state.
     */
    public function ensureIndex(?int $dimensions = null, ?int $folderId = null): bool
    {
        return $this->withScope($folderId, function () use ($dimensions, $folderId) {
            $dimensions = $dimensions ?? (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
            Config::setDimensions($dimensions);

            $currentHash = $this->computeStateHash($folderId, $dimensions);
            $storedHash = $this->getStoredHash($folderId);

            $path = Config::getDataDir();
            $vectorFile = $path . DIRECTORY_SEPARATOR . 'vector.bin';

            if (!File::exists($vectorFile) || filesize($vectorFile) === 0 || $currentHash !== $storedHash) {
                Log::info("Arkhein Vektor: Integrity mismatch for partition [{$folderId}]. Rebuilding...");
                return $this->rebuildIndex($dimensions, $folderId);
            }

            return true;
        });
    }

    /**
     * Compute a unique hash for the current state of a partition.
     */
    protected function computeStateHash(?int $folderId, int $dimensions): string
    {
        $query = Knowledge::on('nativephp');
        if ($folderId) {
            $query->where('metadata->folder_id', $folderId);
        }

        $count = $query->count();
        $lastUpdate = $query->latest('updated_at')->value('updated_at') ?? 'never';

        return md5("v1|{$folderId}|{$dimensions}|{$count}|{$lastUpdate}");
    }

    protected function getStoredHash(?int $folderId): ?string
    {
        if ($folderId) {
            return ManagedFolder::on('nativephp')->find($folderId)?->binary_hash;
        }
        return Setting::get('global_binary_hash');
    }

    protected function updateStoredHash(?int $folderId, string $hash): void
    {
        if ($folderId) {
            ManagedFolder::on('nativephp')->where('id', $folderId)->update(['binary_hash' => $hash]);
        } else {
            Setting::set('global_binary_hash', $hash);
        }
    }

    /**
     * Rebuild Vektor from Knowledge base for a specific partition.
     * Uses shadow directory by default for zero-downtime.
     */
    public function rebuildIndex(?int $dimensions = null, ?int $folderId = null, ?ManagedFolder $folder = null): bool
    {
        // 1. Prepare Shadow
        $this->prepareShadow($folderId);

        // 2. Index into Shadow
        $success = $this->withScope($folderId, function () use ($dimensions, $folderId, $folder) {
            $dimensions = $dimensions ?? (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
            Config::setDimensions($dimensions);
            
            // clearBinaryFiles is already called by prepareShadow, but withScope might have set path to primary.
            // withScope re-entrant logic will keep us in shadow if we call it from withScope(..., true)
            
            $indexer = new Indexer();
            $query = Knowledge::on('nativephp');

            if ($folderId) {
                $query->where('metadata->folder_id', $folderId);
            }

            $total = $query->count();
            $processed = 0;

            $query->chunk(200, function ($items) use ($indexer, $dimensions, $folder, $total, &$processed) {
                foreach ($items as $item) {
                    $embedding = $item->embedding;
                    if (is_string($embedding)) $embedding = json_decode($embedding, true);
                    if (!is_array($embedding) || count($embedding) !== $dimensions) continue;

                    try {
                        $indexer->insert($item->id, $embedding);
                        $processed++;

                        if ($folder && $processed % 50 === 0) {
                            $progress = 90 + (int) (($processed / $total) * 10);
                            $folder->update(['indexing_progress' => min(99, $progress)]);
                        }
                    } catch (\Throwable $e) {
                        Log::error("Failed to index knowledge item {$item->id}: " . $e->getMessage());
                    }
                }
            }, true); // shadow = true

            // Update integrity hash
            $newHash = $this->computeStateHash($folderId, $dimensions);
            $this->updateStoredHash($folderId, $newHash);

            Log::info("Arkhein Vektor: Rebuilt partition [{$folderId}] (SHADOW) with {$processed} items. Hash: {$newHash}");
            return true;
        }, true); // shadow = true

        // 3. Swap Shadow
        if ($success) {
            $this->swapShadow($folderId);
        }

        return $success;
    }

    public function rebuildGlobalIndex(?int $dimensions = null): bool
    {
        return $this->rebuildIndex($dimensions, null);
    }

    /**
     * Save knowledge into a partition.
     */
    public function save(string $id, string $content, array $embedding, string $type = 'chat', array $metadata = [], bool $skipIndex = false, ?string $documentId = null, ?string $mimeType = null, ?string $vectorAnchor = null)
    {
        $folderId = $metadata['folder_id'] ?? null;

        try {
            Knowledge::on('nativephp')->updateOrCreate(
                ['id' => $id],
                [
                    'document_id' => $documentId,
                    'type' => $type,
                    'mime_type' => $mimeType,
                    'content' => $content,
                    'vector_anchor' => $vectorAnchor,
                    'embedding' => $embedding,
                    'metadata' => $metadata
                ]
            );

            if ($skipIndex) return true;

            // Live insertion for small updates
            if ($folderId) {
                $this->withScope($folderId, function() use ($id, $embedding, $folderId) {
                    (new Indexer())->insert($id, $embedding);
                    $this->updateStoredHash($folderId, $this->computeStateHash($folderId, count($embedding)));
                });
            }

            $this->withScope(null, function() use ($id, $embedding) {
                (new Indexer())->insert($id, $embedding);
                $this->updateStoredHash(null, $this->computeStateHash(null, count($embedding)));
            });

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save to Knowledge Index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search Knowledge Base within a specific partition.
     */
    public function search(array $vector, int $limit = 5, ?float $threshold = null, ?int $folderId = null, ?string $type = null)
    {
        $threshold = $threshold ?? config('knowledge.recall_threshold', 0.65);

        return $this->withScope($folderId, function () use ($vector, $limit, $threshold, $folderId, $type) {
            try {
                // Pre-search integrity check
                $dimensions = count($vector);
                Config::setDimensions($dimensions);
                
                $storedHash = $this->getStoredHash($folderId);
                $path = Config::getDataDir();
                $vectorFile = $path . DIRECTORY_SEPARATOR . 'vector.bin';

                if (!File::exists($vectorFile) || $storedHash === null) {
                    Log::warning("Arkhein Vektor: Partition [{$folderId}] search requested but index missing. Forcing check...");
                    $this->ensureIndex($dimensions, $folderId);
                }

                $results = (new Searcher())->search($vector, $limit);
                return $this->parseResults($results, $threshold, $type);
            } catch (\Exception $e) {
                Log::error("Vektor search failed for partition [{$folderId}]: " . $e->getMessage());
                return [];
            }
        });
    }

    public function reset(): bool
    {
        try {
            File::cleanDirectory($this->basePath);
            File::cleanDirectory(storage_path('app/arkhein/workspaces'));
            File::cleanDirectory(storage_path('app/arkhein/workflows'));
            
            Knowledge::on('nativephp')->delete();
            \App\Models\Document::on('nativephp')->delete();
            Setting::on('nativephp')->where('key', 'global_binary_hash')->delete();
            ManagedFolder::on('nativephp')->update(['binary_hash' => null]);
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to reset knowledge base: " . $e->getMessage());
            return false;
        }
    }

    protected function clearBinaryFiles(string $path)
    {
        $files = ['vector.bin', 'graph.bin', 'meta.bin', 'vektor.lock'];
        foreach ($files as $file) {
            $f = $path . DIRECTORY_SEPARATOR . $file;
            if (File::exists($f)) File::delete($f);
        }
    }

    protected function parseResults($results, float $threshold = 0, ?string $typeFilter = null)
    {
        $parsed = [];
        foreach ($results as $result) {
            $id = $result['id'];
            $similarity = 1.0 - ($result['score'] ?? 1.0);
            if ($similarity < $threshold) continue;

            $query = Knowledge::on('nativephp')->with('document');
            if ($typeFilter) {
                $query->where('type', $typeFilter);
            }
            
            $item = $query->find($id);
            if ($item) {
                $parsed[] = [
                    'id' => $id,
                    'type' => $item->type,
                    'content' => $item->content,
                    'score' => $similarity,
                    'metadata' => $item->metadata,
                    'vector_anchor' => $item->vector_anchor, // Expose anchor for precision RAG
                    'vessel' => $item->document ? [
                        'path' => $item->document->path,
                        'filename' => $item->document->filename,
                        'summary' => $item->document->summary,
                        'subfolder' => $item->document->metadata['subfolder'] ?? '',
                        'depth' => $item->document->metadata['depth'] ?? 0,
                        'perception' => $item->document->metadata['perception'] ?? [],
                    ] : null
                ];
            }
        }
        return $parsed;
    }

    /**
     * Purge document knowledge for a managed folder.
     */
    public function purgeFolderKnowledge(int $folderId): bool
    {
        // 1. Purge physical workspace and workflows
        $this->purgeWorkspace($folderId);
        $this->purgeWorkflows($folderId);

        // 2. Purge knowledge base
        return $this->withScope($folderId, function() use ($folderId) {
            try {
                Knowledge::on('nativephp')
                    ->where('metadata->folder_id', $folderId)
                    ->delete();

                $path = Config::getDataDir();
                if (File::isDirectory($path)) {
                    File::deleteDirectory($path);
                }

                return true;
            } catch (\Throwable $e) {
                Log::error("Failed to purge folder knowledge: " . $e->getMessage(), ['folder_id' => $folderId]);
                return false;
            }
        });
    }

    /**
     * Purge physical workspace for a silo.
     */
    public function purgeWorkspace(int $folderId): void
    {
        $workspaceDir = storage_path('app/arkhein/workspaces/' . $folderId);
        if (File::isDirectory($workspaceDir)) {
            Log::info("Arkhein: Purging physical workspace for silo [{$folderId}]");
            File::deleteDirectory($workspaceDir);
        }
    }

    /**
     * Purge physical workflows for a silo.
     */
    public function purgeWorkflows(int $folderId): void
    {
        $workflowDir = storage_path('app/arkhein/workflows/' . $folderId);
        if (File::isDirectory($workflowDir)) {
            Log::info("Arkhein: Purging physical workflows for silo [{$folderId}]");
            File::deleteDirectory($workflowDir);
        }
    }
}
