<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\RagService;
use App\Services\Cognitive\CognitivePayload;

class ContextRetrievalStep
{
    public function __construct(protected RagService $rag) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        if ($payload->task) {
            $payload->task->update(['description' => 'Retrieving high-signal context...']);
        }

        $strategy = $payload->perception['strategy'] ?? 'HYBRID';
        $intent = $payload->perception['intent'] ?? 'Informational';

        // Type safety: LLMs sometimes return arrays for these fields
        if (is_array($strategy)) $strategy = $strategy[0] ?? 'HYBRID';
        if (is_array($intent)) $intent = $intent[0] ?? 'Informational';
        
        $strategy = (string) $strategy;
        $intent = (string) $intent;

        // 1. MANIFEST ONLY: Clean structural view
        if ($strategy === 'USE_MANIFEST') {
            $payload->context = $payload->manifest;
            return $next($payload);
        }

        // 2. RETRIEVE FRAGMENTS (For RAG or HYBRID)
        // Adaptive limit based on query complexity and intent
        $complexity = strtolower((string) ($payload->perception['complexity'] ?? 'low'));
        
        $limit = match(strtolower($intent)) {
            'quantitative', 'inventory' => 50,
            'structural' => 30,
            'creative' => 20,
            default => ($complexity === 'high' ? 25 : 10),
        };

        $fragments = $this->rag->recall($payload->query, $limit, $payload->folderId);
        $ctx = collect($fragments)->map(function($f) {
            $filename = $f['vessel']['filename'] ?? $f['metadata']['filename'] ?? 'Unknown Source';
            return "[{$filename}]: {$f['content']}";
        })->implode("\n\n");

        // 3. RAG ONLY
        if ($strategy === 'USE_RAG') {
            $payload->context = "### RELEVANT FRAGMENTS:\n{$ctx}";
            return $next($payload);
        }

        // 4. HYBRID: Both
        $payload->context = "{$payload->manifest}\n\n### RELEVANT FRAGMENTS:\n{$ctx}";
        
        return $next($payload);
    }
}