<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Online status timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | A user is considered online when last_seen_at is greater than or equal
    | to the current time minus this timeout. The frontend should send a
    | heartbeat more frequently than this window (typically every 30–60s).
    |
    | Reverb presence is the real-time connection overlay. last_seen_at remains
    | the persistent fallback used by REST (Providers listing, conversation
    | list) when WebSockets are unavailable.
    |
    */
    'online_status_timeout' => (int) env('ONLINE_STATUS_TIMEOUT', 120),

];
