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

    /*
    |--------------------------------------------------------------------------
    | Identity (Trello #4)
    |--------------------------------------------------------------------------
    */

    'identity' => [
        // Where the SPA lives; password-reset links point here.
        'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

        'verification' => [
            'code_ttl_minutes' => (int) env('VERIFICATION_CODE_TTL_MINUTES', 10),
            'max_attempts' => (int) env('VERIFICATION_CODE_MAX_ATTEMPTS', 5),
            'resend_cooldown_seconds' => (int) env('VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Published legal document versions
    |--------------------------------------------------------------------------
    |
    | Interim source until the CMS (B15) publishes versioned legal documents.
    | Registration fails closed (503) while a required version is unset, so no
    | one can accept an undefined document.
    |
    */

    'legal' => [
        'terms_version' => env('LEGAL_TERMS_VERSION'),
        'privacy_version' => env('LEGAL_PRIVACY_VERSION'),
        'marketing_consent_version' => env('LEGAL_MARKETING_CONSENT_VERSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces (Trello #16)
    |--------------------------------------------------------------------------
    */

    'workspaces' => [
        // Anti-abuse cap on webshops a user may have in onboarding/review at once.
        'max_unapproved_per_user' => (int) env('WORKSPACES_MAX_UNAPPROVED_PER_USER', 10),
    ],

];
