<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\Cognitive\CognitivePayload;

class DecompositionStep
{
    public function __construct(protected OllamaService $ollama) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        if ($payload->task) {
            $payload->task->update(['description' => 'Decomposing task into sub-goals...']);
        }

        $schema = $payload->folder?->environmental_schema ?? [];
        $schemaStr = !empty($schema) ? json_encode($schema, JSON_PRETTY_PRINT) : "No schema.";

        $prompt = "Level 3 (Decomposition): Break this task into a 3-step execution plan.
        USER: \"{$payload->query}\"
        ENVIRONMENT: {$schemaStr}
        CONTEXT: {$payload->context}
        
        PLAN:";
        
        $payload->plan = $this->ollama->generate($prompt, null, ['options' => ['temperature' => 0.1]]);

        return $next($payload);
    }
}