<?php

return [

    'webhook' => [
        'agent_channel_path' => env('TELEGRAM_WEBHOOK_AGENT_PATH', '/webhooks/telegram/{uuid}'),
        'http_enabled' => filter_var(env('TELEGRAM_WEBHOOK_HTTP_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'ingress' => env('TELEGRAM_WEBHOOK_INGRESS', 'direct'),

    'sqs_queue' => env('SQS_WEBHOOK_QUEUE'),

];
