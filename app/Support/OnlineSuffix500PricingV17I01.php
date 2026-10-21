<?php

namespace App\Support;

/** V17-I01: version 3, NEW DELIVERY checkouts. Legacy V1/V2 stay unchanged. */
final class OnlineSuffix500PricingV17I01
{
    public static function calculate(
        string $channel,
        array $items,
        int $subtotal,
        int $discount = 0,
        int $tax = 0,
        int $service = 0
    ): ?array {
        if (strtoupper(trim($channel)) !== SalesChannels::DELIVERY || !$items
            || $subtotal < 0 || $discount < 0 || $discount > $subtotal || $tax < 0 || $service < 0) {
            return null;
        }

        $actual = 0;
        $protected = 0;
        foreach ($items as $row) {
            if (!is_array($row) || strtoupper(trim((string) ($row['channel'] ?? $channel))) !== SalesChannels::DELIVERY) {
                return null;
            }
            $qty = $row['qty'] ?? null;
            $unit = $row['unit_price'] ?? $row['unit_price_snapshot'] ?? null;
            $line = $row['line_total'] ?? $row['line_total_snapshot'] ?? null;
            if (!is_numeric($qty) || !is_numeric($unit) || !is_numeric($line)
                || filter_var($qty, FILTER_VALIDATE_INT) === false
                || filter_var($unit, FILTER_VALIDATE_INT) === false
                || filter_var($line, FILTER_VALIDATE_INT) === false) {
                return null;
            }
            $qty = (int) $qty;
            $unit = (int) $unit;
            $line = (int) $line;
            if ($qty <= 0 || $unit < 0 || $line < 0 || $line !== $qty * $unit) return null;
            $actual += $line;
            if ($unit % 1000 === 500) $protected += $line;
        }
        if ($actual !== $subtotal || $protected <= 0) return null;

        $rounding = 0;
        $policy = 'EXEMPT_SUFFIX_500';
        if ($discount || $tax || $service) {
            $policy = 'NO_ROUNDING_AMBIGUOUS_COMPONENTS';
        } else {
            $other = $subtotal - $protected;
            $rounding = ($other > 0 && $other < 500) ? 0 : SaleRounding::calculateDelta($other);
        }
        return [
            'version' => 3,
            'kind' => 'ONLINE_SUFFIX_500',
            'protected_500_total' => $protected,
            'policy' => $policy,
            'rounding_total' => $rounding,
            'grand_total' => $subtotal - $discount + $tax + $service + $rounding,
        ];
    }
}
