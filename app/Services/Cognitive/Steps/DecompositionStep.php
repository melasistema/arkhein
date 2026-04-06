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

        $intent = strtolower($payload->perception['intent'] ?? 'informational');
        $schema = $payload->folder?->environmental_schema ?? [];
        $schemaStr = !empty($schema) ? json_encode($schema, JSON_PRETTY_PRINT) : "No schema.";

        $prompt = "Level 3 (Decomposition): Create a detailed execution plan for an autonomous agent.
        USER QUERY: \"{$payload->query}\"
        INTENT: {$intent}
        MANIFEST:
        {$payload->manifest}
        
        INSTRUCTIONS:
        1. Break this task into 3-5 logical phases.
        2. If INTENT is 'Quantitative' or 'Inventory':
           - Phase 1: Target ALL relevant documents from the manifest.
           - Phase 2: Extract specific metrics/facts from EACH targeted document.
           - Phase 3: Perform math, counting, or comparison.
           - Phase 4: Audit for completeness and synthesize final tally.
        3. If INTENT is 'Creative' or 'Informational':
           - Phase 1: Search and gather context fragments.
           - Phase 2: Analyze key points and align with user constraints.
           - Phase 3: Draft response and self-audit.
        
        Respond ONLY as a JSON array of strings.";
        
        $res = $this->ollama->generate($prompt, null, ['format' => 'json']);
        $steps = json_decode($res, true);

        if (!$steps || !is_array($steps)) {
            if (preg_match('/\[.*\]/s', $res, $matches)) {
                $steps = json_decode($matches[0], true);
            }
        }

        $payload->plan = $steps ?: [
            "Identify relevant documents in the silo.",
            "Analyze the contents based on the user instruction.",
            "Synthesize the final answer."
        ];

        return $next($payload);
        }
        }