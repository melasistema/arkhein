<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Proactive Service Settings
    |--------------------------------------------------------------------------
    */

    'enabled' => true,

    // Minimum importance level required to trigger a notification (1-10)
    'min_importance' => 5,

    // How long to wait before re-notifying the same habit (in minutes)
    'notification_cooldown' => 60,

    /*
    |--------------------------------------------------------------------------
    | Trigger Heuristics
    |--------------------------------------------------------------------------
    */
    
    'heuristics' => [
        'time_detection' => true, // Auto-detect HH:MM in habit content
        'day_detection' => true,  // Detect "Monday", "Every day", etc.
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Verification (Optional)
    |--------------------------------------------------------------------------
    */
    
    // If true, use LLM to confirm if a habit should trigger (more accurate, more CPU)
    'use_llm_verification' => false,
];
