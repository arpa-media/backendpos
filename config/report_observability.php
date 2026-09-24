<?php

return [
    'enabled' => env('REPORT_OBSERVABILITY_ENABLED', true),
    'slow_request_ms' => (int) env('REPORT_OBSERVABILITY_SLOW_REQUEST_MS', 1500),
    'slow_query_ms' => (int) env('REPORT_OBSERVABILITY_SLOW_QUERY_MS', 250),
    'capture_query_limit' => (int) env('REPORT_OBSERVABILITY_CAPTURE_QUERY_LIMIT', 25),
    'explain_limit' => (int) env('REPORT_OBSERVABILITY_EXPLAIN_LIMIT', 3),
    'binding_char_limit' => (int) env('REPORT_OBSERVABILITY_BINDING_CHAR_LIMIT', 120),
    'trace_query_param' => env('REPORT_OBSERVABILITY_TRACE_QUERY_PARAM', '__trace'),
    'explain_query_param' => env('REPORT_OBSERVABILITY_EXPLAIN_QUERY_PARAM', '__explain'),
    'trace_header' => env('REPORT_OBSERVABILITY_TRACE_HEADER', 'X-Report-Trace'),
    'explain_header' => env('REPORT_OBSERVABILITY_EXPLAIN_HEADER', 'X-Report-Explain'),
    'header_prefix' => env('REPORT_OBSERVABILITY_HEADER_PREFIX', 'X-Report-'),
    'response_header_limit' => (int) env('REPORT_OBSERVABILITY_RESPONSE_HEADER_LIMIT', 5),
    'log_channel' => env('REPORT_OBSERVABILITY_LOG_CHANNEL', 'report_observability'),
    'persist_metrics' => env('REPORT_OBSERVABILITY_PERSIST_METRICS', true),
    'observe_path_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('REPORT_OBSERVABILITY_PATH_PREFIXES', 'api/v1/report,api/v1/reports,api/v1/finance,api/v1/owner-overview,api/v1/sales-collected,api/v1/operational/analytics,api/v1/cogs,api/v1/warehouse'))))),
];
