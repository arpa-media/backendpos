<?php

namespace App\Support;

/**
 * Accept a specifically identifiable V17 client hydration defect:
 * the persisted V3 identity survives at the envelope level, while
 * frontend/stores/offlineSync.js dropped BOTH pricing_v3 and
 * offline_snapshot.pricing_rule_version while rehydrating a cached sale.
 *
 * Never infer or change prices, discounts, rounding, or totals here. The
 * checkout's V3 server calculation and strict snapshot-total comparisons
 * still run before any sale is written.
 */
final class OnlineSuffix500HydrationRecoveryV17HF03
{
    public static function canRecover(array $payload, array $snapshot, array $items, string $channel): bool
    {
        if ((int) ($payload['pricing_rule_version'] ?? 0) !== 3
            || (string) ($payload['pricing_rule_kind'] ?? '') !== 'ONLINE_SUFFIX_500'
            || strtoupper(trim($channel)) !== 'DELIVERY'
            || trim((string) ($payload['client_sync_id'] ?? '')) === '') {
            return false;
        }

        // Recover ONLY the exact pair of missing fields caused by V17's
        // rehydration. Partial/contradictory contracts still fail closed.
        if (array_key_exists('pricing_rule_version', $snapshot)
            || array_key_exists('pricing_v3', $snapshot)
            || array_key_exists('pricing_v2', $snapshot)) {
            return false;
        }

        foreach (['subtotal', 'discount_amount', 'tax_total', 'rounding_total', 'grand_total', 'paid_total'] as $field) {
            if (!array_key_exists($field, $snapshot)
                || filter_var($snapshot[$field], FILTER_VALIDATE_INT) === false) {
                return false;
            }
        }
        if ((int) $snapshot['subtotal'] < 0
            || (int) $snapshot['tax_total'] !== 0
            || (int) $snapshot['grand_total'] < 0
            || (int) $snapshot['paid_total'] < 0) {
            return false;
        }

        if (!$items || count($items) > 200) return false;
        $subtotal = 0;
        $protected = 0;
        foreach ($items as $row) {
            if (!is_array($row)
                || strtoupper(trim((string) ($row['channel'] ?? $channel))) !== 'DELIVERY') {
                return false;
            }
            // No repricing from today's product master: every line must carry
            // its original price and total in the immutable queue payload.
            foreach (['qty', 'unit_price_snapshot', 'line_total_snapshot'] as $field) {
                if (!array_key_exists($field, $row)
                    || filter_var($row[$field], FILTER_VALIDATE_INT) === false) {
                    return false;
                }
            }
            $qty = (int) $row['qty'];
            $unit = (int) $row['unit_price_snapshot'];
            $total = (int) $row['line_total_snapshot'];
            if ($qty <= 0 || $unit < 0 || $total < 0 || $unit > intdiv(PHP_INT_MAX, $qty)
                || $unit * $qty !== $total) return false;
            if ($subtotal > PHP_INT_MAX - $total) return false;
            $subtotal += $total;
            if ($unit % 1000 === 500) $protected += $total;
        }

        return $protected > 0 && $subtotal === (int) $snapshot['subtotal'];
    }
}
