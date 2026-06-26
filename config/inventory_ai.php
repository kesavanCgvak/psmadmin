<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy OpenAI settings (backward compatible)
    |--------------------------------------------------------------------------
    |
    | Prefer config('ai.providers.openai.*') and AI_PROVIDER for new setups.
    |
    */
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'timeout' => (int) env('OPENAI_TIMEOUT', env('AI_TIMEOUT', 60)),

    /*
    |--------------------------------------------------------------------------
    | Enrichment batch & queue
    |--------------------------------------------------------------------------
    */
    'batch_size' => (int) env('INVENTORY_AI_BATCH_SIZE', 100),
    'queue' => env('INVENTORY_AI_QUEUE', 'default'),
    'max_sync_rerun' => (int) env('INVENTORY_AI_MAX_SYNC_RERUN', 25),
    'max_sync_product_enrich' => (int) env('INVENTORY_AI_MAX_SYNC_PRODUCT_ENRICH', 100),
    'max_sync_cli_limit' => (int) env('INVENTORY_AI_MAX_SYNC_CLI_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Confidence thresholds
    |--------------------------------------------------------------------------
    |
    | Scores >= auto_approve_threshold are auto-approved and applied to inventory_master.
    | Scores below auto_approve_threshold are staged as pending for manual review.
    | Validation failures are still rejected regardless of confidence score.
    |
    */
    'auto_approve_threshold' => (int) env('INVENTORY_AI_AUTO_APPROVE_THRESHOLD', 60),

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

    /*
    |--------------------------------------------------------------------------
    | Audit log field name for manual rejection entries
    |--------------------------------------------------------------------------
    */
    'rejection_log_field_name' => '_rejection',

];
