<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'hr_sync' => [
        'base_url' => env('HR_SYNC_BASE_URL', 'http://127.0.0.1:8080'),
        'endpoint' => env('HR_SYNC_ENDPOINT', '/api/internal/pos/auth-context'),
        'token' => env('HR_SYNC_TOKEN'),
        'timeout' => (int) env('HR_SYNC_TIMEOUT', 30),
    ],

];
