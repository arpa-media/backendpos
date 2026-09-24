<?php

return [
    'modules' => [[
        'key' => 'go-live',
        'code' => 'purchasing-go-live',
        'label' => 'Go-Live & Audit',
        'singular_label' => 'Go-Live Audit',
        'description' => 'Audit final PE01–PE06: attachment, Order→Execution, Invoice/AP/AR, Reimburse atomic posting, duplicate posting, Access Matrix, dan performance.',
        'path' => '/purchasing/go-live',
        'route_name' => 'purchasing-go-live',
        'sort_order' => 140,
        'permission' => 'purchasing.go_live',
        'document_prefix' => 'GL',
        'implementation_status' => 'WORKFLOW',
        'status_options' => ['PASSED', 'PASSED_WITH_WARNINGS', 'FAILED'],
    ]],
];
