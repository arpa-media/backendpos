<?php

namespace App\Support;

class SaleRounding
{
    public const BASE = 1000;

    public static function shouldApplyForPaymentType(?string $paymentType): bool
    {
        $type = strtoupper(trim((string) $paymentType));

        if ($type === '') {
            return true;
        }

        return $type === PaymentMethodTypes::CASH;
    }

    public static function calculateDelta(int $amount, int $base = self::BASE): int
    {
        $amount = max(0, (int) $amount);
        $base = max(1, (int) $base);

        $remainder = $amount % $base;
        if ($remainder === 0) {
            return 0;
        }

        $half = (int) ceil($base / 2);

        return $remainder >= $half
            ? ($base - $remainder)
            : (-1 * $remainder);
    }

    public static function apply(int $amount, int $base = self::BASE): array
    {
        $before = max(0, (int) $amount);
        $rounding = self::calculateDelta($before, $base);

        return [
            'before_rounding' => $before,
            'rounding_total' => $rounding,
            'after_rounding' => max(0, $before + $rounding),
        ];
    }

    public static function applyForPaymentType(int $amount, ?string $paymentType, int $base = self::BASE): array
    {
        $before = max(0, (int) $amount);

        if (!self::shouldApplyForPaymentType($paymentType)) {
            return [
                'before_rounding' => $before,
                'rounding_total' => 0,
                'after_rounding' => $before,
            ];
        }

        return self::apply($before, $base);
    }
}
