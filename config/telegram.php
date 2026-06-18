<?php

return [

    'webhook' => [
        'agent_channel_path' => env('TELEGRAM_WEBHOOK_AGENT_PATH', '/webhooks/telegram/{uuid}'),
        'http_enabled' => filter_var(env('TELEGRAM_WEBHOOK_HTTP_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'ingress' => env('TELEGRAM_WEBHOOK_INGRESS', 'direct'),

    'sqs' => [
        'queue' => env('SQS_WEBHOOK_QUEUE'),
        'prefix' => env('SQS_PREFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'wait_time_seconds' => (int) env('SQS_WEBHOOK_WAIT_SECONDS', 20),
        'max_messages' => (int) env('SQS_WEBHOOK_MAX_MESSAGES', 10),
        'visibility_timeout' => (int) env('SQS_WEBHOOK_VISIBILITY_TIMEOUT', 120),
        'connect_timeout' => (int) env('SQS_WEBHOOK_CONNECT_TIMEOUT', 5),
        'request_timeout' => (int) env('SQS_WEBHOOK_REQUEST_TIMEOUT', 35),
    ],

];
