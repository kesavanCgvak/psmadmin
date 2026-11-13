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
];
