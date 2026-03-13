<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Flex API Paths
    |--------------------------------------------------------------------------
    | Each company has its own api_base_url in company_integrations.
    | These paths are appended to that base URL.
    |
    | If you get 404, try:
    | - /api/inventory-model/search (no "f5")
    | - /f5/api/report/process/{reportId} (report-based - needs report ID from Flex)
    | Check your Flex instance Swagger UI (often at /swagger-ui.html) for exact paths.
    */
    'search_path' => env('FLEX_SEARCH_PATH', '/f5/api/inventory-model/search'),
    'details_path' => env('FLEX_DETAILS_PATH', '/f5/api/inventory-model'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Header
    |--------------------------------------------------------------------------
    | Flex API may use either:
    | - 'bearer'  => Authorization: Bearer {api_key}
    | - 'x_auth'  => X-Auth-Token: {api_key} (Flex docs often use this)
    */
    'auth_header' => env('FLEX_AUTH_HEADER', 'bearer'),
];
