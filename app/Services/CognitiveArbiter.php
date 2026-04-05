<?php

namespace App\Services;

use App\Models\SystemTask;
use App\Models\ManagedFolder;
use Illuminate\Support\Facades\Log;
use Illuminate\Pipeline\Pipeline;
use App\Services\Cognitive\CognitivePayload;
use App\Services\Cognitive\Steps\PerceptionStep;
use App\Services\Cognitive\Steps\ContextRetrievalStep;
use App\Services\Cognitive\Steps\DecompositionStep;
use App\Services\Cognitive\Steps\ReasoningStep;
use App\Services\Cognitive\Steps\SelfCritiqueStep;
use App\Services\Cognitive\Steps\SynthesisStep;

class CognitiveArbiter
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag,
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
        
        // Build Silo Manifest (Only if folder exists)
        $manifest = "";
        if ($folderId) {
            $allDocs = \App\Models\Document::where('folder_id', $folderId)->get(['path', 'summary']);
            $manifest = "### SILO MANIFEST (GROUND TRUTH):\n";

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
                    ContextRetrievalStep::class
                ])
                ->thenReturn();

            // 1.5 Actionable Inventory (The Sovereign Coordinator)
            if (strtolower($payload->perception['intent'] ?? '') === 'inventory') {
                if ($task) $task->update(['description' => 'Querying silo inventory...']);
                $tool = new \App\Services\Tools\InventoryTool();
                $result = $tool->execute(['pattern' => $payload->perception['entities'][0] ?? null], $folder);
                
                if ($result['success']) {
                    $payload->verified = implode("\n", $result['data']);
                    // Skip to Synthesis
                    $payload = app(Pipeline::class)
                        ->send($payload)
                        ->through([SynthesisStep::class])
                        ->thenReturn();
                    
                    return $this->finalizeTask($payload);
                }
            }

            // Dynamically assemble the rest of the pipeline based on complexity
            $intent = strtolower($payload->perception['intent'] ?? '');
            $complexity = strtolower($payload->perception['complexity'] ?? 'low');
            $hasHighIntensityKeywords = preg_match('/\b(all|every|list|count|total|inventory|summarize everything)\b/i', $query);
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
                // Fast-track: Simple queries bypass decomposition and critique
                $payload->scratchpad = $payload->context; // Pass context directly as scratchpad input for synthesis
                $remainingSteps = [
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