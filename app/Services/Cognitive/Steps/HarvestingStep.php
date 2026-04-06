<?php

namespace App\Services\Cognitive\Steps;

use Closure;
use App\Services\OllamaService;
use App\Services\RagService;
use App\Services\Cognitive\CognitivePayload;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

class HarvestingStep
{
    public function __construct(
        protected OllamaService $ollama,
        protected RagService $rag
    ) {}

    public function __invoke(CognitivePayload $payload, Closure $next)
    {
        $intent = strtolower($payload->perception['intent'] ?? '');
        $hasHighIntensityKeywords = preg_match('/\b(most|common|top|frequent|proportion|average|total|sum)\b/i', $payload->query);

        // We only perform a structural harvest for global aggregate queries in a silo context.
        if (($intent === 'quantitative' || $intent === 'inventory') && $hasHighIntensityKeywords && $payload->folder) {
            
            if ($payload->task) {
                $payload->task->update(['description' => 'Performing structural data harvest...']);
            }

            Log::info("Arkhein Harvesting: Starting global silo scan for '{$payload->query}'");

            // 1. Get all relevant documents in the silo with metadata included
            $docs = Document::where('folder_id', $payload->folder->id)->get(['id', 'path', 'summary', 'metadata']);
            $totalCount = $docs->count();

            $harvestedContext = "### SILO GROUND TRUTH (TOTAL FILES AUTHORIZED: {$totalCount})\n";
            $harvestedContext .= "MANDATE: You MUST use this list for any counts or aggregate analysis. Do NOT hallucinate numbers outside of this list.\n\n";
            
            $count = 0;
            foreach ($docs as $doc) {
                $count++;
                $perception = $doc->metadata['perception'] ?? [];
                $type = $perception['document_type'] ?? 'Unknown';
                $fact = $perception['semantic_summary'] ?? "No summary available.";
                
                $harvestedContext .= "FILE {$count}: [{$doc->path}]\n";
                $harvestedContext .= "TYPE: {$type}\n";
                $harvestedContext .= "CONTENT SUMMARY: {$fact}\n\n";
            }

            // Prepend to context
            $payload->context = $harvestedContext . "\n" . $payload->context;
            
            Log::info("Arkhein Harvesting: Completed harvest of {$count} documents.");
        }

        return $next($payload);
    }
}
