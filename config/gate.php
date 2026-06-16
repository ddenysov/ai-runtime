<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access gate
    |--------------------------------------------------------------------------
    |
    | When enabled with valid credentials in storage/app/gate/config.json,
    | unauthenticated visitors receive an nginx-like 404 until login is
    | temporarily opened from the gatekeeper Telegram bot.
    |
    */

    'enabled' => filter_var(env('GATE_ENABLED', false), FILTER_VALIDATE_BOOL),

    'open_seconds' => 120,

    'notification_cooldown_seconds' => 300,

    'storage_path' => storage_path('app/gate'),

    'webhook_path' => '/api/integrations/gatekeeper/telegram/webhook',

];
