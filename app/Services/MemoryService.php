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

        $vectorFile = Config::getVectorFile();
        
        if (!File::exists($vectorFile) || $this->isMismatched($vectorFile, $dimensions)) {
            return $this->rebuildIndex($dimensions);
        }

        return true;
    }

    protected function isMismatched(string $path, int $dimensions): bool
    {
        if (filesize($path) === 0) return false;
        clearstatcache(true, $path);
        return (filesize($path) % Config::getVectorRowSize()) !== 0;
    }

    /**
     * Rebuild Vektor from Knowledge base.
     */
    public function rebuildIndex(int $dimensions): bool
    {
        Log::info("Arkhein: Rebuilding Vektor index from Knowledge Base SSOT.");
        
        $this->clearBinaryFiles();
        Config::setDimensions($dimensions);
        
        $indexer = new Indexer();
        
        Knowledge::chunk(100, function ($items) use ($indexer) {
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
        try {
            $this->ensureIndex(count($embedding));
            
            // 1. Save to SQLite Knowledge Base (SSOT)
            Knowledge::updateOrCreate(
                ['id' => $id],
                [
                    'type' => $type,
                    'content' => $content,
                    'embedding' => $embedding,
                    'metadata' => $metadata
                ]
            );

            // 2. Save to Vektor Index
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
    public function search(array $vector, int $limit = 5)
    {
        try {
            $this->ensureIndex(count($vector));
            $searcher = new Searcher();
            
            $results = $searcher->search($vector, $limit);

            return $this->parseResults($results);
        } catch (\Exception $e) {
            Log::error("Vektor search failed: " . $e->getMessage());
            return [];
        }
    }

    public function reset()
    {
        $this->clearBinaryFiles();
        Knowledge::truncate();
        return true;
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

    protected function parseResults($results)
    {
        $parsed = [];

        foreach ($results as $result) {
            $id = $result['id'];
            $item = Knowledge::find($id);
            
            if ($item) {
                $parsed[] = [
                    'id' => $id,
                    'type' => $item->type,
                    'content' => $item->content,
                    'score' => $result['score'] ?? 0,
                    'metadata' => $item->metadata
                ];
            }
        }

        return $parsed;
    }
}
