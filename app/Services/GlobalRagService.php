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

    /**
     * Smart Recall: Automatically performs Discovery + Selective Partition Recall.
     * This is the "Sovereign Tree" implementation for Global RAG.
     */
    public function autoRecall(string $query, int $fragmentLimit = 15): array
    {
        Log::info("Arkhein Global RAG: Starting Hierarchical Auto-Recall");

        // 1. DISCOVERY PASS (Level 3 Canopy)
        $silos = $this->discover($query, 3);
        
        if (empty($silos)) {
            Log::info("Arkhein Global RAG: No specific silos discovered. Falling back to global fragment search.");
            return $this->recall($query, $fragmentLimit);
        }

        $allFragments = [];
        $siloCount = count($silos);
        
        // 2. SELECTIVE RECALL (Level 0 Fragments from identified silos)
        foreach ($silos as $silo) {
            $folderId = $silo['metadata']['folder_id'] ?? null;
            if (!$folderId) continue;

            Log::info("Arkhein Global RAG: Deep diving into Silo [ID: {$folderId}]");
            
            // Allocate fragment budget per silo
            $perSiloLimit = max(5, (int) ($fragmentLimit / $siloCount));
            $fragments = $this->memory->search($this->ollama->embeddings($query), $perSiloLimit, null, $folderId);
            
            foreach ($fragments as $f) {
                // Annotate fragment with its canopy context for the synthesizer
                $f['canopy_summary'] = $silo['content'];
                $allFragments[] = $f;
            }
        }

        return $allFragments;
    }
}
