<?php

$corsAllowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(
        ',',
        (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:5173,http://127.0.0.1:5173,https://unicaprint.com.br,https://www.unicaprint.com.br'
        )
    )
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $corsAllowedOrigins,
    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^https://([a-z0-9-]+\.)?unicaprint\.com\.br$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
