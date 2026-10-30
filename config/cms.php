<?php

return [
    // "Improve with AI" drafts (Trello #33–#35). Off until the owner enables it.
    'ai_enabled' => env('CMS_AI_ENABLED', false),
    'api_key' => env('CMS_AI_API_KEY', env('OPENAI_API_KEY', '')),
    'model' => env('CMS_AI_MODEL', ''),
    'daily_limit' => (int) env('CMS_AI_DAILY_LIMIT', 20),
];
