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
    public function recall(string $query, int $limit = 5, ?int $folderId = null, ?string $type = null): array
    {
        $embedding = $this->ollama->embeddings($query);

        if (!$embedding) {
            Log::error("Arkhein RAG: Failed to generate embedding for query.");
            return [];
        }

        return $this->recallByVector($embedding, $limit, $folderId, $type);
    }

    /**
     * Search by vector across specific hierarchies.
     */
    public function recallByVector(array $vector, int $limit = 5, ?int $folderId = null, ?string $type = null): array
    {
        $results = $this->memory->search($vector, $limit, null, $folderId);

        if ($type) {
            $results = array_values(array_filter($results, fn($r) => $r['type'] === $type));
        }

        Log::info("Arkhein RAG: Search Results", [
            'count' => count($results),
            'top_score' => count($results) > 0 ? $results[0]['score'] : null,
            'partition' => $folderId ?? 'global',
            'type_filter' => $type
        ]);

        return $results;
    }

    /**
     * Discovery Pass: Identifies relevant silos (Level 3) before fragment retrieval.
     */
    public function discover(string $query, int $limit = 3): array
    {
        $embedding = $this->ollama->embeddings($query);
        if (!$embedding) return [];

        // Search global partition for silo summaries
        $silos = $this->recallByVector($embedding, $limit, null, 'silo_summary');

        return collect($silos)->map(function($s) {
            return [
                'folder_id' => $s['metadata']['folder_id'] ?? null,
                'summary' => $s['content'],
                'score' => $s['score']
            ];
        })->toArray();
    }
}
