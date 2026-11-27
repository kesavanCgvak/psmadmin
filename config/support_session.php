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

    /*
    |--------------------------------------------------------------------------
    | Handshake window
    |--------------------------------------------------------------------------
    |
    | Seconds a newly issued support credential may sit unused before it is
    | retired. The marketplace activates the session by calling the API with
    | that credential. A session that is already active is unaffected.
    | Values below 15 or above 180 are ignored and 90 is used instead.
    |
    */

    'handshake_seconds' => (int) env('SUPPORT_SESSION_HANDSHAKE_SECONDS', 90),

];
