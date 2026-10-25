<?php

return [
    'enabled' => (bool) env('ADMIN_DIAGNOSTICS_ENABLED', false),
    'provider' => 'openai',
    'api_key' => env('OPENAI_API_KEY', ''),
    'model' => env('ADMIN_DIAGNOSTICS_MODEL', ''),
    'daily_request_limit' => (int) env('ADMIN_DIAGNOSTICS_DAILY_REQUEST_LIMIT', 10),
    'max_output_tokens' => (int) env('ADMIN_DIAGNOSTICS_MAX_OUTPUT_TOKENS', 1500),
];
