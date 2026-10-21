<?php

namespace App\Support;

/** Strict opt-in Squad V2 amount calculator (integer Rupiah). */
final class SquadCalculationV15I08
{
    public static function calculate(int $eligibleSubtotal, int $baseDiscount, int $taxPercent, int $packagingSubtotal = 0): ?array
    {
        if ($eligibleSubtotal <= 0 || $packagingSubtotal < 0 || $taxPercent < 0 || $taxPercent > 100) {
            return null;
        }
        if ($baseDiscount !== intdiv($eligibleSubtotal * 20, 100)) {
            return null;
        }
        $withTax = static function (int $discount) use ($eligibleSubtotal, $taxPercent): int {
            $taxable = $eligibleSubtotal - $discount;
            return $taxable + intdiv($taxable * $taxPercent, 100);
        };
        $before = $withTax($baseDiscount);
        $target = intdiv($before, 1000) * 1000;
        if ($target < 1000 || $target === $before) {
            return null;
        }
        $low = $baseDiscount;
        $high = $eligibleSubtotal;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($withTax($mid) <= $target) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        $discount = $low;
        $taxable = $eligibleSubtotal - $discount;
        $tax = intdiv($taxable * $taxPercent, 100);
        $residual = $target - $withTax($discount);
        if ($residual < 0 || $residual > 1) {
            return null;
        }
        return [
            'version' => 2,
            'discount_amount' => $discount,
            'base_discount_amount' => $baseDiscount,
            'adjustment' => $discount - $baseDiscount,
            'tax_total' => $tax,
            'rounding_total' => $residual,
            'grand_total' => $packagingSubtotal + $target,
        ];
    }
}
