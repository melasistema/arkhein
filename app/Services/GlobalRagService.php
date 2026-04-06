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
    public function recall(string $query, int $limit = 20, ?string $type = null): array
    {
        $embedding = $this->ollama->embeddings($query);

        if (!$embedding) {
            Log::error("Arkhein Global RAG: Failed to generate embedding.");
            return [];
        }

        // We use the 'null' partition for global search
        $results = $this->memory->search($embedding, $limit, null, null);

        if ($type) {
            $results = array_values(array_filter($results, fn($r) => $r['type'] === $type));
        }

        Log::info("Arkhein Global RAG: Search Results", [
            'count' => count($results),
            'partition' => 'global',
            'type_filter' => $type
        ]);

        return $results;
    }

    /**
     * Hierarchy Discovery: Find relevant silos before fragments.
     */
    public function discover(string $query, int $limit = 5): array
    {
        return $this->recall($query, $limit, 'silo_summary');
    }
}
