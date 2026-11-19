<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HireHop API
    |--------------------------------------------------------------------------
    | The base URL is the same for every company. Tokens are stored per company
    | in company_integrations.api_key and are not configured here.
    */
    'base_url' => rtrim((string) env('HIREHOP_API_BASE_URL', 'https://myhirehop.com'), '/'),

    'timeout' => (int) env('HIREHOP_HTTP_TIMEOUT', 15),

    'user_info_path' => '/php_functions/get_user_info.php',

    /*
    | Supported hire-stock list. /modules/stock/list.php is deprecated and is
    | not called. The documented row id is MAIN_ID. Live rows may send ID
    | instead, and MAIN_ID is preferred when both are present.
    */
    'stock_list_path' => '/modules/stock/asset_assign_list.php',

    'list_rows_default' => 20,

    'list_rows_min' => 20,

    'list_rows_max' => 200,

    'list_cache_seconds' => (int) env('HIREHOP_LIST_CACHE_SECONDS', 60),

    /*
    | One retry for an idempotent product read after HTTP 429 or HireHop error
    | 327. Import writes are not retried. 5000 matches HireHop's loop guidance.
    */
    'retry_delay_ms' => (int) env('HIREHOP_RETRY_DELAY_MS', 5000),
];
