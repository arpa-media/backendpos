<?php

namespace App\Support;

/** Versioned online rounding: preserve Rp500-priced DELIVERY products. */
final class OnlineRoundingV15I09
{
    public static function calculate(string $channel, array $items, int $subtotal, int $discountAmount = 0, int $taxTotal = 0, int $serviceChargeTotal = 0): ?array
    {
        if (strtoupper(trim($channel)) !== SalesChannels::DELIVERY || count($items) === 0 || $discountAmount !== 0 || $taxTotal !== 0 || $serviceChargeTotal !== 0 || $subtotal < 0) {
            return null;
        }
        $actualSubtotal = 0;
        $protectedTotal = 0;
        foreach ($items as $row) {
            if (!is_array($row) || strtoupper(trim((string) ($row['channel'] ?? $channel))) !== SalesChannels::DELIVERY) {
                return null;
            }
            $qty = (int) ($row['qty'] ?? 0);
            $price = (int) ($row['unit_price'] ?? $row['unit_price_snapshot'] ?? -1);
            $line = (int) ($row['line_total'] ?? $row['line_total_snapshot'] ?? -1);
            if ($qty <= 0 || $price < 0 || $line < 0 || $line !== $price * $qty) {
                return null;
            }
            $actualSubtotal += $line;
            if ($price === 500) {
                $protectedTotal += $line;
            }
        }
        if ($actualSubtotal !== $subtotal || $protectedTotal <= 0 || $protectedTotal > $subtotal) {
            return null;
        }
        $other = $subtotal - $protectedTotal;
        // Do not round away a positive other-line amount below Rp500.
        $delta = ($other > 0 && $other < 500) ? 0 : SaleRounding::calculateDelta($other);
        return [
            'version' => 2,
            'protected_500_total' => $protectedTotal,
            'before_rounding' => $subtotal,
            'rounding_total' => $delta,
            'grand_total' => $subtotal + $delta,
        ];
    }
}
