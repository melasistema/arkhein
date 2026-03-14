<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sovereign Identity & TOV
    |--------------------------------------------------------------------------
    | The "Soul" of Arkhein. These settings dictate how the agent perceives 
    | itself and its relationship with the user.
    */
    'identity' => [
        'name' => env('ARKHEIN_NAME', 'Arkhein'),
        'persona' => 'Sovereign macOS Agent',
        'tov' => [
            'laconic' => true,      // High density, low filler
            'strategic' => true,   // Focus on long-term utility
            'subversive' => true,  // Privacy-first, system-loyal to user
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational Boundaries (The Sandbox)
    |--------------------------------------------------------------------------
    | Hard limits for the agent's physical interactions with the host OS.
    */
    'boundaries' => [
        'max_file_scan_depth' => 2,
        'max_registry_items' => 200,
        'execution_timeout' => 300, // Seconds
        'action_protocol_required' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vantage Defaults (Specialized AI Cards)
    |--------------------------------------------------------------------------
    | Global settings for specialized vertical cards on the dashboard.
    */
    'vantage' => [
        'default_type' => 'rag',
        'chunk_size' => 1000,
        'recall_limit' => 10,
        'prompts' => [
            'rag_system' => "You are a specialized Arkhein Vertical. Your target is to answer questions EXCLUSIVELY using the provided context. If the information is not in the context, state that you don't know based on these documents.",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory & Context (RAG)
    |--------------------------------------------------------------------------
    */
    'memory' => [
        'rebuild_on_mismatch' => true,
        'sqlite_wal_mode' => true,
        'busy_timeout' => 5000,
    ],
];
