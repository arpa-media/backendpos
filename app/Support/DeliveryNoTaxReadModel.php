<?php

namespace App\Support;

use App\Support\PaymentMethodTypes;
use App\Support\SaleRounding;
use App\Support\SalesChannels;

final class DeliveryNoTaxReadModel
{
    public static function isDeliveryChannel(?string $channel): bool
    {
        return strtoupper(trim((string) $channel)) === SalesChannels::DELIVERY;
    }

    public static function normalizeSaleArray(array $sale): array
    {
        if (!self::isDeliveryChannel($sale['channel'] ?? null)) {
            return $sale;
        }

        $subtotal = max(0, (int) ($sale['subtotal'] ?? 0));
        $discountTotal = max(0, (int) ($sale['discount_total'] ?? $sale['discount_amount'] ?? 0));
        $serviceChargeTotal = max(0, (int) ($sale['service_charge_total'] ?? 0));

        $beforeRounding = max(0, $subtotal - $discountTotal) + $serviceChargeTotal;
        $roundingSnapshot = SaleRounding::apply($beforeRounding);
        $roundingTotal = (int) ($roundingSnapshot['rounding_total'] ?? 0);
        $grandTotal = (int) ($roundingSnapshot['after_rounding'] ?? 0);

        $oldPaidTotal = max(0, (int) ($sale['paid_total'] ?? 0));
        $oldGrandTotal = max(0, (int) ($sale['grand_total'] ?? 0));
        $paymentMethodType = strtoupper(trim((string) ($sale['payment_method_type'] ?? '')));
        $syncPaymentAmount = $paymentMethodType !== PaymentMethodTypes::CASH || $oldPaidTotal === $oldGrandTotal;

        $paidTotal = $syncPaymentAmount ? $grandTotal : $oldPaidTotal;
        $changeTotal = $syncPaymentAmount ? 0 : max(0, $paidTotal - $grandTotal);

        $sale['tax_id'] = null;
        $sale['tax_name'] = (string) ($sale['tax_name'] ?? $sale['tax_name_snapshot'] ?? 'Tax');
        $sale['tax_name_snapshot'] = $sale['tax_name'];
        $sale['tax_percent'] = 0;
        $sale['tax_percent_snapshot'] = 0;
        $sale['tax_total'] = 0;
        $sale['service_charge_total'] = $serviceChargeTotal;
        $sale['rounding_total'] = $roundingTotal;
        $sale['total_before_rounding'] = max(0, $grandTotal - $roundingTotal);
        $sale['grand_total'] = $grandTotal;
        $sale['paid_total'] = $paidTotal;
        $sale['change_total'] = $changeTotal;

        return $sale;
    }

    public static function sqlTaxTotal(string $alias = 's'): string
    {
        return 'CASE WHEN ' . self::sqlIsDelivery($alias) . ' THEN 0 ELSE COALESCE(' . $alias . '.tax_total, 0) END';
    }

    public static function sqlTaxPercent(string $alias = 's'): string
    {
        return 'CASE WHEN ' . self::sqlIsDelivery($alias) . ' THEN 0 ELSE COALESCE(' . $alias . '.tax_percent_snapshot, 0) END';
    }

    public static function sqlTaxName(string $alias = 's'): string
    {
        return "CASE WHEN " . self::sqlIsDelivery($alias) . " THEN 'Tax' ELSE COALESCE(" . $alias . ".tax_name_snapshot, 'Tax') END";
    }

    public static function sqlRoundingTotal(string $alias = 's'): string
    {
        return 'CASE WHEN ' . self::sqlIsDelivery($alias) . ' THEN ' . self::sqlDeliveryRoundingTotal($alias) . ' ELSE COALESCE(' . $alias . '.rounding_total, 0) END';
    }

    public static function sqlGrandTotal(string $alias = 's'): string
    {
        return 'CASE WHEN ' . self::sqlIsDelivery($alias) . ' THEN ' . self::sqlDeliveryGrandTotal($alias) . ' ELSE COALESCE(' . $alias . '.grand_total, 0) END';
    }

    public static function sqlNetSales(string $alias = 's'): string
    {
        return 'GREATEST(COALESCE(' . $alias . '.subtotal, 0) - COALESCE(' . $alias . '.discount_total, 0), 0)';
    }

    private static function sqlIsDelivery(string $alias): string
    {
        return "UPPER(COALESCE(" . $alias . ".channel, '')) = '" . SalesChannels::DELIVERY . "'";
    }

    private static function sqlDeliveryBaseAmount(string $alias): string
    {
        return '(' . self::sqlNetSales($alias) . ' + COALESCE(' . $alias . '.service_charge_total, 0))';
    }

    private static function sqlDeliveryRoundingTotal(string $alias): string
    {
        $base = self::sqlDeliveryBaseAmount($alias);
        $remainder = 'MOD(' . $base . ', ' . SaleRounding::BASE . ')';
        $half = (int) ceil(SaleRounding::BASE / 2);

        return '(CASE '
            . 'WHEN ' . $remainder . ' = 0 THEN 0 '
            . 'WHEN ' . $remainder . ' >= ' . $half . ' THEN ' . SaleRounding::BASE . ' - ' . $remainder . ' '
            . 'ELSE -1 * ' . $remainder . ' '
            . 'END)';
    }

    private static function sqlDeliveryGrandTotal(string $alias): string
    {
        $base = self::sqlDeliveryBaseAmount($alias);
        $rounding = self::sqlDeliveryRoundingTotal($alias);

        return 'GREATEST(' . $base . ' + ' . $rounding . ', 0)';
    }
}
