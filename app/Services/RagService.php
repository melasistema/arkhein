<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Knowledge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RagService
{
    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory,
        protected PromptService $prompts
    ) {}

    /**
     * Search the Knowledge Base with a similarity threshold.
     */
    public function recall(string $query, int $limit = 5, ?int $folderId = null): array
    {
        $embedding = $this->ollama->embeddings($query);

        if (!$embedding) {
            Log::error("Arkhein RAG: Failed to generate embedding for query.");
            return [];
        }

        // If a folderId is provided, we search with a MUCH higher limit and filter post-retrieval
        // since the vector index is flat and global.
        $searchLimit = $folderId ? $limit * 100 : $limit;
        $results = $this->memory->search($embedding, $searchLimit);

        Log::info("Arkhein RAG: Raw Memory Search Results", [
            'raw_count' => count($results),
            'top_score' => count($results) > 0 ? $results[0]['score'] : null,
            'threshold_used' => config('knowledge.recall_threshold', 0.65)
        ]);

        if ($folderId) {
            $filtered = collect($results)
                ->filter(function ($item) use ($folderId) {
                    $metadata = $item['metadata'] ?? [];
                    $match = isset($metadata['folder_id']) && (int) $metadata['folder_id'] === $folderId;
                    return $match;
                })
                ->take($limit)
                ->values()
                ->toArray();

            Log::info("Arkhein RAG: Filtered Results", [
                'folder_id' => $folderId,
                'filtered_count' => count($filtered)
            ]);

            return $filtered;
        }

        return $results;
    }
}
