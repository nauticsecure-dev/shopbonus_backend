<?php

return [
    'email_enabled' => env('NOTIFICATION_EMAIL_ENABLED', false),
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    'ai_enabled' => env('NOTIFICATION_AI_ENABLED', false),
    'api_key' => env('NOTIFICATION_AI_API_KEY', env('OPENAI_API_KEY', '')),
    'model' => env('NOTIFICATION_AI_MODEL', ''),
    'daily_limit' => (int) env('NOTIFICATION_AI_DAILY_LIMIT', 10),
];
