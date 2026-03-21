<?php

namespace App\Support;

final class SaleStatuses
{
    public const PAID = 'PAID';
    public const VOID = 'VOID';

    public const ALL = [
        self::PAID,
        self::VOID,
    ];

    private function __construct()
    {
        // static only
    }
}
