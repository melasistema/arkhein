<?php

namespace App\Services;

use App\Models\ManagedFolder;
use Illuminate\Support\Facades\Log;

class GlobalRagService
{
    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {}

    /**
     * Search across all authorized partitions.
     */
    public function recall(string $query, int $limit = 10): array
    {
        $embedding = $this->ollama->embeddings($query);

        if (!$embedding) {
            Log::error("Arkhein Global RAG: Failed to generate embedding.");
            return [];
        }

        // We use the 'null' partition for global search
        $results = $this->memory->search($embedding, $limit, null, null);

        Log::info("Arkhein Global RAG: Search Results", [
            'count' => count($results),
            'partition' => 'global'
        ]);

        return $results;
    }
}
