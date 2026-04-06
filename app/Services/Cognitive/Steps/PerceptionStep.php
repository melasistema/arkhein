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
        1. Intent (Informational, Actionable, Creative, Quantitative, INVENTORY)
           - Use INVENTORY/Quantitative for ANY request asking for a 'count', 'list', 'summary of all', or 'total' of ANY entity (e.g., patients, characters, books, invoices, items).
        2. Complexity (high|low)
           - Use 'high' for any query requiring a global scan of the silo or math across multiple documents.
        3. Context Strategy:
           - USE_MANIFEST: For simple file lists or counts.
           - USE_RAG: For deep questions about specific content inside 1-2 files.
           - HYBRID: For complex comparisons, 'most common' style queries, or cross-document analysis.
        4. Key Entities (Names, Projects, Dates, Entity Types)
        5. Constraints (Format, Tone, Language)
        
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