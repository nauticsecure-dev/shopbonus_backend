<?php

return [
    // "Improve with AI" drafts (Trello #33–#35). Off until the owner enables it.
    'ai_enabled' => env('CMS_AI_ENABLED', false),
    'api_key' => env('CMS_AI_API_KEY', env('OPENAI_API_KEY', '')),
    'model' => env('CMS_AI_MODEL', 'gpt-6.1-sol'),
    'daily_budget_micros' => (int) env('CMS_AI_DAILY_BUDGET_MICROS', 0),
    'pricing' => json_decode((string) env('CMS_AI_VERIFIED_PRICING', '{}'), true),
    'daily_limit' => (int) env('CMS_AI_DAILY_LIMIT', 20),
    'queue_connection' => env('CMS_AI_QUEUE_CONNECTION', 'database'),
    'queue' => 'cms-translations',
    'auto_translate' => env('CMS_AI_AUTO_TRANSLATE', true),
    'max_input_chars' => 24000,
    'max_output_tokens' => 4000,
    'media_queue_connection' => env('CMS_MEDIA_QUEUE_CONNECTION', 'database'),
    // Reviewed brand terms must occur verbatim in every matching output segment.
    'glossary' => ['ShopBonus', 'Answer24'],
];
