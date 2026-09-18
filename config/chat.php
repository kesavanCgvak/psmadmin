<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend conversation URL
    |--------------------------------------------------------------------------
    |
    | Used in chat email/SMS deep links. {id} is replaced with the conversation
    | id. APP_FRONTEND_URL is the Vue/Quasar origin; do not hardcode hosts.
    |
    */
    'conversation_path' => env('CHAT_CONVERSATION_PATH', '#/chat?conversation={id}'),

    /*
    |--------------------------------------------------------------------------
    | Offline notification throttle
    |--------------------------------------------------------------------------
    |
    | If several messages arrive in the same conversation for the same user,
    | only the first email/SMS in this window is sent. Later messages are
    | recorded as throttled so batching can be tightened later without
    | changing chat persistence or Reverb delivery.
    |
    */
    'notification_throttle_seconds' => max(0, (int) env('CHAT_NOTIFICATION_THROTTLE_SECONDS', 300)),

    /*
    |--------------------------------------------------------------------------
    | Default per-user chat notification preferences
    |--------------------------------------------------------------------------
    |
    | Browser remains opt-in (Phase 3). Email when offline follows other PSM
    | transactional mail. SMS stays opt-in and requires an explicit enable.
    |
    */
    'defaults' => [
        'browser_notifications_enabled' => false,
        'email_notifications_enabled' => true,
        'sms_notifications_enabled' => false,
    ],

];
