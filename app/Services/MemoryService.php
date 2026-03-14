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
     * Rebuilds from Knowledge table (SSOT) if missing.
     */
    public function ensureIndex(int $dimensions = 768)
    {
        Config::setDimensions($dimensions);

        // Vektor uses internal paths based on DataDir. 
        // We ensure we check the absolute path.
        $vectorFile = $this->storagePath . DIRECTORY_SEPARATOR . 'vector.bin';
        
        if (!File::exists($vectorFile)) {
            Log::info("Arkhein: Binary index missing at {$vectorFile}. Triggering auto-rebuild.");
            return $this->rebuildIndex($dimensions);
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
        
        $indexer = new Indexer();
        
        Knowledge::on('nativephp')->chunk(100, function ($items) use ($indexer) {
            foreach ($items as $item) {
                try {
                    $indexer->insert($item->id, $item->embedding);
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
        Log::debug("Arkhein: Attempting to save knowledge item", [
            'id' => $id,
            'type' => $type,
            'dimensions' => count($embedding),
            'storage_path' => $this->storagePath
        ]);

        try {
            $this->ensureIndex(count($embedding));
            
            // 1. Save to SQLite Knowledge Base (SSOT)
            $dbResult = Knowledge::on('nativephp')->updateOrCreate(
                ['id' => $id],
                [
                    'type' => $type,
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => $metadata
                ]
            );
            Log::debug("Arkhein: SQLite save complete", ['success' => (bool)$dbResult]);

            // 2. Save to Vektor Index
            $indexer = new Indexer();
            $indexer->insert($id, $embedding);
            Log::debug("Arkhein: Vektor Index insertion complete.");
            
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
        $threshold = $threshold ?? config('knowledge.recall_threshold', 0.75);

        try {
            $this->ensureIndex(count($vector));
            $searcher = new Searcher();
            
            $results = $searcher->search($vector, $limit);

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
            Knowledge::on('nativephp')->truncate();
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reset knowledge base: " . $e->getMessage());
            return false;
        }
    }

    protected function clearBinaryFiles()
    {
        $files = [
            Config::getVectorFile(),
            Config::getGraphFile(),
            Config::getMetaFile(),
            Config::getLockFile(),
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
            
            $distance = $result['score'] ?? 1.0;
            $similarity = 1.0 - $distance;

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
