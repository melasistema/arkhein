<?php

namespace App\Services;

use App\Models\Knowledge;
use App\Models\Setting;
use Centamiv\Vektor\Core\Config;
use Centamiv\Vektor\Services\Indexer;
use Centamiv\Vektor\Services\Searcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MemoryService
{
    protected string $basePath;
    protected ?int $currentFolderId = null;

    public function __construct()
    {
        $this->basePath = storage_path('app/vektor');
        $this->setPartition(null);
    }

    /**
     * Switch the active Vektor partition.
     * Vektor uses static Config, so we must set the directory before any Vektor operation.
     */
    public function setPartition(?int $folderId): self
    {
        $this->currentFolderId = $folderId;
        $path = $this->getPartitionPath($folderId);

        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        Config::setDataDir($path);
        return $this;
    }

    protected function getPartitionPath(?int $folderId): string
    {
        return $folderId 
            ? $this->basePath . DIRECTORY_SEPARATOR . 'folder_' . $folderId
            : $this->basePath . DIRECTORY_SEPARATOR . 'global';
    }

    /**
     * Ensure the index is ready and matched to dimensions.
     */
    public function ensureIndex(?int $dimensions = null, ?int $folderId = null)
    {
        $folderId = $folderId ?? $this->currentFolderId;
        $this->setPartition($folderId);

        $dimensions = $dimensions ?? (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
        Config::setDimensions($dimensions);

        $path = $this->getPartitionPath($folderId);
        $vectorFile = $path . DIRECTORY_SEPARATOR . 'vector.bin';
        
        if (!File::exists($vectorFile) || filesize($vectorFile) === 0) {
            $query = Knowledge::on('nativephp');
            if ($folderId) {
                $query->where('metadata->folder_id', $folderId);
            }
            // For global (null), we don't apply a where clause, indexing EVERYTHING.

            if ($query->count() > 0) {
                Log::info("Arkhein: Binary index missing for partition [{$folderId}]. Rebuilding...");
                return $this->rebuildIndex($dimensions, $folderId);
            }
        }

        if ($this->isMismatched($vectorFile, $dimensions)) {
            Log::warning("Arkhein: Dimension mismatch for partition [{$folderId}]. Rebuilding...");
            return $this->rebuildIndex($dimensions, $folderId);
        }

        return true;
    }

    protected function isMismatched(string $path, int $dimensions): bool
    {
        if (!File::exists($path)) return false;
        
        clearstatcache(true, $path);
        $size = filesize($path);
        
        if ($size === 0) return false;

        $expectedRowSize = Config::getVectorRowSize();
        return ($size % $expectedRowSize) !== 0;
    }

    /**
     * Rebuild the aggregate global index from all authorized knowledge.
     */
    public function rebuildGlobalIndex(int $dimensions): bool
    {
        return $this->rebuildIndex($dimensions, null);
    }

    /**
     * Rebuild Vektor from Knowledge base for a specific partition.
     */
    public function rebuildIndex(int $dimensions, ?int $folderId = null): bool
    {
        $folderId = $folderId ?? $this->currentFolderId;
        $this->setPartition($folderId);

        return $this->withRebuildLock($folderId, function () use ($dimensions, $folderId) {
            Log::info("Arkhein: Rebuilding Vektor index for partition [{$folderId}]. Dimensions: $dimensions");

            $this->clearBinaryFiles($folderId);
            Config::setDimensions($dimensions);
            
            $indexer = new Indexer();
            $query = Knowledge::on('nativephp');

            if ($folderId) {
                $query->where('metadata->folder_id', $folderId);
            }
            // For global (null), index everything.

            $query->chunk(100, function ($items) use ($indexer, $dimensions) {
                foreach ($items as $item) {
                    try {
                        $embedding = $item->embedding;
                        if (is_string($embedding)) {
                            $embedding = json_decode($embedding, true);
                        }

                        if (!is_array($embedding) || count($embedding) !== $dimensions) continue;

                        $indexer->insert($item->id, $embedding);
                    } catch (\Throwable $e) {
                        Log::error("Failed to index knowledge item {$item->id}: " . $e->getMessage());
                    }
                }
            });

            return true;
        });
    }

    protected function withRebuildLock(?int $folderId, callable $callback): bool
    {
        $path = $this->getPartitionPath($folderId);
        $lockPath = $path . DIRECTORY_SEPARATOR . 'rebuild.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            Log::error("Arkhein: Failed to create rebuild lock for partition [{$folderId}].");
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                Log::error("Arkhein: Failed to acquire rebuild lock for partition [{$folderId}].");
                return false;
            }

            return (bool) $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Save any piece of knowledge into a partition.
     */
    public function save(string $id, string $content, array $embedding, string $type = 'chat', array $metadata = [])
    {
        $folderId = isset($metadata['folder_id']) ? (int) $metadata['folder_id'] : null;
        
        try {
            // 1. Persistence in SSOT (SQLite)
            Knowledge::on('nativephp')->updateOrCreate(
                ['id' => $id],
                [
                    'type' => $type,
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => $metadata
                ]
            );

            // 2. Index in Folder Partition (if applicable)
            if ($folderId) {
                $this->ensureIndex(count($embedding), $folderId);
                $indexer = new Indexer();
                $indexer->insert($id, $embedding);
            }

            // 3. Index in Global Partition (Aggregate)
            $this->ensureIndex(count($embedding), null);
            $indexer = new Indexer();
            $indexer->insert($id, $embedding);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save to Knowledge Index [{$folderId}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search Knowledge Base within a specific partition.
     */
    public function search(array $vector, int $limit = 5, ?float $threshold = null, ?int $folderId = null)
    {
        $threshold = $threshold ?? config('knowledge.recall_threshold', 0.65);
        $folderId = $folderId ?? $this->currentFolderId;

        try {
            $this->ensureIndex(count($vector), $folderId);
            $searcher = new Searcher();
            
            $results = $searcher->search($vector, $limit);

            // Self-healing: if DB has data for this partition but Vektor returns 0
            if (empty($results)) {
                $query = Knowledge::on('nativephp');
                if ($folderId) {
                    $query->where('metadata->folder_id', $folderId);
                }
                // For global (null), check total count.

                if ($query->count() > 0) {
                    Log::warning("Arkhein: Search returned 0 hits for partition [{$folderId}] but DB is not empty. Rebuilding...");
                    $this->rebuildIndex(count($vector), $folderId);
                    $results = $searcher->search($vector, $limit);
                }
            }

            return $this->parseResults($results, $threshold);
        } catch (\Exception $e) {
            Log::error("Vektor search failed for partition [{$folderId}]: " . $e->getMessage());
            return [];
        }
    }

    public function reset(): bool
    {
        try {
            File::cleanDirectory($this->basePath);
            Knowledge::on('nativephp')->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to reset knowledge base: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge document knowledge for a managed folder.
     */
    public function purgeFolderKnowledge(int $folderId): bool
    {
        try {
            Knowledge::on('nativephp')
                ->where('type', 'file')
                ->where('metadata->folder_id', $folderId)
                ->delete();

            $path = $this->getPartitionPath($folderId);
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to purge folder knowledge: " . $e->getMessage(), ['folder_id' => $folderId]);
            return false;
        }
    }

    protected function clearBinaryFiles(?int $folderId)
    {
        $path = $this->getPartitionPath($folderId);
        $files = [
            $path . '/vector.bin',
            $path . '/graph.bin',
            $path . '/meta.bin',
            $path . '/vektor.lock',
        ];

        foreach ($files as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }
    }

    protected function parseResults($results, float $threshold = 0)
    {
        $parsed = [];

        foreach ($results as $result) {
            $id = $result['id'];
            $similarity = 1.0 - ($result['score'] ?? 1.0);

            if ($similarity < $threshold) continue;

            $item = Knowledge::on('nativephp')->find($id);

            if ($item) {
                $parsed[] = [
                    'id' => $id,
                    'type' => $item->type,
                    'content' => $item->content,
                    'score' => $similarity,
                    'metadata' => $item->metadata
                ];
            }
        }

        return $parsed;
    }
}
