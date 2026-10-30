<?php

return [
    'enabled' => (bool) env('MERCHANT_ENRICHMENT_ENABLED', false),
    'api_key' => env('OPENAI_API_KEY', ''),
    'text_model' => env('MERCHANT_ENRICHMENT_TEXT_MODEL', ''),
    'image_model' => env('MERCHANT_ENRICHMENT_IMAGE_MODEL', ''),
    'daily_run_limit' => (int) env('MERCHANT_ENRICHMENT_DAILY_RUN_LIMIT', 50),
    'max_output_tokens' => (int) env('MERCHANT_ENRICHMENT_MAX_OUTPUT_TOKENS', 2000),
    'fetch_max_bytes' => (int) env('MERCHANT_ENRICHMENT_FETCH_MAX_BYTES', 262144),
    'auto_publish' => (bool) env('MERCHANT_ENRICHMENT_AUTO_PUBLISH', false),
    'stale_days' => (int) env('MERCHANT_ENRICHMENT_STALE_DAYS', 90),
];
