<?php

namespace App\Services;

class PromptService
{
    /**
     * Build the primary system prompt for the Help Chat.
     */
    public function buildHelpPrompt(): string
    {
        $config = config('prompts.help');
        
        return $config['persona'] . "\n\n" . $config['knowledge'];
    }
}
