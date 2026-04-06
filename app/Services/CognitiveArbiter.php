<?php

namespace App\Services;

use App\Models\SystemTask;
use App\Models\ManagedFolder;
use Illuminate\Support\Facades\Log;
use Illuminate\Pipeline\Pipeline;
use App\Services\Cognitive\CognitivePayload;
use App\Services\Cognitive\Steps\PerceptionStep;
use App\Services\Cognitive\Steps\ContextRetrievalStep;
use App\Services\Cognitive\Steps\HarvestingStep;
use App\Services\Cognitive\Steps\DecompositionStep;
use App\Services\Cognitive\Steps\ReasoningStep;
use App\Services\Cognitive\Steps\SelfCritiqueStep;
use App\Services\Cognitive\Steps\SynthesisStep;

class CognitiveArbiter
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
        protected GlobalRagService $globalRag,
        protected FileArchitectService $architect
    ) {}

    /**
     * Expose Level 1 Perception pass for external services.
     */
    public function processPerception(string $query, array $schema = []): array
    {
        $payload = new CognitivePayload($query, 0, null);
        $payload->folder = new ManagedFolder(['environmental_schema' => $schema]); // Dummy for schema
        
        $pipeline = app(Pipeline::class)
            ->send($payload)
            ->through([PerceptionStep::class])
            ->thenReturn();

        return $pipeline->perception;
    }

    public function process(string $query, ?int $folderId, ?SystemTask $task = null): string
    {
        Log::info("Arkhein Cognitive Stack: Initializing pass for query", ['query' => $query, 'folder_id' => $folderId]);

        if ($task) {
            $task->update(['status' => SystemTask::STATUS_RUNNING, 'started_at' => now()]);
        }

        $folder = $folderId ? \App\Models\ManagedFolder::find($folderId) : null;
        $schema = $folder?->environmental_schema ?? [];
        
        // Level 3 Discovery: If no folder specified, or if we want to enrich context,
        // we use the Canopy Level to find relevant silos.
        $discoveryContext = "";
        if (!$folderId) {
            $silos = $this->globalRag->discover($query, 3);
            if (!empty($silos)) {
                $discoveryContext = "### GLOBAL DISCOVERY (Level 3 Canopy):\n";
                foreach ($silos as $s) {
                    $discoveryContext .= "RELEVANT SILO: [ID: {$s['metadata']['folder_id']}]\nSUMMARY: {$s['content']}\n\n";
                }
            }
        }

        // Build Silo Manifest (Only if folder exists)
        $manifest = $discoveryContext;
        if ($folderId) {
            $allDocs = \App\Models\Document::where('folder_id', $folderId)->get(['path', 'summary']);
            $manifest .= "### SILO MANIFEST (GROUND TRUTH):\n";
            
            if ($folder->summary) {
                $manifest .= "CANOPY OVERVIEW: {$folder->summary}\n\n";
            }

            if (!empty($schema['folder_map'])) {
                $manifest .= "FOLDER HIERARCHY & FILE COUNTS:\n" . implode("\n", $schema['folder_map']) . "\n\n";
                $manifest .= "TOTAL FILES IN SILO: " . ($schema['total_files'] ?? $allDocs->count()) . "\n\n";
            }

            $manifest .= "FILE LIST (Samples):\n";
            foreach ($allDocs->take(50) as $doc) {
                $manifest .= "- {$doc->path}\n";
            }
        }

        $payload = new CognitivePayload($query, $folderId ?? 0, $task);
        $payload->folder = $folder;
        $payload->manifest = $manifest;

        try {
            // First, run Perception and Context
            $payload = app(Pipeline::class)
                ->send($payload)
                ->through([
                    PerceptionStep::class,
                    ContextRetrievalStep::class,
                    HarvestingStep::class
                ])
                ->thenReturn();

            // 1.5 Actionable Inventory (The Sovereign Coordinator)
            $intent = strtolower($payload->perception['intent'] ?? '');
            $hasHighIntensityKeywords = preg_match('/\b(all|every|list|count|total|inventory|summarize everything)\b/i', $query);

            if ($intent === 'inventory' || ($intent === 'quantitative' && $hasHighIntensityKeywords)) {
                if ($task) $task->update(['description' => 'Querying structural inventory...']);
                $tool = new \App\Services\Tools\InventoryTool();
                $result = $tool->execute(['pattern' => $payload->perception['entities'][0] ?? null], $folder);
                
                if ($result['success']) {
                    // Inject the true manifest into the scratchpad/context instead of RAG fragments
                    $payload->context = "### ACCURATE SILO MANIFEST (GROUND TRUTH):\n" . implode("\n", $result['data']);
                    $payload->verified = $payload->context;
                }
            }

            // Dynamically assemble the rest of the pipeline based on complexity
            $complexity = strtolower($payload->perception['complexity'] ?? 'low');
            $hasHighIntensityKeywords = preg_match('/\b(all|every|list|count|total|inventory|summarize everything|most|common|top|frequent|proportion|how many|entire)\b/i', $query);
            $isComplex = (in_array($intent, ['quantitative', 'structural', 'creative']) || $complexity === 'high' || $hasHighIntensityKeywords);

            $remainingSteps = [];

            if ($isComplex) {
                // Full cognitive load for complex tasks
                $remainingSteps = [
                    DecompositionStep::class,
                    ReasoningStep::class,
                    SelfCritiqueStep::class,
                    SynthesisStep::class
                ];
            } else {
                // Fast-track: Simple queries bypass decomposition and critique, 
                // but we keep ReasoningStep if we want to maintain the scratchpad awareness/logic
                $remainingSteps = [
                    ReasoningStep::class,
                    SynthesisStep::class
                ];
            }

            $payload = app(Pipeline::class)
                ->send($payload)
                ->through($remainingSteps)
                ->thenReturn();

            return $this->finalizeTask($payload);

        } catch (\Throwable $e) {
            if ($task) {
                $task->update([
                    'status' => SystemTask::STATUS_FAILED,
                    'description' => 'Reasoning Pipeline Failed'
                ]);
            }
            Log::error("CognitiveArbiter Error: " . $e->getMessage());
            throw $e;
        }
    }

    protected function finalizeTask(CognitivePayload $payload): string
    {
        if ($payload->task) {
            $payload->task->update([
                'status' => SystemTask::STATUS_COMPLETED,
                'completed_at' => now(),
                'progress' => 100,
                'description' => 'Analysis Complete'
            ]);
        }
        return $payload->finalResult ?? "Analysis Complete.";
    }
}