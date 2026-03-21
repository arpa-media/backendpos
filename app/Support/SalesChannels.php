<?php

namespace App\Support;

final class SalesChannels
{
    public const DINE_IN = 'DINE_IN';
    public const TAKEAWAY = 'TAKEAWAY';
    public const DELIVERY = 'DELIVERY';

    /**
     * Virtual channel for a single transaction/receipt containing multiple channels.
     * Phase 1 Patch-6: allow DINE_IN + TAKEAWAY in one Sale.
     */
    public const MIXED = 'MIXED';

    public const ALL = [
        self::DINE_IN,
        self::TAKEAWAY,
        self::DELIVERY,
        self::MIXED,
    ];

    private function __construct()
    {
        // static only
    }
}
