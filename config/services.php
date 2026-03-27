<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Engine (Local-first)
    |--------------------------------------------------------------------------
    | Efficient (Standard): Mistral + Nomic (768d)
    | Elite (High-Precision): Qwen3 Suite (1056d)
    */
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_DEFAULT_MODEL', 'mistral'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text:latest'),
        'embedding_dimensions' => (int) env('OLLAMA_EMBEDDING_DIMENSIONS', 768),
    ],

];
