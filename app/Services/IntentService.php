<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class IntentService
{
    public function __construct(protected OllamaService $ollama) {}

    /**
     * Classify the user input using the "Bouncer" Pattern with Heuristic Fallback.
     */
    public function classify(string $input, ?\App\Models\Vertical $vertical = null): string
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
        
        // 1. CONTEXTUAL CHECK: Is this a confirmation?
        // We only consider it a confirmation if the last assistant message was a PLAN.
        $confirmRegex = '/\b(yes|ok|okay|perfect|do it|go ahead|proceed|confirm|finalize|execute|make it so|sure)\b/i';
        
        if (preg_match($confirmRegex, $inputLower)) {
            if ($vertical) {
                $lastMsg = $vertical->interactions()->where('role', 'assistant')->latest()->first();
                $meta = $lastMsg?->metadata;
                if (is_string($meta)) $meta = json_decode($meta, true);
                
                // If there were pending actions, then YES, this is a confirmation.
                if (!empty($meta['pending_actions'])) {
                    if (!str_contains($inputLower, "don't") && !str_contains($inputLower, "not now")) {
                        Log::info("Arkhein Bouncer: Contextual -> CONFIRMATION");
                        return 'CONFIRMATION';
                    }
                }
            }
        }

        // 2. DEFAULT: CHAT
        Log::info("Arkhein Bouncer: Default -> CHAT");
        return 'CHAT';
    }
}
