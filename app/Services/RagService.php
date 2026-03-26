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

        $results = $this->memory->search($embedding, $limit, null, $folderId);

        Log::info("Arkhein RAG: Search Results", [
            'count' => count($results),
            'top_score' => count($results) > 0 ? $results[0]['score'] : null,
            'partition' => $folderId ?? 'global'
        ]);

        return $results;
    }
}
