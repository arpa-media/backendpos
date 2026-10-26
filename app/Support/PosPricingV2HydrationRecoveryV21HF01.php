<?php

namespace App\Support;

/**
 * Recognize the legacy Android V2 journal-hydration bug without repricing.
 *
 * The original offline checkout contained two V2 fields inside
 * offline_snapshot. Some shipped APK builds rebuilt that snapshot from their
 * journal and dropped BOTH fields, although the envelope still says V2.
 *
 * This helper only decides whether the original incoming payload has that
 * exact shape and complete immutable pricing evidence. It does NOT authorize
 * the sale: PosCheckoutService must still calculate the corresponding V2
 * contract and compare every checkout total before inserting anything.
 */
final class PosPricingV2HydrationRecoveryV21HF01
{
    public static function canRecover(array $payload): bool
    {
        if ((int) ($payload['pricing_rule_version'] ?? 0) !== 2
            || !in_array(($payload['pricing_rule_kind'] ?? null), ['SQUAD', 'ONLINE_500'], true)
            || trim((string) ($payload['client_sync_id'] ?? '')) === '') {
            return false;
        }

        $snapshot = $payload['offline_snapshot'] ?? null;
        if (!is_array($snapshot)
            || array_key_exists('pricing_rule_version', $snapshot)
            || array_key_exists('pricing_v2', $snapshot)
            || array_key_exists('pricing_v3', $snapshot)) {
            // A partial or contradictory V2 contract is NOT a hydration loss.
            return false;
        }

        foreach (['subtotal', 'discount_amount', 'tax_total', 'rounding_total',
            'grand_total', 'paid_total', 'change_total', 'tax_percent_snapshot'] as $field) {
            if (!array_key_exists($field, $snapshot)
                || filter_var($snapshot[$field], FILTER_VALIDATE_INT) === false) {
                return false;
            }
        }

        $subtotalSnapshot = (int) $snapshot['subtotal'];
        $discount = (int) $snapshot['discount_amount'];
        $tax = (int) $snapshot['tax_total'];
        $grand = (int) $snapshot['grand_total'];
        $paid = (int) $snapshot['paid_total'];
        $change = (int) $snapshot['change_total'];
        $taxPercent = (int) $snapshot['tax_percent_snapshot'];

        if ($subtotalSnapshot < 0 || $discount < 0 || $discount > $subtotalSnapshot
            || $tax < 0 || $grand < 0 || $paid < $grand
            || $change < 0 || $change !== $paid - $grand
            || $taxPercent < 0 || $taxPercent > 100) {
            return false;
        }

        $payment = $payload['payment'] ?? null;
        if (!is_array($payment)
            || trim((string) ($payment['payment_method_id'] ?? '')) === ''
            || filter_var($payment['amount'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $payment['amount'] !== $paid) {
            return false;
        }

        $kind = (string) $payload['pricing_rule_kind'];
        $channel = strtoupper(trim((string) ($payload['channel'] ?? '')));
        $isOnline = $kind === 'ONLINE_500';
        if ($isOnline ? $channel !== 'DELIVERY' : !in_array($channel, ['DINE_IN', 'TAKEAWAY', 'MIXED'], true)) {
            return false;
        }
        if ($isOnline && ($tax !== 0 || $taxPercent !== 0)) {
            return false;
        }

        $items = $payload['items'] ?? null;
        if (!is_array($items) || !$items || count($items) > 200) {
            return false;
        }

        $subtotal = 0;
        $hasExactly500Line = false;
        foreach ($items as $row) {
            if (!is_array($row)
                || trim((string) ($row['product_id'] ?? '')) === ''
                || trim((string) ($row['variant_id'] ?? '')) === '') {
                return false;
            }
            $itemChannel = strtoupper(trim((string) ($row['channel'] ?? $channel)));
            if ($isOnline ? $itemChannel !== 'DELIVERY' : !in_array($itemChannel, ['DINE_IN', 'TAKEAWAY'], true)) {
                return false;
            }
            if (!$isOnline && trim((string) ($row['category_kind_snapshot'] ?? '')) === '') {
                // The SQUAD calculator must not depend on today's category master.
                return false;
            }
            foreach (['qty', 'unit_price_snapshot', 'line_total_snapshot'] as $field) {
                if (!array_key_exists($field, $row)
                    || filter_var($row[$field], FILTER_VALIDATE_INT) === false) {
                    return false;
                }
            }
            $qty = (int) $row['qty'];
            $unit = (int) $row['unit_price_snapshot'];
            $line = (int) $row['line_total_snapshot'];
            if ($qty <= 0 || $qty > 999 || $unit < 0 || $line < 0
                || $unit > intdiv(PHP_INT_MAX, $qty)
                || $line !== $qty * $unit
                || $subtotal > PHP_INT_MAX - $line) {
                return false;
            }
            $subtotal += $line;
            if ($unit === 500) {
                $hasExactly500Line = true;
            }
        }
        if ($subtotal !== $subtotalSnapshot) {
            return false;
        }

        if ($isOnline) {
            // V2 protects an EXACT Rp500 unit price. A price ending in 500
            // (e.g. Rp24.500) is a V3 rule and must remain untouched.
            return $hasExactly500Line;
        }

        $packages = $snapshot['discounts_snapshot'] ?? null;
        if (!is_array($packages) || count($packages) !== 1
            || !is_array($packages[0] ?? null)
            || strtoupper((string) ($packages[0]['applies_to'] ?? '')) !== 'SQUAD'
            || trim((string) ($packages[0]['id'] ?? '')) === ''
            || empty($packages[0]['product_ids'])
            || !is_array($packages[0]['product_ids'])
            || trim((string) ($payload['discount_squad_nisj'] ?? '')) === '') {
            return false;
        }

        return true;
    }
}
