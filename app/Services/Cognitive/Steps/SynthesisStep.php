<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\Cognitive\CognitivePayload;

class SynthesisStep
{
    public function __construct(protected OllamaService $ollama) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        if ($payload->task) {
            $payload->task->update(['description' => 'Synthesizing final response...']);
        }

        $intent = $payload->perception['intent'] ?? 'Informational';
        $verifiedData = $payload->verified ?: $payload->scratchpad; // Fallback if critique skipped

        $prompt = "Level 6 (Generation): Synthesize the final user-facing response.
        DATA: {$verifiedData}
        INTENT: {$intent}
        
        RULES:
        1. Be laconic and professional.
        2. Do not show your thinking tags.
        3. Match the user's language.
        
        FINAL RESPONSE:";
        
        $rawResult = $this->ollama->generate($prompt);

        // THE SOVEREIGN PURGE: Ensure no internal thinking blocks leak to the user
        $result = preg_replace('/<think>.*?<\/think>/s', '', $rawResult);
        $payload->finalResult = trim($result);

        return $next($payload);
    }
}