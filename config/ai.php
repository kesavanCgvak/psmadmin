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
    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Shared request settings
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Rate limit pacing & retries
    |--------------------------------------------------------------------------
    |
    | OpenAI limits requests per minute (RPM) and tokens per minute (TPM).
    | Pace outbound calls and retry 429 rate-limit errors with backoff.
    | See: https://developers.openai.com/api/docs/guides/rate-limits
    |
    | Tier 1 accounts are often low RPM — start conservative (3 RPM) and
    | increase after checking limits at platform.openai.com/settings/limits
    |
    */
    'rate_limit' => [
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 3),
        'max_retries' => (int) env('AI_RATE_LIMIT_MAX_RETRIES', 6),
        'initial_backoff_seconds' => (float) env('AI_RATE_LIMIT_INITIAL_BACKOFF', 1),
        'max_backoff_seconds' => (float) env('AI_RATE_LIMIT_MAX_BACKOFF', 60),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider-specific configuration
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-nano'),
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
