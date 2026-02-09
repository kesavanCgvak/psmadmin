<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HubSpot API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('HUBSPOT_BASE_URL', 'https://api.hubapi.com'),

    /*
    |--------------------------------------------------------------------------
    | HubSpot Private App Access Token
    |--------------------------------------------------------------------------
    |
    | Store your HubSpot private app access token in the environment as:
    | HUBSPOT_ACCESS_TOKEN=your-token-here
    |
    */
    'access_token' => env('HUBSPOT_ACCESS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Contact Property Mapping
    |--------------------------------------------------------------------------
    |
    | Map local user fields to HubSpot contact properties.
    | You can adjust these if your HubSpot portal uses custom property names.
    |
    */
    'properties' => [
        // Standard HubSpot defaults; override via .env if your internal names differ
        'email' => env('HUBSPOT_PROP_EMAIL', 'email'),
        'phone' => env('HUBSPOT_PROP_PHONE', 'phone'),
        'user_type' => env('HUBSPOT_PROP_USER_TYPE', 'user_type'),
        'firstname' => env('HUBSPOT_PROP_FIRSTNAME', 'firstname'),
        'lastname' => env('HUBSPOT_PROP_LASTNAME', 'lastname'),
        // Optional custom "Full Name" property, if you have one in HubSpot
        'full_name' => env('HUBSPOT_PROP_FULL_NAME', 'fullname'),
    ],
];

