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

        $prompt = "Level 3 (Decomposition): Break this task into a 4-phase 'Deep Reasoning' execution plan for an autonomous agent.
        USER: \"{$payload->query}\"
        ENVIRONMENT: {$schemaStr}
        MANIFEST (Silo Contents):
        {$payload->manifest}
        
        INSTRUCTIONS:
        1. Create a logical roadmap to fulfill the USER request using the MANIFEST.
        2. Phase 1: Targeting (Identify EVERY relevant file in the manifest).
        3. Phase 2: Extraction (Extract the specific facts requested for EACH file).
        4. Phase 3: Calculation/Tally (Perform math, count occurrences, or compare values across files).
        5. Phase 4: Final Conclusion (Synthesize and audit for completeness).
        
        Respond ONLY in a JSON array of strings: [\"Phase 1...\", \"Phase 2...\", \"Phase 3...\", \"Phase 4...\"]";
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