<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Memory Confidence Thresholds
    |--------------------------------------------------------------------------
    */

    // Minimum similarity score to include a memory in the LLM context (0.0 to 1.0)
    'recall_threshold' => 0.55,

    // Similarity score required to trigger a habit/fact reconciliation (conflict check)
    'reconciliation_threshold' => 0.88,

    /*
    |--------------------------------------------------------------------------
    | Extraction Settings
    |--------------------------------------------------------------------------
    */
    
    // Number of previous messages to analyze during reflection
    'reflection_limit' => 10,

    // Default importance assigned to new insights
    'default_importance' => 3,
];
