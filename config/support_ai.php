<?php

return [
    'enabled' => env('SUPPORT_AI_ENABLED', false),
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('SUPPORT_AI_MODEL', ''),
    'daily_request_limit' => (int) env('SUPPORT_AI_DAILY_REQUEST_LIMIT', 100),
    'user_daily_request_limit' => (int) env('SUPPORT_AI_USER_DAILY_REQUEST_LIMIT', 20),
    'max_output_tokens' => (int) env('SUPPORT_AI_MAX_OUTPUT_TOKENS', 1000),
    'timeout_seconds' => (int) env('SUPPORT_AI_TIMEOUT_SECONDS', 20),
    'retention_days' => (int) env('SUPPORT_AI_RETENTION_DAYS', 30),
];
