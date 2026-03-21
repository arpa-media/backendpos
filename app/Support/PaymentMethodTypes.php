<?php

namespace App\Support;

final class PaymentMethodTypes
{
    public const CASH = 'CASH';
    public const CARD = 'CARD';
    public const QRIS = 'QRIS';
    public const BANK_TRANSFER = 'BANK_TRANSFER';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::CASH,
        self::CARD,
        self::QRIS,
        self::BANK_TRANSFER,
        self::OTHER,
    ];

    private function __construct()
    {
        // static only
    }
}
