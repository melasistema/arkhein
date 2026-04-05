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
        
        $isWorkspaceEnabled = config('arkhein.protocols.agent_workspace_enabled', false);
        
        // WISE OVERRIDE: If the user says "all", "list", "every", or "count", it IS complex.
        $hasHighIntensityKeywords = preg_match('/\b(all|every|list|count|total|inventory|summarize everything)\b/i', $payload->query);
        
        $isComplex = (in_array($intent, ['quantitative', 'structural', 'creative']) || $complexity === 'high' || $hasHighIntensityKeywords);

        $usePhysical = $isWorkspaceEnabled && $isComplex;

        if ($usePhysical && $payload->folder) {
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

        $prompt = "You are the Arkhein Analytical Agent.
        {$priorState}
        TASK: Perform the following PLAN using the provided CONTEXT.
        PLAN: {$payload->plan}
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

        $prompt = "Level 4 (Reasoning): Think step-by-step through the plan using the context.
        PLAN: {$payload->plan}
        CONTEXT: {$payload->context}
        {$analyticalRule}
        
        Write your hidden reasoning inside <think>...</think> tags.
        Then provide a draft response.";
        
        return $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.4]]);
    }
}