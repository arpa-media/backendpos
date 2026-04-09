<?php

namespace App\Support;

final class SaleAmountBreakdown
{
    public static function canonical(int $subtotal, int $discountTotal, int $taxPercent, int $roundingTotal = 0, int $serviceChargeTotal = 0): array
    {
        $subtotal = max(0, $subtotal);
        $discountTotal = max(0, min($subtotal, $discountTotal));
        $taxPercent = max(0, min(100, $taxPercent));
        $roundingTotal = (int) $roundingTotal;
        $serviceChargeTotal = max(0, (int) $serviceChargeTotal);

        $taxableBase = max(0, $subtotal - $discountTotal);
        $taxTotal = (int) floor(($taxableBase * $taxPercent) / 100);
        $beforeRounding = max(0, $taxableBase + $taxTotal + $serviceChargeTotal);
        $grandTotal = max(0, $beforeRounding + $roundingTotal);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_percent' => $taxPercent,
            'taxable_base' => $taxableBase,
            'tax_total' => $taxTotal,
            'service_charge_total' => $serviceChargeTotal,
            'before_rounding' => $beforeRounding,
            'rounding_total' => $roundingTotal,
            'grand_total' => $grandTotal,
        ];
    }

    public static function legacyTaxBeforeDiscount(int $subtotal, int $taxPercent): int
    {
        $subtotal = max(0, $subtotal);
        $taxPercent = max(0, min(100, $taxPercent));

        return (int) floor(($subtotal * $taxPercent) / 100);
    }

    public static function detectDiscountTaxMismatch(int $subtotal, int $discountTotal, int $taxPercent, int $recordedTaxTotal): array
    {
        $canonical = self::canonical($subtotal, $discountTotal, $taxPercent);
        $legacyTaxTotal = self::legacyTaxBeforeDiscount($subtotal, $taxPercent);
        $recordedTaxTotal = max(0, (int) $recordedTaxTotal);

        return [
            'recorded_tax_total' => $recordedTaxTotal,
            'canonical_tax_total' => (int) $canonical['tax_total'],
            'legacy_tax_total' => $legacyTaxTotal,
            'matches_canonical' => $recordedTaxTotal === (int) $canonical['tax_total'],
            'matches_legacy' => $recordedTaxTotal === $legacyTaxTotal && $legacyTaxTotal !== (int) $canonical['tax_total'],
            'needs_repair' => $recordedTaxTotal !== (int) $canonical['tax_total'],
        ];
    }

    public static function resolvePaymentSnapshot(string $paymentMethodType, int $grandTotal, ?int $recordedPaidTotal = null, ?int $recordedChangeTotal = null): array
    {
        $paymentMethodType = strtoupper(trim($paymentMethodType));
        $grandTotal = max(0, $grandTotal);

        if ($paymentMethodType !== PaymentMethodTypes::CASH) {
            return [
                'paid_total' => $grandTotal,
                'change_total' => 0,
            ];
        }

        $paidTotal = max($grandTotal, (int) ($recordedPaidTotal ?? 0));
        $computedChange = max(0, $paidTotal - $grandTotal);
        $recordedChange = max(0, (int) ($recordedChangeTotal ?? 0));

        return [
            'paid_total' => $paidTotal,
            'change_total' => max($computedChange, $recordedChange),
        ];
    }
}
