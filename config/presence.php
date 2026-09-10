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
    */
    'online_status_timeout' => (int) env('ONLINE_STATUS_TIMEOUT', 120),

];
