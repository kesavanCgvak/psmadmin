<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rentman API (rental request → Project Request)
    |--------------------------------------------------------------------------
    | Per-company JWT lives in company_integrations.api_key.
    | Base URL falls back to services.rentman.base_url when not set on the row.
    */
    'timeout' => (int) env('RENTMAN_HTTP_TIMEOUT', 120),

    'project_request_path' => env('RENTMAN_PROJECT_REQUEST_PATH', '/projectrequests'),
    'project_request_equipment_path_pattern' => env(
        'RENTMAN_PROJECT_REQUEST_EQUIPMENT_PATH_PATTERN',
        '/projectrequests/%s/projectrequestequipment'
    ),
    'contact_list_path' => env('RENTMAN_CONTACT_LIST_PATH', '/contacts'),
    'contact_create_path' => env('RENTMAN_CONTACT_CREATE_PATH', '/contacts'),
    'equipment_path' => env('RENTMAN_EQUIPMENT_PATH', '/equipment'),
    'equipment_search_fields' => env('RENTMAN_EQUIPMENT_SEARCH_FIELDS', 'id,name,displayname,code,updateHash'),

    /** ISO 8601 datetime for planperiod_* (UTC Z preferred by Rentman samples). */
    'planperiod_datetime_format' => env('RENTMAN_PLANPERIOD_DATETIME_FORMAT', 'Y-m-d\TH:i:s\Z'),

    /** When true, push PSM rental_price as unit_price on equipment lines. */
    'push_unit_price' => filter_var(env('RENTMAN_PUSH_UNIT_PRICE', true), FILTER_VALIDATE_BOOLEAN),

    /** When true, POST /equipment if cache+sync still miss the product. */
    'create_equipment_if_missing' => filter_var(env('RENTMAN_CREATE_EQUIPMENT_IF_MISSING', true), FILTER_VALIDATE_BOOLEAN),

    /** Default folder path for new contacts. */
    'contact_folder' => env('RENTMAN_CONTACT_FOLDER', '/folders/0'),

    /** Default contact type (private|company). */
    'contact_type' => env('RENTMAN_CONTACT_TYPE', 'private'),

    /** Default language code on new project requests. */
    'default_language' => env('RENTMAN_DEFAULT_LANGUAGE', 'en'),

    /** Page size when listing contacts for local match. */
    'contact_list_limit' => (int) env('RENTMAN_CONTACT_LIST_LIMIT', 100),

    /** Max pages when retrieving all contacts (safety). */
    'contact_list_max_pages' => (int) env('RENTMAN_CONTACT_LIST_MAX_PAGES', 50),

    /** Max characters for response/request previews in rentman-integration.log */
    'log_response_preview_max' => (int) env('RENTMAN_LOG_RESPONSE_PREVIEW_MAX', 8000),
];
