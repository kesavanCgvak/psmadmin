<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assume Identity session lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes a read-only support session remains valid. Values below 5 or
    | above 120 are ignored and the default of 30 minutes is used instead,
    | so a misconfigured environment cannot create a long-lived credential.
    |
    */

    'ttl_minutes' => (int) env('SUPPORT_SESSION_TTL', 30),

];
