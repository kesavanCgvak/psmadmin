<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: openai, gemini
    |
    */
    'provider' => env('AI_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Shared request settings
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Provider-specific configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_version' => env('GEMINI_API_VERSION', 'v1beta'),
        ],

    ],

];
