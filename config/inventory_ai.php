<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI / AI Provider
    |--------------------------------------------------------------------------
    */
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'timeout' => (int) env('OPENAI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Enrichment batch & queue
    |--------------------------------------------------------------------------
    */
    'batch_size' => (int) env('INVENTORY_AI_BATCH_SIZE', 100),
    'queue' => env('INVENTORY_AI_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Confidence thresholds
    |--------------------------------------------------------------------------
    */
    'auto_approve_threshold' => 75,
    'pending_min_threshold' => 60,
    'pending_max_threshold' => 74,
    'reject_threshold' => 50,

    /*
    |--------------------------------------------------------------------------
    | Physical specification fields tracked for enrichment
    |--------------------------------------------------------------------------
    */
    'spec_fields' => [
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
    ],

];
