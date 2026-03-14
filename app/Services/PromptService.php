<?php

namespace App\Services;

class PromptService
{
    /**
     * Build the primary system prompt by merging persona and active features.
     */
    public function buildSystemPrompt(): string
    {
        $persona = $this->getPersona();
        $verticals = $this->getVerticals();

        return $persona . "\n\n" . $verticals;
    }

    protected function getPersona(): string
    {
        $config = config('prompts.persona');
        
        return str_replace(
            ['{name}', '{role}', '{personality}', '{ethics}'],
            [
                $config['name'],
                $config['role'],
                implode("\n- ", $config['personality']),
                implode("\n- ", $config['ethics'])
            ],
            $config['template']
        );
    }

    protected function getVerticals(): string
    {
        $output = "AVAILABLE CAPABILITIES:\n";
        
        // 1. File Management
        if (config('prompts.verticals.files.enabled')) {
            $output .= config('prompts.verticals.files.protocol');
        }

        // Future verticals (vision, sound, etc.) can be added here
        
        return $output;
    }

    public function getReflectionPrompt(): string
    {
        return "As an AI reflection module, analyze this interaction.
Extract any new personal facts, habits, or behavioral patterns about the user.
Return ONLY a JSON array: [{\"type\": \"fact|habit|pattern|personality\", \"content\": \"text\", \"importance\": 1-10}]";
    }
}
