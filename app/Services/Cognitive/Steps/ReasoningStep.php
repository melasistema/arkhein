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
        
        // ARKHEIN CRITICAL: "The Archivist" (Global RAG) MUST have a physical workspace
        // to enable multi-step Chain of Thought (CoT).
        $usePhysical = $isWorkspaceEnabled;

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
        // Use folder ID or "global" for the physical lab partition
        $labId = $payload->folder ? (string) $payload->folder->id : 'global';
        $filename = 'scratchpad.md';
        
        // Ensure steps is a clean indexed array
        $steps = is_array($payload->plan) ? array_values($payload->plan) : [$payload->plan];
        if (empty($steps) || (is_string($payload->plan) && empty($payload->plan))) {
            $steps = ["Analyze the context to answer the user query."];
        }
        
        $scratchpadContent = "# Arkhein Laboratory: Agentic Reasoning\n";
        $scratchpadContent .= "## User Instruction\n> {$payload->query}\n\n";
        
        if (!$payload->folder) {
            $scratchpadContent .= "**ORCHESTRATOR: THE ARCHIVIST (Global RAG Mode)**\n\n";
        } else {
            $scratchpadContent .= "**ORCHESTRATOR: SILO @{$payload->folder->name}**\n\n";
        }
        
        Log::info("Arkhein Laboratory: Starting multi-step reasoning for partition [{$labId}]");

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            
            // Fix: If step is an object/array from LLM, convert to string
            $stepStr = is_array($step) ? ($step['description'] ?? $step['step'] ?? json_encode($step)) : $step;

            if ($payload->task) {
                $progress = 50 + (int) (($index / count($steps)) * 25);
                $payload->task->update([
                    'progress' => $progress,
                    'description' => "Level 5: Phase {$stepNum}/" . count($steps) . " (" . substr($stepStr, 0, 30) . "...)"
                ]);
            }

            $scratchpadContent .= "## Phase {$stepNum}: {$stepStr}\n";
            $this->cognitive->persistCoT('workspace', $labId, $filename, $scratchpadContent);

            try {
                // Execute the specific step
                $reasoning = $this->processStep($payload, $stepStr, $scratchpadContent);
                
                $scratchpadContent .= "{$reasoning}\n\n";
                $this->cognitive->persistCoT('workspace', $labId, $filename, $scratchpadContent);
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
        1. Use the 'GLOBAL DATA HARVEST' and 'RELEVANT FRAGMENTS' sections below as your primary Ground Truth. 
        2. You MUST account for EVERY file or fragment mentioned in the context.
        3. If no specific files are visible in the fragments, look at the SILO MANIFEST or GLOBAL DISCOVERY overview.
        
        CONTEXT:
        {$payload->context}
        
        PRIOR WORK (from previous phases):
        {$priorWork}
        
        INSTRUCTIONS:
        1. If Phase 1: List EVERY file or specific data source found in the context.
        2. If Phase 2: Extract the specific fact (e.g. Illness, Diagnosis, Amount, Date) for EACH source individually.
           - MANDATE: Be extremely precise. Quote fragments if necessary.
        3. If Phase 3: Perform synthesis, mathematical tally, or final reasoning.
           - Show your logic clearly.
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

        $planStr = is_array($payload->plan) ? json_encode($payload->plan) : ($payload->plan ?: "Answer the user query based on the context.");

        $prompt = "Level 5 (Reasoning): Think step-by-step through the plan using the context.
        PLAN: {$planStr}
        CONTEXT: {$payload->context}
        {$analyticalRule}
        
        Write your hidden reasoning inside <think>...</think> tags.
        Then provide a draft response.";
        
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.4]]);
    }
}
