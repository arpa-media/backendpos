<?php

return [
    'modules' => [
        [
            'key' => 'fund-requests',
            'code' => 'purchasing-fund-requests',
            'label' => 'Fund Requests',
            'singular_label' => 'Fund Request',
            'description' => 'Fund Request untuk kebutuhan Purchase, Service, Reimburse, Asset, dan Stock; approved request otomatis menghasilkan Order canonical sesuai tipe.',
            'path' => '/purchasing/fund-requests',
            'route_name' => 'purchasing-fund-requests',
            'sort_order' => 20,
            'permission' => 'purchasing.fund_request',
            'document_prefix' => 'REQ',
            'implementation_status' => 'CORE',
            'status_options' => [
                'DRAFT',
                'AWAITING_REQUEST_APPROVAL',
                'REQUEST_APPROVED',
                'REQUEST_REJECTED',
            ],
        ],
    ],
];
