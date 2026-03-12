<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    protected string $indexName = 'arkhein:memory';

    /**
     * Ensure the search index exists.
     */
    public function ensureIndex(int $dimensions = 768)
    {
        try {
            $indices = Redis::executeRaw(['FT._LIST']);
            if (is_array($indices) && in_array($this->indexName, $indices)) {
                return true;
            }
            return $this->createIndex($dimensions);
        } catch (\Exception $e) {
            Log::error("Failed to check/create Redis index: " . $e->getMessage());
            return $this->createIndex($dimensions);
        }
    }

    /**
     * Create the RediSearch index for vector search.
     */
    protected function createIndex(int $dimensions)
    {
        try {
            // Drop existing if any to be safe
            try {
                Redis::executeRaw(['FT.DROPINDEX', $this->indexName]);
            } catch (\Exception $e) {}

            $command = [
                'FT.CREATE', $this->indexName,
                'ON', 'JSON',
                'PREFIX', '1', 'memory:',
                'SCHEMA',
                '$.content', 'AS', 'content', 'TEXT',
                '$.embedding', 'AS', 'embedding', 'VECTOR', 'HNSW', '6', 
                'TYPE', 'FLOAT32', 
                'DIM', (string) $dimensions, 
                'DISTANCE_METRIC', 'COSINE'
            ];
            
            $result = Redis::executeRaw($command);
            
            if ($result !== 'OK' && $result !== true) {
                Log::error("FT.CREATE returned unexpected result: " . json_encode($result));
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to create Redis index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save a memory (document + embedding).
     */
    public function save(string $id, string $content, array $embedding, array $metadata = [])
    {
        $key = "memory:{$id}";
        
        $data = [
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'timestamp' => time(),
        ];

        try {
            Redis::executeRaw(['JSON.SET', $key, '$', json_encode($data)]);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save memory to Redis: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for similar memories.
     */
    public function search(array $vector, int $limit = 5)
    {
        // Vector search syntax for RediSearch
        // *=>[KNN $limit @embedding $vector AS score]
        
        // We need to pack the vector into binary format (FLOAT32)
        $binaryVector = '';
        foreach ($vector as $val) {
            $binaryVector .= pack('f', $val);
        }

        try {
            $results = Redis::executeRaw([
                'FT.SEARCH', $this->indexName,
                "*=>[KNN $limit @embedding \$vec AS score]",
                'PARAMS', '2', 'vec', $binaryVector,
                'DIALECT', '2',
                'RETURN', '3', 'content', 'metadata', 'score',
                'SORTBY', 'score', 'ASC'
            ]);

            return $this->parseResults($results);
        } catch (\Exception $e) {
            Log::error("Redis search failed: " . $e->getMessage());
            return [];
        }
    }

    protected function parseResults($results)
    {
        if (!is_array($results) || count($results) <= 1) {
            if (!is_array($results)) {
                Log::debug("Redis search returned non-array: " . json_encode($results));
            }
            return [];
        }

        $count = $results[0];
        $parsed = [];

        // Results are in a flat list: [count, key1, [field1, val1, ...], key2, ...]
        for ($i = 1; $i < count($results); $i += 2) {
            $key = $results[$i];
            $fields = $results[$i + 1];
            
            $item = ['id' => $key];
            for ($j = 0; $j < count($fields); $j += 2) {
                $item[$fields[$j]] = $fields[$j + 1];
            }
            $parsed[] = $item;
        }

        return $parsed;
    }
}
