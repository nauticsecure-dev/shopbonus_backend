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

    // Super Admin security (B16a, Trello #21 "Audit & security controls").
    'admin' => [
        // Super Admin routes require confirmed TOTP two-factor authentication.
        'mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),
        // Emailed step-up code for sensitive admin actions (after a recent password).
        'step_up' => [
            'code_ttl_minutes' => (int) env('ADMIN_STEP_UP_CODE_TTL_MINUTES', 10),
            'max_attempts' => (int) env('ADMIN_STEP_UP_MAX_ATTEMPTS', 5),
            'valid_minutes' => (int) env('ADMIN_STEP_UP_VALID_MINUTES', 10),
        ],
        'support_session_minutes' => (int) env('ADMIN_SUPPORT_SESSION_MINUTES', 30),
    ],

    'security' => [
        // HSTS max-age for HTTPS responses; 0 disables the header.
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        // TLS-terminating proxies/load balancers (comma list or "*"), so HTTPS is detected for secure cookies, HSTS and URLs.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
    ],

    'identity' => [
        // Where the SPA lives; password-reset links point here.
        'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

        // SPA paths used in notification emails, the inbox and support-chat actions (Trello #26, #29).
        // Relative to frontend_url; {workspace} and {conversation} are filled in. Align with the frontend router.
        'frontend_paths' => [
            'consumer_benefits' => env('FRONTEND_PATH_CONSUMER_BENEFITS', '/app/benefits'),
            'consumer_wallet' => env('FRONTEND_PATH_CONSUMER_WALLET', '/app/wallet'),
            'consumer_vouchers' => env('FRONTEND_PATH_CONSUMER_VOUCHERS', '/app/vouchers'),
            'support_conversation' => env('FRONTEND_PATH_SUPPORT_CONVERSATION', '/app/support?conversation={conversation}'),
            'workspace_home' => env('FRONTEND_PATH_WORKSPACE_HOME', '/app/workspaces?workspace={workspace}'),
            'workspace_wallet' => env('FRONTEND_PATH_WORKSPACE_WALLET', '/app/workspaces/{workspace}/wallet'),
            'workspace_offers' => env('FRONTEND_PATH_WORKSPACE_OFFERS', '/app/workspaces/{workspace}/offers'),
            'workspace_banners' => env('FRONTEND_PATH_WORKSPACE_BANNERS', '/app/workspaces/{workspace}/banners'),
            'workspace_integrations' => env('FRONTEND_PATH_WORKSPACE_INTEGRATIONS', '/app/workspaces/{workspace}/integrations'),
            'admin_home' => env('FRONTEND_PATH_ADMIN_HOME', '/app/platform'),
        ],

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
    | CMS / homepage
    |--------------------------------------------------------------------------
    */

    'cms' => [
        // Extra https hosts allowed in homepage section hrefs (frontend + APP_URL are always included).
        'href_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CMS_HREF_HOSTS', 'localhost,127.0.0.1,shopbonus.nl,www.shopbonus.nl'))))),
        // Homepage AI suggestions (never auto-apply). Off by default.
        'ai_suggest_enabled' => (bool) env('CMS_AI_SUGGEST_ENABLED', false),
        'ai_api_key' => env('CMS_AI_API_KEY', env('OPENAI_API_KEY')),
        'ai_model' => env('CMS_AI_MODEL', 'gpt-4o-mini'),
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
        // Suggested starting values per discovery category (Trello #9). Suggestions only.
        'branch_defaults' => [
            'fashion' => [
                'discount_type' => 'percentage',
                'discount_value' => 1500, // 15%
                'min_order_minor' => 7500,
                'max_discount_minor' => 1500,
                'claim_validity_days' => 90,
                'redemption_validity_days' => 90,
                'note' => 'Fashion often supports a higher cashback percentage.',
            ],
            'electronics' => [
                'discount_type' => 'percentage',
                'discount_value' => 500, // 5%
                'min_order_minor' => 20000,
                'max_discount_minor' => 2500,
                'claim_validity_days' => 90,
                'redemption_validity_days' => 60,
                'note' => 'Electronics typically use a lower suggested percentage.',
            ],
            'home_living' => [
                'discount_type' => 'percentage',
                'discount_value' => 1000, // 10%
                'min_order_minor' => 10000,
                'max_discount_minor' => 1000,
                'claim_validity_days' => 90,
                'redemption_validity_days' => 90,
                'note' => 'Home & interior: configurable spend threshold and maximum reward.',
            ],
            'default' => [
                'discount_type' => 'percentage',
                'discount_value' => 1000,
                'min_order_minor' => 10000,
                'max_discount_minor' => 1000,
                'claim_validity_days' => 90,
                'redemption_validity_days' => 90,
                'note' => 'Balanced starting point for most webshops.',
            ],
        ],
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

    // Banner campaigns on the merchant's site (Trello #25, #24 §1–2).
    'banners' => [
        'attribution_days' => (int) env('BANNER_ATTRIBUTION_DAYS', 30),
        // Documented multi-banner priority when an order could match more than one signal.
        'attribution_priority' => ['click', 'voucher', 'view', 'unattributed'],
        'pairing_ttl_minutes' => 10,
        'editor_token_ttl_minutes' => 30,
        'events_per_batch' => 50,
        'event_retention_days' => 90,
        // User-Agent substrings treated as bots (case-insensitive); rejected at ingest.
        'bot_user_agents' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'BANNER_BOT_USER_AGENTS',
            'bot,crawler,spider,slurp,facebookexternalhit,preview,headlesschrome,phantomjs,selenium'
        ))))),
    ],

    // Merchant notifications (Trello #8 §6).
    'notifications' => [
        'low_inventory_threshold' => (int) env('NOTIFY_LOW_INVENTORY_THRESHOLD', 20),
        'expiry_warning_days' => 7,
    ],

    // Merchant funding of sponsored rewards (Trello #24 §4).
    'funding' => [
        // New sponsored vouchers need funding for reward + fee (card #24 "stop new rewards when unfunded").
        'required' => (bool) env('FUNDING_REQUIRED', true),
        // Percentage rewards: fee basis until OPEN-PERCENT-REWARD-FEE-BASIS is decided.
        'percentage_basis' => env('FUNDING_PERCENTAGE_BASIS', 'maximum_discount'),
        'min_top_up_minor' => 1000,
        'max_top_up_minor' => 1_000_000,
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
        // on_network_approval (owner-confirmed default, D-089): pending and not spendable
        // until the network approves. on_validated_attribution (card #23 instant credit)
        // remains a compatibility path; enabling it needs a new owner decision.
        'credit_timing' => env('AFFILIATE_CREDIT_TIMING', 'on_network_approval'),
        // Default consumer share of eligible commission (70% / ShopBonus 30%).
        'default_consumer_share_bps' => (int) env('AFFILIATE_DEFAULT_CONSUMER_SHARE_BPS', 7000),
        // Network used when programs cannot be compared reliably.
        'fallback_network' => env('AFFILIATE_FALLBACK_NETWORK', 'daisycon'),
        // Reference basket for comparing percentage against fixed commissions.
        'reference_basket_minor' => 5000,
        'selection_rule_version' => '2026-10-23.1',
        // A conversion must cite a click from this many days back.
        'attribution_window_days' => 30,
        'networks' => [
            'tradetracker' => ['enabled' => (bool) env('TRADETRACKER_API_ENABLED', false)],
            'daisycon' => [
                'base_url' => env('DAISYCON_API_BASE_URL', 'https://services.daisycon.com'),
                // Sandbox: https://services-sandbox.daisycon.com (15 req/min/IP).
            ],
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
