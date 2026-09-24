<?php

return [
    // Acceptance budgets are deliberately conservative for a shared POS/MySQL host.
    // I12 health checks can run in --strict mode to make these deployment gates.
    'aggregate_370_ms' => (int) env('REPORT_BUDGET_AGGREGATE_370_MS', 3000),
    'detail_first_page_ms' => (int) env('REPORT_BUDGET_DETAIL_PAGE_MS', 3000),
    'lightweight_read_ms' => (int) env('REPORT_BUDGET_LIGHT_READ_MS', 1000),
    'max_range_days' => (int) env('REPORT_BUDGET_MAX_RANGE_DAYS', 370),
    'sample_page_size' => (int) env('REPORT_BUDGET_SAMPLE_PAGE_SIZE', 100),
];
