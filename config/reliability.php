<?php

return [
    'enabled' => (bool) env('API_MONITORING_ENABLED', false),
    'sample_limit' => (int) env('API_MONITORING_SAMPLE_LIMIT', 50000),
    'retention_hours' => (int) env('API_MONITORING_RETENTION_HOURS', 24),
    'minimum_samples' => (int) env('API_MONITORING_MINIMUM_SAMPLES', 20),
    'error_percent' => (float) env('API_MONITORING_ERROR_PERCENT', 2),
    'p95_ms' => (int) env('API_MONITORING_P95_MS', 1000),
    'slow_query_ms' => (int) env('API_MONITORING_SLOW_QUERY_MS', 100),
];
