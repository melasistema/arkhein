<?php

namespace App\Services;

use App\Models\Knowledge;
use Centamiv\Vektor\Core\Config;
use Centamiv\Vektor\Services\Indexer;
use Centamiv\Vektor\Services\Searcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MemoryService
{
    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/vektor');
        
        if (!File::isDirectory($this->storagePath)) {
            File::makeDirectory($this->storagePath, 0755, true);
        }

        Config::setDataDir($this->storagePath);
    }

    /**
     * Ensure the index is ready and matched to dimensions.
     */
    public function ensureIndex(?int $dimensions = null)
    {
        $dimensions = $dimensions ?? (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
        Config::setDimensions($dimensions);

        $vectorFile = $this->storagePath . DIRECTORY_SEPARATOR . 'vector.bin';
        
        if (!File::exists($vectorFile) || filesize($vectorFile) === 0) {
            // Check if we have data in SQLite to rebuild from
            if (Knowledge::on('nativephp')->count() > 0) {
                Log::info("Arkhein: Binary index missing or empty but SQLite has data. Rebuilding...");
                return $this->rebuildIndex($dimensions);
            }
        }

        if ($this->isMismatched($vectorFile, $dimensions)) {
            Log::warning("Arkhein: Binary index dimensions mismatch. Triggering auto-rebuild.");
            return $this->rebuildIndex($dimensions);
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
     * Rebuild Vektor from Knowledge base.
     */
    public function rebuildIndex(int $dimensions): bool
    {
        Log::info("Arkhein: Rebuilding Vektor index from Knowledge Base SSOT. Dimensions: $dimensions");
        
        $this->clearBinaryFiles();
        Config::setDimensions($dimensions);
        Config::setDataDir($this->storagePath);
        
        $indexer = new Indexer();
        
        Knowledge::on('nativephp')->chunk(100, function ($items) use ($indexer, $dimensions) {
            foreach ($items as $item) {
                try {
                    $embedding = $item->embedding;
                    if (is_string($embedding)) {
                        $embedding = json_decode($embedding, true);
                    }

                    if (!is_array($embedding)) continue;

                    $itemDimensions = count($embedding);
                    if ($itemDimensions !== $dimensions) continue;

                    $indexer->insert($item->id, $embedding);
                } catch (\Exception $e) {
                    Log::error("Failed to index knowledge item {$item->id}: " . $e->getMessage());
                }
            }
        });

        return true;
    }

    /**
     * Save any piece of knowledge.
     */
    public function save(string $id, string $content, array $embedding, string $type = 'chat', array $metadata = [])
    {
        try {
            $this->ensureIndex(count($embedding));
            
            $dbResult = Knowledge::on('nativephp')->updateOrCreate(
                ['id' => $id],
                [
                    'type' => $type,
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => $metadata
                ]
            );

            $indexer = new Indexer();
            $indexer->insert($id, $embedding);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save to Knowledge Index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search Knowledge Base using Vektor.
     */
    public function search(array $vector, int $limit = 5, ?float $threshold = null)
    {
        $threshold = $threshold ?? config('knowledge.recall_threshold', 0.65);

        try {
            $this->ensureIndex(count($vector));
            $searcher = new Searcher();
            
            $results = $searcher->search($vector, $limit);

            // If we have data in DB but 0 results from Vektor, something is wrong with the index
            if (empty($results) && Knowledge::on('nativephp')->count() > 0) {
                Log::warning("Arkhein: Search returned 0 hits but DB is not empty. Possible index corruption. Rebuilding...");
                $this->rebuildIndex(count($vector));
                // Retry search once
                $results = $searcher->search($vector, $limit);
            }

            return $this->parseResults($results, $threshold);
        } catch (\Exception $e) {
            Log::error("Vektor search failed: " . $e->getMessage());
            return [];
        }
    }

    public function reset()
    {
        try {
            $this->clearBinaryFiles();
            Knowledge::on('nativephp')->delete(); // Using delete() instead of truncate() for SQLite safety
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reset knowledge base: " . $e->getMessage());
            return false;
        }
    }

    protected function clearBinaryFiles()
    {
        $files = [
            $this->storagePath . '/vector.bin',
            $this->storagePath . '/graph.bin',
            $this->storagePath . '/meta.bin',
            $this->storagePath . '/vektor.lock',
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

        Log::debug("Arkhein Vektor: Parsing " . count($results) . " raw hits.");

        foreach ($results as $result) {
            $id = $result['id'];

            // Vektor returns distance (usually 0 to 2 for cosine)
            $distance = $result['score'] ?? 1.0;
            $similarity = 1.0 - $distance;

            Log::debug("Arkhein Vektor: Item {$id} -> Distance: {$distance}, Similarity: {$similarity}, Threshold: {$threshold}");

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
            } else {
                Log::warning("Arkhein Vektor: Found ID {$id} in index but missing from SQLite SSOT.");
            }
        }

        return $parsed;
    }

}
