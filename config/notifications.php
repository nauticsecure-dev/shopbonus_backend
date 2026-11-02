<?php

return [
    'workspace_support_path' => env('NOTIFICATION_WORKSPACE_SUPPORT_PATH', '/dashboard/support'),
    // Email links bypass the SPA resolver; keep these aligned with Next.js pages.
    'action_paths' => [
        '/app/benefits' => env('NOTIFICATION_BENEFITS_PATH', '/mijn-voordelen'),
        '/app/workspaces' => env('NOTIFICATION_WORKSPACE_PATH', '/dashboard'),
        '/app/platform' => env('NOTIFICATION_PLATFORM_PATH', '/admin'),
        '/app/support' => env('NOTIFICATION_SUPPORT_PATH', '/app/support'),
    ],
    'email_enabled' => env('NOTIFICATION_EMAIL_ENABLED', false),
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    'ai_enabled' => env('NOTIFICATION_AI_ENABLED', false),
    'api_key' => env('NOTIFICATION_AI_API_KEY', env('OPENAI_API_KEY', '')),
    'model' => env('NOTIFICATION_AI_MODEL', ''),
    'daily_limit' => (int) env('NOTIFICATION_AI_DAILY_LIMIT', 10),
];
