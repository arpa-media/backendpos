<?php

return [
    'token_name' => env('POS_TOKEN_NAME', 'pos-api'),
    'token_expire_minutes' => env('POS_TOKEN_EXPIRE_MINUTES', null),

    'roles' => [
        'admin',
        'cashier',
    ],

    'seed_admin' => [
        'name' => env('POS_SEED_ADMIN_NAME', 'Admin'),
        // NISJ (Nomor Induk Squad Jaya) untuk login
        'nisj' => env('POS_SEED_ADMIN_NISJ', '10012501000'),
        'email' => env('POS_SEED_ADMIN_EMAIL', 'admin@tokokopijaya.com'),
        'password' => env('POS_SEED_ADMIN_PASSWORD', 'password123'),
    ],

    'seed_cashier' => [
        'name' => env('POS_SEED_CASHIER_NAME', 'Cashier POS'),
        // NISJ (Nomor Induk Squad Jaya) untuk login
        'nisj' => env('POS_SEED_CASHIER_NISJ', '10012501001'),
        'email' => env('POS_SEED_CASHIER_EMAIL', 'cashier@pos.test'),
        'password' => env('POS_SEED_CASHIER_PASSWORD', 'password123'),
    ],

    'seed_outlet' => [
        'name' => env('POS_SEED_OUTLET_NAME', 'Outlet_Utama'),
        'address' => env('POS_SEED_OUTLET_ADDRESS', 'Jl.ContohNo.1'),
        'phone' => env('POS_SEED_OUTLET_PHONE', '0800000000'),
        'timezone' => env('POS_SEED_OUTLET_TIMEZONE', 'Asia/Jakarta'),
    ],
];
