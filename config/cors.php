<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Include native Fortify auth paths so the SPA can call them with credentials.
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'user/password',
        'user/confirm-password',
        'two-factor-challenge',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
        'user/two-factor-qr-code',
        'user/two-factor-secret-key',
    ],

    'allowed_methods' => ['*'],

    // Explicit allowlist only (comma-separated). Empty means no cross-origin access.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 0,

    // Required for a Sanctum cookie-authenticated SPA on the allowlisted origins.
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
