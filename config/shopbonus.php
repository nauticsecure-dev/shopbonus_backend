<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    // Business day boundaries for counters/reports (Netherlands-first).
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Europe/Amsterdam'),

    'api' => [
        // Default per-client limit for /api/v1 (per user when authenticated, else per IP).
        'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
        // Browsable API docs at /docs (OpenAPI viewer + reference). Off unless enabled; never on by default in production.
        'docs_enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),
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

    /*
    |--------------------------------------------------------------------------
    | Onboarding (Trello #16)
    |--------------------------------------------------------------------------
    */

    'onboarding' => [
        // Google Places API (New). Empty = business search disabled (manual entry only).
        'google_places_api_key' => env('GOOGLE_PLACES_API_KEY'),

        // Domains where a mailbox does not prove control of the domain.
        'free_email_domains' => [
            'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.nl', 'live.com', 'live.nl',
            'msn.com', 'yahoo.com', 'yahoo.nl', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com',
            'ziggo.nl', 'kpnmail.nl', 'planet.nl', 'home.nl', 'hetnet.nl', 'xs4all.nl', 'upcmail.nl', 'telfort.nl',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees (Trello board: 30% Shopbonus fee ON TOP OF the reward amount)
    |--------------------------------------------------------------------------
    */

    'fees' => [
        'direct_reward_fee_bps' => (int) env('DIRECT_REWARD_FEE_BPS', 3000),
    ],

    'offers' => [
        // Currencies merchants may price offers in (Netherlands-first).
        'currencies' => ['EUR'],
        // Answer24 / reward claim window default (Trello: 90 days).
        'default_claim_validity_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer24 (Trello #1, #3, #23)
    |--------------------------------------------------------------------------
    |
    | Outbound dispatch is OFF until the Answer24 contract and the lawful
    | basis for contact are confirmed (OPEN-ANSWER24-CONTRACT,
    | OPEN-MARKETING-CONSENT). Opportunities are still evaluated and queued.
    |
    */

    // Central non-cash wallet (Trello #19, #23). No cash-out exists.
    'wallet' => [
        // Card #23: "Default credit validity: 12 months".
        'credit_validity_months' => (int) env('WALLET_CREDIT_VALIDITY_MONTHS', 12),
        'reservation_ttl_minutes' => 30,
    ],

    // Voucher checkout (Trello #20). Mollie test mode only unless explicitly allowed (OPEN-FINANCIAL-LEGAL).
    'payments' => [
        'checkout_ttl_minutes' => 30,
        // A Mollie payment can still complete after the checkout's soft expiry.
        'checkout_hard_limit_minutes' => 180,
        'mollie' => [
            'key' => env('MOLLIE_API_KEY'),
            'base_url' => env('MOLLIE_API_BASE_URL', 'https://api.mollie.com/v2'),
            'allow_live' => (bool) env('MOLLIE_ALLOW_LIVE', false),
        ],
    ],

    // Affiliate tracking (Trello #19, #23). Adapters are NOT VERIFIED (no network accounts).
    'affiliate' => [
        // on_network_approval (owner's safe default): pending until the network approves.
        // on_validated_attribution (card #23): credit immediately after attribution checks.
        'credit_timing' => env('AFFILIATE_CREDIT_TIMING', 'on_network_approval'),
        // Network used when programs cannot be compared reliably.
        'fallback_network' => env('AFFILIATE_FALLBACK_NETWORK', 'daisycon'),
        // Reference basket for comparing percentage against fixed commissions.
        'reference_basket_minor' => 5000,
        'selection_rule_version' => '2026-10-23.1',
        // A conversion must cite a click from this many days back.
        'attribution_window_days' => 30,
        'networks' => [
            'daisycon' => ['base_url' => env('DAISYCON_API_BASE_URL', 'https://services.daisycon.com')],
        ],
    ],

    // Public discovery (Trello #18). Category keys are stable API values; labels NL/EN.
    'discovery' => [
        'cache_ttl_seconds' => (int) env('DISCOVERY_CACHE_TTL_SECONDS', 60),
        'max_per_page' => 50,
        'logo_max_kb' => 1024,
        'categories' => [
            'fashion' => ['nl' => 'Mode', 'en' => 'Fashion'],
            'home_living' => ['nl' => 'Wonen', 'en' => 'Home & living'],
            'electronics' => ['nl' => 'Elektronica', 'en' => 'Electronics'],
            'beauty_health' => ['nl' => 'Beauty & gezondheid', 'en' => 'Beauty & health'],
            'sports_outdoor' => ['nl' => 'Sport & outdoor', 'en' => 'Sports & outdoor'],
            'kids_toys' => ['nl' => 'Kinderen & speelgoed', 'en' => 'Kids & toys'],
            'food_drinks' => ['nl' => 'Eten & drinken', 'en' => 'Food & drinks'],
            'books_media' => ['nl' => 'Boeken & media', 'en' => 'Books & media'],
            'garden_diy' => ['nl' => 'Tuin & klussen', 'en' => 'Garden & DIY'],
            'pets' => ['nl' => 'Dieren', 'en' => 'Pets'],
            'travel_leisure' => ['nl' => 'Reizen & vrije tijd', 'en' => 'Travel & leisure'],
            'other' => ['nl' => 'Overig', 'en' => 'Other'],
        ],
    ],

    'answer24' => [
        'dispatch_enabled' => (bool) env('ANSWER24_DISPATCH_ENABLED', false),
        'base_url' => env('ANSWER24_BASE_URL'),
        'contact_path' => env('ANSWER24_CONTACT_PATH', '/api/shopbonus/v1/contact-requests'),
        'cancel_path' => env('ANSWER24_CANCEL_PATH', '/api/shopbonus/v1/contact-requests/{reference}/cancel'),
        'claim_status_path' => env('ANSWER24_CLAIM_STATUS_PATH', '/api/shopbonus/v1/claims/{reference}/status'),
        'signing_secret' => env('ANSWER24_SIGNING_SECRET'),
        // Legitimate interest as a basis for calls stays off until legally confirmed.
        'allow_legitimate_interest' => (bool) env('ANSWER24_ALLOW_LEGITIMATE_INTEREST', false),
        'grouping_window_minutes' => 60,
        'recent_contact_days' => (int) env('ANSWER24_RECENT_CONTACT_DAYS', 30),
        'claim_requires_email_match' => true,
        'reminder_days' => [30, 60, 80],
        'dispatch_max_attempts' => 10,
    ],

];
