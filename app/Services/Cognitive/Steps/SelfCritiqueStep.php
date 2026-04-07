<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\Cognitive\CognitivePayload;

class SelfCritiqueStep
{
    public function __construct(protected OllamaService $ollama) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        if ($payload->task) {
            $payload->task->update([
                'progress' => 85,
                'description' => 'Level 6: Performing self-critique and verification...'
            ]);
        }

        $prompt = "Level 6 (Self-Critique): Review your reasoning for errors or hallucinations.
        REASONING: {$payload->scratchpad}
        CONTEXT: {$payload->context}
        
        Is this consistent with the data? If not, correct it.
        Output ONLY the corrected reasoning and draft.";
        
        $payload->verified = $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.1]]);

        return $next($payload);
    }
}