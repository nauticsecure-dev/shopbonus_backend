<?php

return [
    // GPT failure analysis for findings (Trello #37). Off until the owner enables it.
    'ai_enabled' => env('QA_AI_ENABLED', false),
    'api_key' => env('QA_AI_API_KEY', env('OPENAI_API_KEY', '')),
    'model' => env('QA_AI_MODEL', ''),
    'daily_limit' => (int) env('QA_AI_DAILY_LIMIT', 50),

    // Deployed commit, set by the deploy (used by discovery and probe runs).
    'release_sha' => env('APP_RELEASE_SHA', 'unknown'),

    // Imported reports and evidence.
    'max_report_kb' => (int) env('QA_MAX_REPORT_KB', 10240),
    'max_evidence_kb' => (int) env('QA_MAX_EVIDENCE_KB', 10240),
    'evidence_retention_days' => (int) env('QA_EVIDENCE_RETENTION_DAYS', 365),

    // Evidence older than this, or older than the endpoint's last change, is STALE.
    'stale_after_days' => (int) env('QA_STALE_AFTER_DAYS', 7),

    // Read-only production probe (every 5 minutes); alerts after this many ms.
    'probe_enabled' => env('QA_PROBE_ENABLED', false),
    'probe_slow_ms' => (int) env('QA_PROBE_SLOW_MS', 1000),
];
