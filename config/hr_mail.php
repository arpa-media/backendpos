<?php

return [
    'host' => env('HR_MAIL_HOST', 'mail.tokokopijaya.com'),
    'port' => (int) env('HR_MAIL_PORT', 465),
    'scheme' => env('HR_MAIL_SCHEME', 'smtps'),
    'username' => env('HR_MAIL_USERNAME', 'ch.hr@tokokopijaya.com'),
    'password' => env('HR_MAIL_PASSWORD'),
    'from_address' => env('HR_MAIL_FROM_ADDRESS', 'ch.hr@tokokopijaya.com'),
    'from_name' => env('HR_MAIL_FROM_NAME', 'Human Resource Chambers - Toko Kopi Jaya'),
    'timeout' => (int) env('HR_MAIL_TIMEOUT', 30),
];
