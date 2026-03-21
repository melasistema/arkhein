<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class IntentService
{
    public function __construct(protected OllamaService $ollama) {}

    /**
     * Classify the user input using the "Bouncer" Pattern with Heuristic Fallback.
     */
    public function classify(string $input): string
    {
        $inputLower = strtolower(trim($input, " .!"));

        // 0. COMMAND DETECTION (Magic Touch)
        if (str_starts_with($inputLower, '/')) {
            if (str_starts_with($inputLower, '/create')) return 'COMMAND_CREATE';
            if (str_starts_with($inputLower, '/move')) return 'COMMAND_MOVE';
            if (str_starts_with($inputLower, '/organize')) return 'COMMAND_ORGANIZE';
            if (str_starts_with($inputLower, '/help')) return 'COMMAND_HELP';
            if (str_starts_with($inputLower, '/delete')) return 'COMMAND_DELETE';
            if (str_starts_with($inputLower, '/sync')) return 'COMMAND_SYNC';
        }
        
        // 1. HEURISTIC: CONFIRMATION (Extreme High Priority)
        // Matches "do it", "ok do it", "go ahead", "yes please", etc.
        $confirmRegex = '/\b(yes|ok|okay|perfect|do it|go ahead|proceed|confirm|finalize|execute|make it so|sure)\b/i';
        
        if (preg_match($confirmRegex, $inputLower)) {
            // But exclude if it's clearly a more complex request like "don't do it" or "create a file"
            if (!str_contains($inputLower, "don't") && !str_contains($inputLower, "not now")) {
                Log::info("Arkhein Bouncer: Heuristic -> CONFIRMATION");
                return 'CONFIRMATION';
            }
        }

        // 2. DEFAULT: CHAT
        // We now enforce that all natural language is treated as a conversational RAG query.
        // File operations MUST be initiated with explicit '/' commands.
        Log::info("Arkhein Bouncer: Default -> CHAT");
        return 'CHAT';
    }
}
