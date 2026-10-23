<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    'api' => [
        // Default per-client limit for /api/v1 (per user when authenticated, else per IP).
        'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health checks
    |--------------------------------------------------------------------------
    |
    | /api/v1/health/ready always returns 200/503. Per-component detail is only
    | included when the caller sends X-Health-Token matching this value, so
    | infrastructure layout is not disclosed publicly. Leave empty to never
    | expose detail.
    |
    */

    'health' => [
        'readiness_token' => env('HEALTH_READINESS_TOKEN'),
    ],

];
