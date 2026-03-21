<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn ($value) => trim($value),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://arpaa.my.id,https://www.arpaa.my.id,http://localhost,http://localhost:5173,http://127.0.0.1:5173,capacitor://localhost,http://localhost'))
)));

$allowedOriginPatterns = array_values(array_filter(array_map(
    static fn ($value) => trim($value),
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => $allowedOriginPatterns,
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => false,
];
