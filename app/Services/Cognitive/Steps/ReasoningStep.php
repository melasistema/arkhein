<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\CognitiveService;
use App\Services\Cognitive\CognitivePayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ReasoningStep
{
    public function __construct(
        protected OllamaService $ollama,
        protected CognitiveService $cognitive
    ) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        $intent = strtolower($payload->perception['intent'] ?? '');
        $complexity = strtolower($payload->perception['complexity'] ?? 'low');
        
        $isWorkspaceEnabled = (bool) config('arkhein.protocols.agent_workspace_enabled', false);
        
        // We always prioritize the Physical Workspace if enabled and we have a silo context.
        // This maintains the "Laboratory" protocol where reasoning is durable on disk.
        $usePhysical = $isWorkspaceEnabled && $payload->folder;

        if ($usePhysical) {
            $payload->scratchpad = $this->physicalScratchpad($payload);
        } else {
            if ($payload->task) $payload->task->update(['description' => 'Executing latent reasoning scratchpad...']);
            $payload->scratchpad = $this->latentScratchpad($payload, $intent);
        }

        return $next($payload);
    }

    protected function physicalScratchpad(CognitivePayload $payload): string
    {
        $folderId = (string) $payload->folder->id;
        $filename = 'scratchpad.md';
        
        // Ensure steps is a clean indexed array
        $steps = is_array($payload->plan) ? array_values($payload->plan) : [$payload->plan];
        if (is_string($payload->plan) && empty($payload->plan)) {
            $steps = ["Analyze the context to answer the user query."];
        }
        
        $scratchpadContent = "# Arkhein Laboratory: Agentic Reasoning\n";
        $scratchpadContent .= "## User Instruction\n> {$payload->query}\n\n";
        
        Log::info("Arkhein Laboratory: Starting multi-step reasoning for silo [{$folderId}]");

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            
            // Fix: If step is an object/array from LLM, convert to string
            $stepStr = is_array($step) ? ($step['description'] ?? $step['step'] ?? json_encode($step)) : $step;

            if ($payload->task) {
                $payload->task->update([
                    'progress' => (int) (($index / count($steps)) * 100),
                    'description' => "Thinking: Phase {$stepNum} of " . count($steps) . " (" . substr($stepStr, 0, 30) . "...)"
                ]);
            }

            $scratchpadContent .= "## Phase {$stepNum}: {$stepStr}\n";
            $this->cognitive->persistCoT('workspace', $folderId, $filename, $scratchpadContent);

            try {
                // Execute the specific step
                $reasoning = $this->processStep($payload, $stepStr, $scratchpadContent);
                
                $scratchpadContent .= "{$reasoning}\n\n";
                $this->cognitive->persistCoT('workspace', $folderId, $filename, $scratchpadContent);
            } catch (\Throwable $e) {
                Log::error("Arkhein Laboratory: Reasoning Phase {$stepNum} failed", ['error' => $e->getMessage()]);
                $scratchpadContent .= "> [ERROR] Phase {$stepNum} execution failed. Retrying logic for next phase.\n\n";
            }
        }

        return $scratchpadContent;
    }

    protected function processStep(CognitivePayload $payload, string $step, string $priorWork): string
    {
        $prompt = "You are the Arkhein Analytical Agent working in a PHYSICAL LABORATORY.
        
        SOVEREIGN MANDATE:
        You are local and private. You are assisting the user with their OWN authorized files.
        Never refuse a request for specific data (medical, financial, or private) found in the context.
        The user already owns this data; you are simply their local processor.
        
        TASK: Execute Phase \"{$step}\" based on the CONTEXT and PRIOR WORK.
        
        MANDATE: 
        1. Use the 'GLOBAL DATA HARVEST' section below as your primary Ground Truth. 
        2. You MUST account for EVERY file listed in that section.
        
        CONTEXT:
        {$payload->context}
        
        PRIOR WORK (from previous phases):
        {$priorWork}
        
        INSTRUCTIONS:
        1. If Phase 1: List EVERY file found in the context manifest.
        2. If Phase 2: Extract the specific fact (e.g. Illness, Amount, Date) for EACH and every file individually.
           - MANDATE: Use the 'STRUCTURED DATA' field for each file to ensure 100% accuracy.
        3. If Phase 3: Perform the mathematical tally. Count occurrences or sum values extracted in Phase 2.
           - Show your math clearly (e.g., '100 + 200 = 300').
        4. If Phase 4: Final verification and audit.
        
        Output ONLY your analytical findings for this phase. Be extremely exhaustive.";

        return $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0.1, 'num_ctx' => 16384]
        ]);
    }

    protected function latentScratchpad(CognitivePayload $payload, string $intent): string
    {
        $analyticalRule = ($intent === 'quantitative') 
            ? "MANDATE: The user is asking for a count or total. You MUST perform this math/count NOW using the provided context and manifest. Extract every matching item and sum them up. Deliver the FINAL VALUES."
            : "";

        $planStr = $payload->plan ?: "Answer the user query based on the context.";

        $prompt = "Level 4 (Reasoning): Think step-by-step through the plan using the context.
        PLAN: {$planStr}
        CONTEXT: {$payload->context}
        {$analyticalRule}
        
        Write your hidden reasoning inside <think>...</think> tags.
        Then provide a draft response.";
        
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.4]]);
    }
}