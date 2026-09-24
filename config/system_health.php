<?php

return [
    'cache_seconds' => (int) env('SYSTEM_HEALTH_CACHE_SECONDS', 20),
    'metrics_retention_days' => (int) env('SYSTEM_HEALTH_METRICS_RETENTION_DAYS', 7),
    'metrics_window_hours' => (int) env('SYSTEM_HEALTH_METRICS_WINDOW_HOURS', 24),
    'metrics_max_rows' => (int) env('SYSTEM_HEALTH_METRICS_MAX_ROWS', 5000),

    'scheduler' => [
        'healthy_seconds' => (int) env('SYSTEM_HEALTH_SCHEDULER_HEALTHY_SECONDS', 180),
        'critical_seconds' => (int) env('SYSTEM_HEALTH_SCHEDULER_CRITICAL_SECONDS', 600),
    ],
    'worker' => [
        'healthy_seconds' => (int) env('SYSTEM_HEALTH_WORKER_HEALTHY_SECONDS', 600),
        'critical_seconds' => (int) env('SYSTEM_HEALTH_WORKER_CRITICAL_SECONDS', 1200),
    ],
    'database' => [
        'healthy_ms' => (float) env('SYSTEM_HEALTH_DB_HEALTHY_MS', 150),
        'critical_ms' => (float) env('SYSTEM_HEALTH_DB_CRITICAL_MS', 500),
    ],
    'queue' => [
        'healthy_depth' => (int) env('SYSTEM_HEALTH_QUEUE_HEALTHY_DEPTH', 10),
        'critical_depth' => (int) env('SYSTEM_HEALTH_QUEUE_CRITICAL_DEPTH', 50),
        'failed_jobs_warning' => (int) env('SYSTEM_HEALTH_FAILED_JOBS_WARNING', 1),
        'failed_jobs_critical' => (int) env('SYSTEM_HEALTH_FAILED_JOBS_CRITICAL', 4),
    ],
    'coverage' => [
        'healthy_percent' => (float) env('SYSTEM_HEALTH_COVERAGE_HEALTHY_PERCENT', 99.5),
        'critical_percent' => (float) env('SYSTEM_HEALTH_COVERAGE_CRITICAL_PERCENT', 95),
    ],
    'reports' => [
        'p95_healthy_ms' => (float) env('SYSTEM_HEALTH_REPORT_P95_HEALTHY_MS', 3000),
        'p95_critical_ms' => (float) env('SYSTEM_HEALTH_REPORT_P95_CRITICAL_MS', 6000),
    ],
];
