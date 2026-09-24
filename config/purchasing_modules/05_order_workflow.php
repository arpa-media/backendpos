<?php

return [
    'modules' => [[
        'key' => 'order-management',
        'code' => 'purchasing-order-management',
        'label' => 'Order Management',
        'singular_label' => 'Order',
        'description' => 'Purchase Order, Service Order, Reimburse Order, dan Aktiva Purchase Order dalam satu workspace bertab.',
        'path' => '/purchasing/order-management',
        'route_name' => 'purchasing-order-management',
        'sort_order' => 30,
        'permission' => 'purchasing.order_management',
        'document_prefix' => 'ORD',
        'implementation_status' => 'WORKFLOW',
        'status_options' => [
            'DRAFT',
            'AWAITING_FINANCE_APPROVAL_1',
            'AWAITING_FINANCE_APPROVAL_2',
            'APPROVED',
            'REJECTED',
            'PARTIALLY_EXECUTED',
            'EXECUTED',
            'CANCELLED',
        ],
    ]],
];
