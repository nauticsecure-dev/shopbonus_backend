<?php

return [
    'enabled' => (bool) env('BANNER_AI_ENABLED', false),
    'api_key' => env('OPENAI_API_KEY', ''),
    'model' => env('BANNER_AI_MODEL', ''),
    'daily_request_limit' => (int) env('BANNER_AI_DAILY_REQUEST_LIMIT', 10),
    'max_output_tokens' => (int) env('BANNER_AI_MAX_OUTPUT_TOKENS', 2000),
];
