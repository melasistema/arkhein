<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Intent Classification Prompt
    |--------------------------------------------------------------------------
    */

    'classify' => "Analyze the user input and classify the primary intent.
Return ONLY a JSON object: {\"intent\": \"CHAT|FILE_SYSTEM|SCHEDULE|KNOWLEDGE\", \"reasoning\": \"Brief reason\"}

Intents:
- CHAT: General conversation, greetings, philosophy, or questions that don't require system changes.
- FILE_SYSTEM: Creating, moving, deleting, organizing, or searching for specific files and folders.
- SCHEDULE: Setting habits, daily reports, or time-based tasks.
- KNOWLEDGE: Asking about what you remember, personal facts, or learning from the archive.

Input: {input}",
];
