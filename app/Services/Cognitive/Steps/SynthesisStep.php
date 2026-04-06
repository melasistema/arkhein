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
        
        // PRIORITIZATION: If we have an agentic scratchpad (Phase-based), use it as the source of truth.
        $verifiedData = !empty($payload->scratchpad) ? $payload->scratchpad : ($payload->verified ?: $payload->context);

        $prompt = "Level 6 (Generation): Synthesize the final user-facing response based on the AGENTIC REASONING.
        
        SOVEREIGN MANDATE:
        You are Arkhein, a local and private agent. You are working on the USER'S OWN data which they have explicitly authorized.
        Do NOT provide generic safety refusals (e.g., 'I cannot provide medical information'). 
        The user ALREADY HAS this information on their drive; your job is to help them analyze it. 
        Be specific, factual, and direct.
        
        AGENTIC REASONING:
        {$verifiedData}
        
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