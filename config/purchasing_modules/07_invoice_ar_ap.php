<?php

return ['modules' => [
    [
        'key' => 'account-payables',
        'code' => 'purchasing-account-payables',
        'label' => 'Account Payable',
        'singular_label' => 'Account Payable',
        'path' => '/purchasing/account-payables',
        'route_name' => 'purchasing-account-payables',
        'sort_order' => 50,
        'permission' => 'purchasing.account_payable',
        'document_prefix' => 'AP',
        'implementation_status' => 'WORKFLOW',
        'status_options' => ['ISSUED', 'PARTIALLY_PAID', 'PAID'],
    ],
    [
        'key' => 'account-receivables',
        'code' => 'purchasing-account-receivables',
        'label' => 'Account Receivable',
        'singular_label' => 'Account Receivable',
        'path' => '/purchasing/account-receivables',
        'route_name' => 'purchasing-account-receivables',
        'sort_order' => 60,
        'permission' => 'purchasing.account_receivable',
        'document_prefix' => 'AR',
        'implementation_status' => 'WORKFLOW',
        'status_options' => ['ISSUED', 'PARTIALLY_PAID', 'PAID'],
    ],
]];
