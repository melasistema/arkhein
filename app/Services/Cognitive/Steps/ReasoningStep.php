<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\Cognitive\CognitivePayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ReasoningStep
{
    public function __construct(protected OllamaService $ollama) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        $intent = strtolower($payload->perception['intent'] ?? '');
        $complexity = strtolower($payload->perception['complexity'] ?? 'low');
        
        $isWorkspaceEnabled = (bool) config('arkhein.protocols.agent_workspace_enabled', false);
        
        // We always prioritize the Physical Workspace if enabled and we have a silo context.
        // This maintains the "Laboratory" protocol where reasoning is durable on disk.
        $usePhysical = $isWorkspaceEnabled && $payload->folder;

        if ($usePhysical) {
            if ($payload->task) $payload->task->update(['description' => 'Thinking deeply in physical workspace...']);
            $payload->scratchpad = $this->physicalScratchpad($payload);
        } else {
            if ($payload->task) $payload->task->update(['description' => 'Executing latent reasoning scratchpad...']);
            $payload->scratchpad = $this->latentScratchpad($payload, $intent);
        }

        return $next($payload);
    }

    protected function physicalScratchpad(CognitivePayload $payload): string
    {
        $workspaceDir = storage_path('app/arkhein/workspaces/' . $payload->folder->id);
        File::ensureDirectoryExists($workspaceDir, 0777);
        $scratchpadPath = $workspaceDir . DIRECTORY_SEPARATOR . 'scratchpad.md';

        $priorState = "";
        if (File::exists($scratchpadPath)) {
            $priorState = "PRIOR WORKSPACE STATE (Historical Reasoning):\n" . File::get($scratchpadPath) . "\n\n";
            Log::info("Arkhein Laboratory: Resuming from prior scratchpad state.");
        }

        Log::info("Arkhein Laboratory: Working on physical scratchpad", ['path' => $scratchpadPath]);

        $planStr = $payload->plan ?: "Perform deep analysis of the context to answer the user query accurately.";

        $prompt = "You are the Arkhein Analytical Agent.
        {$priorState}
        TASK: Perform the following PLAN using the provided CONTEXT.
        PLAN: {$planStr}
        CONTEXT: {$payload->context}
        
        INSTRUCTIONS:
        1. You are working in a PHYSICAL SCRATCHPAD file inside the system laboratory.
        2. Perform every step of the math/count/analysis now.
        3. Be extremely detailed. List each finding.
        4. If prior state exists, iterate or correct it; do not just repeat.
        5. Output ONLY the markdown content for the scratchpad.";

        $reasoning = $this->ollama->generate($prompt, null, [
            'options' => ['temperature' => 0.1, 'num_ctx' => 16384]
        ]);

        File::put($scratchpadPath, $reasoning);

        return $reasoning;
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