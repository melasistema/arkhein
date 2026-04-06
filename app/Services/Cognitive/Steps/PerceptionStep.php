<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\Cognitive\CognitivePayload;

class PerceptionStep
{
    public function __construct(protected OllamaService $ollama) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        if ($payload->task) {
            $payload->task->update(['description' => 'Perceiving intent and constraints...']);
        }

        $schema = $payload->folder?->environmental_schema ?? [];
        $schemaStr = !empty($schema) ? json_encode($schema, JSON_PRETTY_PRINT) : "No environmental schema detected.";

        $prompt = "Level 1 (Perception): Analyze the user query based on the ENVIRONMENTAL SCHEMA.
        ENVIRONMENTAL SCHEMA:
        {$schemaStr}

        QUERY: \"{$payload->query}\"
        
        Extract:
        1. Intent:
           - INVENTORY: For requests asking for a full list, count, or summary of ALL items in the silo (e.g., 'list all patients', 'how many files').
           - Quantitative: For requests asking for specific math or tallies based on content (e.g., 'sum of all invoices', 'average age').
           - Actionable: For requests to modify the system (create, move, delete).
           - Informational: For questions about specific facts inside files.
           - Creative: For drafting, brainstorming, or writing tasks.
        2. Complexity (high|low):
           - 'high' if the task requires scanning multiple files, performing math, or complex multi-step reasoning.
        3. Context Strategy:
           - USE_MANIFEST: If the user only asks about file names, counts, or structure.
           - USE_RAG: If the user asks a specific question about content inside a few files.
           - HYBRID: If the task requires both structural knowledge (manifest) and deep content analysis (RAG).
        4. Key Entities: Extract names, projects, dates, or specific entity types mentioned.
        5. Constraints: Tone, format, language, or length requirements.
        
        Respond ONLY in JSON.";

        $res = $this->ollama->generate($prompt, null, ['format' => 'json']);
        $data = json_decode($res, true);

        if (!$data || !isset($data['intent'])) {
            if (preg_match('/\{.*\}/s', $res, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        $payload->perception = [
            'intent' => $data['intent'] ?? 'Informational',
            'complexity' => $data['complexity'] ?? 'low',
            'strategy' => $data['context_strategy'] ?? $data['strategy'] ?? 'HYBRID',
            'entities' => $data['entities'] ?? [],
            'constraints' => $data['constraints'] ?? []
        ];

        return $next($payload);
    }
}