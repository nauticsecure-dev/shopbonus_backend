<?php

return [
    // Deployment-owned rates in EUR per million tokens, keyed by exact model ID.
    // Leave empty until prices/exchange conversion are verified; no invented default prices.
    'verified_eur_rates' => [],
    'enabled' => env('SUPPORT_AI_ENABLED', false),
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('SUPPORT_AI_MODEL', ''),
    'daily_request_limit' => (int) env('SUPPORT_AI_DAILY_REQUEST_LIMIT', 100),
    'user_daily_request_limit' => (int) env('SUPPORT_AI_USER_DAILY_REQUEST_LIMIT', 20),
    'max_output_tokens' => (int) env('SUPPORT_AI_MAX_OUTPUT_TOKENS', 1000),
    'timeout_seconds' => (int) env('SUPPORT_AI_TIMEOUT_SECONDS', 20),
    'daily_cost_limit_eur' => env('SUPPORT_AI_DAILY_COST_LIMIT_EUR', '20'),
    'contract_ttl_hours' => (int) env('SUPPORT_AI_CONTRACT_TTL_HOURS', 24),
    // Exact configured model's verified context and worst-case billing rates, not estimates.
    'contract' => [
        'context_tokens' => (int) env('SUPPORT_AI_CONTEXT_TOKENS', 0),
        'output_tokens' => (int) env('SUPPORT_AI_CONTRACT_OUTPUT_TOKENS', 0),
        'input_micro_eur_per_token' => (int) env('SUPPORT_AI_INPUT_MICRO_EUR_PER_TOKEN', 0),
        'output_micro_eur_per_token' => (int) env('SUPPORT_AI_OUTPUT_MICRO_EUR_PER_TOKEN', 0),
        'pricing_reference' => env('SUPPORT_AI_PRICING_REFERENCE', ''),
    ],
    // Optional second model, same server-held API key. Requires its own successful canary.
    'fallback_model' => env('SUPPORT_AI_FALLBACK_MODEL', ''),
    'fallback_contract' => [
        'context_tokens' => (int) env('SUPPORT_AI_FALLBACK_CONTEXT_TOKENS', 0),
        'output_tokens' => (int) env('SUPPORT_AI_FALLBACK_OUTPUT_TOKENS', 0),
        'input_micro_eur_per_token' => (int) env('SUPPORT_AI_FALLBACK_INPUT_MICRO_EUR_PER_TOKEN', 0),
        'output_micro_eur_per_token' => (int) env('SUPPORT_AI_FALLBACK_OUTPUT_MICRO_EUR_PER_TOKEN', 0),
        'pricing_reference' => env('SUPPORT_AI_FALLBACK_PRICING_REFERENCE', ''),
    ],
    'retention_days' => (int) env('SUPPORT_AI_RETENTION_DAYS', 30),
    // Operator-entered model prices (EUR per million tokens) for the cost ESTIMATE in operations.
    // Leave empty when unknown: no price is ever assumed. Billed cost stays at the provider.
    'price_eur_per_million_input' => env('SUPPORT_AI_PRICE_EUR_PER_MILLION_INPUT'),
    'price_eur_per_million_output' => env('SUPPORT_AI_PRICE_EUR_PER_MILLION_OUTPUT'),
];
