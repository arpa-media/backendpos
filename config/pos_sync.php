<?php

return [
    'seeders' => [
        'enable_demo_users' => env('POS_ENABLE_DEMO_USERS', false),
        'enable_demo_outlets' => env('POS_ENABLE_DEMO_OUTLETS', false),
        'seed_master_data' => env('POS_SEED_MASTER_DATA', true),
    ],

    'roles' => [
        'protected' => array_values(array_filter(array_map('trim', explode(',', (string) env('POS_PROTECTED_ROLE_NAMES', 'admin'))))),
        'sync_assignments' => env('POS_SYNC_ASSIGN_DEFAULT_ROLES', true),
        'classification_map' => [
            'squad' => 'cashier',
            'management' => 'manager',
            'warehouse' => 'warehouse',
            'legacy' => 'cashier',
        ],
    ],

    'auth' => [
        'require_hr_assignment' => env('POS_AUTH_REQUIRE_HR_ASSIGNMENT', false),
    ],

    'legacy_bridge' => [
        'sync_on_import' => env('POS_SYNC_LEGACY_USER_OUTLET_BRIDGE', true),
        'mirror_squad_assignment_outlet' => env('POS_MIRROR_SQUAD_ASSIGNMENT_OUTLET', true),
        'keep_non_squad_outlet_id' => env('POS_KEEP_NON_SQUAD_LEGACY_OUTLET_ID', false),
    ],

    'manual_assignment_overrides' => [
        'enabled' => env('POS_ENABLE_MANUAL_ASSIGNMENT_OVERRIDES', true),
        'outlet' => [
            'code' => env('POS_MANUAL_ASSIGNMENT_OUTLET_CODE', 'KTA'),
            'name' => env('POS_MANUAL_ASSIGNMENT_OUTLET_NAME', 'Kuta'),
            'type' => env('POS_MANUAL_ASSIGNMENT_OUTLET_TYPE', 'outlet'),
            'timezone' => env('POS_MANUAL_ASSIGNMENT_OUTLET_TIMEZONE', 'Asia/Jakarta'),
            'legacy_lookup_codes' => ['OUTLET-003'],
            'legacy_lookup_names' => ['Outlet Cabang (Demo)'],
        ],
        'records' => [
            [
                'nisj' => '10012501001',
                'role_title' => 'squad',
                'status' => 'active',
            ],
            [
                'nisj' => '10012501002',
                'role_title' => 'squad',
                'status' => 'active',
            ],
        ],
    ],
];
