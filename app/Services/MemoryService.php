<?php

namespace App\Services;

use Centamiv\Vektor\Core\Config;
use Centamiv\Vektor\Services\Indexer;
use Centamiv\Vektor\Services\Searcher;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    protected string $storagePath;
    protected string $metadataPath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/vektor');
        $this->metadataPath = storage_path('app/vektor/metadata.json');
        
        if (!file_exists($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        // Set the storage path for Vektor
        Config::setDataDir($this->storagePath);
    }

    /**
     * Compatibility method for our previous implementation.
     */
    public function ensureIndex(int $dimensions = 768)
    {
        Config::setDimensions($dimensions);
        return true;
    }

    /**
     * Save a memory (document + embedding).
     */
    public function save(string $id, string $content, array $embedding, array $metadata = [])
    {
        try {
            Config::setDimensions(count($embedding));
            $indexer = new Indexer();
            
            // Insert into Vektor
            $indexer->insert($id, $embedding);
            
            // Save metadata locally in a JSON file
            $this->saveMetadata($id, [
                'content' => $content,
                'metadata' => $metadata,
                'timestamp' => time()
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save memory to Vektor: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for similar memories.
     */
    public function search(array $vector, int $limit = 5)
    {
        try {
            Config::setDimensions(count($vector));
            $searcher = new Searcher();
            
            $results = $searcher->search($vector, $limit);

            return $this->parseResults($results);
        } catch (\Exception $e) {
            Log::error("Vektor search failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear all memory (used when changing dimensions/models).
     */
    public function reset()
    {
        try {
            $files = [
                Config::getVectorFile(),
                Config::getGraphFile(),
                Config::getMetaFile(),
                Config::getLockFile(),
                $this->metadataPath
            ];

            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reset Vektor memory: " . $e->getMessage());
            return false;
        }
    }

    protected function saveMetadata(string $id, array $data)
    {
        $allMetadata = [];
        if (file_exists($this->metadataPath)) {
            $allMetadata = json_decode(file_get_contents($this->metadataPath), true) ?: [];
        }
        
        $allMetadata[$id] = $data;
        file_put_contents($this->metadataPath, json_encode($allMetadata));
    }

    protected function getMetadata(string $id)
    {
        if (!file_exists($this->metadataPath)) {
            return null;
        }
        
        $allMetadata = json_decode(file_get_contents($this->metadataPath), true) ?: [];
        return $allMetadata[$id] ?? null;
    }

    protected function parseResults($results)
    {
        $parsed = [];

        foreach ($results as $result) {
            $id = $result['id'];
            $meta = $this->getMetadata($id);
            
            $parsed[] = [
                'id' => $id,
                'content' => $meta['content'] ?? '',
                'score' => $result['score'] ?? 0,
                'metadata' => $meta['metadata'] ?? []
            ];
        }

        return $parsed;
    }
}
