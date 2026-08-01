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
    /** GET ?modelId= — inventory quantities per location (Rental qty in stockQtyList) */
    'qty_per_location_path' => env('FLEX_QTY_PER_LOCATION_PATH', '/f5/api/inventory-model/qty-per-location'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Header
    |--------------------------------------------------------------------------
    | Flex API may use either:
    | - 'bearer'  => Authorization: Bearer {api_key}
    | - 'x_auth'  => X-Auth-Token: {api_key} (Flex docs often use this)
    */
    'auth_header' => env('FLEX_AUTH_HEADER', 'x_auth'),

    /*
    |--------------------------------------------------------------------------
    | Currency & Pricing API Paths
    |--------------------------------------------------------------------------
    | Used for fetching Day Rate from Flex resource pricing.
    */
    'currency_path' => env('FLEX_CURRENCY_PATH', '/f5/api/currency/identity'),
    'pricing_path' => env('FLEX_PRICING_PATH', '/f5/api/resource-pricing/grid-node'),

    /*
    |--------------------------------------------------------------------------
    | Inventory model custom fields (Pro Subrental Marketplace — PSM Code, etc.)
    |--------------------------------------------------------------------------
    | GET groups: ?resourceId= — then GET …/custom-field-value/{groupId}/resource-values?resourceId=
    */
    'custom_field_inventory_model_groups_path' => env(
        'FLEX_CUSTOM_FIELD_INVENTORY_MODEL_GROUPS_PATH',
        '/f5/api/custom-field-group/inventory-model/groups'
    ),
    /** sprintf: first %s = groupId (UUID) */
    'custom_field_resource_values_path_pattern' => env(
        'FLEX_CUSTOM_FIELD_RESOURCE_VALUES_PATH_PATTERN',
        '/f5/api/custom-field-value/%s/resource-values'
    ),
    /** sprintf: first %s = resourceId (UUID) — POST to set a custom field value */
    'custom_field_resource_value_save_path_pattern' => env(
        'FLEX_CUSTOM_FIELD_RESOURCE_VALUE_SAVE_PATH_PATTERN',
        '/f5/api/custom-field-value/resource/%s'
    ),

    /*
    |--------------------------------------------------------------------------
    | Sales quote & contact (rental request → Flex quote)
    |--------------------------------------------------------------------------
    | Optional env defaults for sales quote sync.
    */
    'contact_search_path' => env('FLEX_CONTACT_SEARCH_PATH', '/f5/api/contact/search'),
    'contact_create_path' => env('FLEX_CONTACT_CREATE_PATH', '/f5/api/contact'),
    'resource_type_path' => env('FLEX_RESOURCE_TYPE_PATH', '/f5/api/resource-type/nodes'),
    /** Query params for GET resource-type/nodes (Flex Client type lookup). */
    'resource_type_query' => [
        'classname' => env('FLEX_RESOURCE_TYPE_CLASSNAME', 'resource-type'),
        'nodeId' => env('FLEX_RESOURCE_TYPE_NODE_ID', 'root'),
    ],
    'global_search_path' => env('FLEX_GLOBAL_SEARCH_PATH', '/f5/api/search'),
    'element_create_path' => env('FLEX_ELEMENT_CREATE_PATH', '/f5/api/element'),

    /**
     * GET list of element definitions; Quote definitionId is resolved by name === "Quote"
     * (no longer loaded from FLEX_SALES_QUOTE_DEFINITION_ID).
     */
    'element_definition_identity_path' => env(
        'FLEX_ELEMENT_DEFINITION_IDENTITY_PATH',
        '/f5/api/element-definition/identity'
    ),

    /**
     * sprintf path: first %s = sales quote definitionId (resolved from element-definition/identity).
     * Example: /f5/api/element/%s/fields → GET …/element/9bfb850c-b117-11df-b8d5-00e08175e43e/fields?elementId=&parentElementId=
     */
    'element_fields_path_pattern' => env('FLEX_ELEMENT_FIELDS_PATH_PATTERN', '/f5/api/element/%s/fields'),

    /** Cache TTL (seconds) for element definition fields response */
    'element_fields_cache_ttl' => (int) env('FLEX_ELEMENT_FIELDS_CACHE_TTL', 3600),

    /** Cache TTL for Quote definitionId from element-definition/identity */
    'element_definition_cache_ttl' => (int) env('FLEX_ELEMENT_DEFINITION_CACHE_TTL', 86400),

    /** When false, only env values are used (no fields API). */
    'use_element_fields_api' => ($v = env('FLEX_USE_ELEMENT_FIELDS_API')) === null || filter_var($v, FILTER_VALIDATE_BOOLEAN),
    'financial_line_item_path' => env('FLEX_FINANCIAL_LINE_ITEM_PATH', '/f5/api/financial-document-line-item'),
    'referral_source_path' => env('FLEX_REFERRAL_SOURCE_PATH', '/f5/api/referral-source/identity'),
    'user_event_tracking_path' => env('FLEX_USER_EVENT_TRACKING_PATH', '/f5/api/user-event-tracking'),

    /** GET inventory groups; root group (parentGroupId null) is used when creating inventory models */
    'inventory_group_list_path' => env('FLEX_INVENTORY_GROUP_LIST_PATH', '/f5/api/inventory-group/list'),

    /** POST create inventory model */
    'inventory_model_create_path' => env('FLEX_INVENTORY_MODEL_CREATE_PATH', '/f5/api/inventory-model'),

    /** Inventory group name used when creating new inventory models */
    'inventory_model_group_name' => env('FLEX_INVENTORY_MODEL_GROUP_NAME', 'Non-Serialized Model'),

    /** Cache TTL for inventory group id used for product create */
    'inventory_group_cache_ttl' => (int) env('FLEX_INVENTORY_GROUP_CACHE_TTL', 86400),

    /**
     * sprintf path: first %s = financial document / quote id.
     * POST …/financial-document/{quoteId}/address-data
     */
    'financial_document_address_path_pattern' => env(
        'FLEX_FINANCIAL_DOCUMENT_ADDRESS_PATH_PATTERN',
        '/f5/api/financial-document/%s/address-data'
    ),

    /** POST quote / element notes */
    'element_notification_path' => env('FLEX_ELEMENT_NOTIFICATION_PATH', '/f5/api/element-notification'),

    'referral_source_cache_ttl' => (int) env('FLEX_REFERRAL_SOURCE_CACHE_TTL', 86400),
    'client_resource_type_cache_ttl' => (int) env('FLEX_CLIENT_RESOURCE_TYPE_CACHE_TTL', 86400),

    /** Max characters for response/request previews in flex-integration.log */
    'log_response_preview_max' => (int) env('FLEX_LOG_RESPONSE_PREVIEW_MAX', 8000),

    /**
     * Flex rejects bare dates (Y-m-d). Use local datetime without offset, e.g. 2026-04-20T05:00:00
     * (app timezone — see config/app.php timezone).
     */
    'quote_planned_datetime_format' => env('FLEX_QUOTE_PLANNED_DATETIME_FORMAT', 'Y-m-d\TH:i:s'),

    /** When true, adds currencyId to quote payload (some Flex versions require it). */
    'include_currency_in_quote' => env('FLEX_INCLUDE_CURRENCY_IN_QUOTE', false),

    'sales_quote_status_id' => env('FLEX_SALES_QUOTE_STATUS_ID'),
    'sales_quote_person_responsible_id' => env('FLEX_SALES_QUOTE_PERSON_RESPONSIBLE_ID'),
    'sales_quote_location_id' => env('FLEX_SALES_QUOTE_LOCATION_ID'),
    'sales_quote_default_pricing_model_id' => env('FLEX_SALES_QUOTE_DEFAULT_PRICING_MODEL_ID'),
];
