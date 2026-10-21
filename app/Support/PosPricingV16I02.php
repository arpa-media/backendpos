<?php

namespace App\Support;

/** Pure integer Rupiah calculations. Version 2 is explicit opt-in only. */
final class PosPricingV16I02
{
    private static function packaging(array $r): bool
    {
        $kind = strtoupper(trim((string) ($r['category_kind_snapshot'] ?? $r['category_kind'] ?? '')));
        $slug = strtolower(trim((string) ($r['category_slug_snapshot'] ?? $r['category_slug'] ?? '')));
        $name = strtoupper(trim((string) ($r['category_name_snapshot'] ?? $r['category_name'] ?? '')));
        return $kind === 'PACKAGING' || $slug === 'packaging' || $name === 'PACKAGING';
    }

    private static function lines(array $items, ?string $channel = null): ?array
    {
        if (!$items) return null;
        $lines = [];
        foreach ($items as $r) {
            if (!is_array($r)) return null;
            $qty = (int) ($r['qty'] ?? 0);
            $unit = (int) ($r['unit_price'] ?? $r['unit_price_snapshot'] ?? -1);
            $line = (int) ($r['line_total'] ?? $r['line_total_snapshot'] ?? ($qty * $unit));
            if ($qty <= 0 || $unit < 0 || $line < 0 || $line !== $qty * $unit) return null;
            if ($channel !== null && strtoupper(trim((string) ($r['channel'] ?? $channel))) !== $channel) return null;
            $lines[] = ['qty' => $qty, 'unit' => $unit, 'line' => $line,
                'product' => trim((string) ($r['product_id'] ?? '')), 'packaging' => self::packaging($r)];
        }
        return $lines;
    }

    public static function squad(array $items, array $productIds, string $nisj, int $taxPercent, string $channel): ?array
    {
        if (trim($nisj) === '' || $channel === 'DELIVERY' || $taxPercent < 0 || $taxPercent > 100 || !$productIds) return null;
        $ids = array_fill_keys(array_map('strval', $productIds), true);
        $lines = self::lines($items);
        if ($lines === null) return null;
        $subtotal = 0; $nonPackaging = 0; $eligible = 0; $targets = []; $targetEligible = 0;
        foreach ($lines as $r) {
            $subtotal += $r['line'];
            if ($r['packaging']) continue;
            $nonPackaging += $r['line'];
            if (!isset($ids[$r['product']])) continue;
            $eligible += $r['line'];
            $baseUnit = intdiv($r['unit'] * 20, 100);
            $net = $r['unit'] - $baseUnit;
            $gross = $net + intdiv($net * $taxPercent, 100);
            $targetUnit = intdiv($gross, 1000) * 1000;
            if ($gross > 0 && $targetUnit < 1000) return null;
            $targetEligible += $targetUnit * $r['qty'];
            $targets[] = ['product_id' => $r['product'], 'qty' => $r['qty'],
                'unit_price' => $r['unit'], 'target_unit_gross' => $targetUnit];
        }
        if (!$targets) return null;
        $other = $nonPackaging - $eligible;
        $packaging = $subtotal - $nonPackaging;
        $base = intdiv($eligible * 20, 100);
        $target = $packaging + $other + intdiv($other * $taxPercent, 100) + $targetEligible;
        $before = static fn (int $d): int => $subtotal - $d + intdiv(($nonPackaging - $d) * $taxPercent, 100);
        if ($base > $eligible || $before($base) < $target) return null;
        $lo = $base; $hi = $eligible;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($before($mid) <= $target) $hi = $mid;
            else $lo = $mid + 1;
        }
        $tax = intdiv(($nonPackaging - $lo) * $taxPercent, 100);
        $residual = $target - ($subtotal - $lo + $tax);
        if ($residual < 0 || $residual > 1) return null;
        return [
            'version' => 2, 'kind' => 'SQUAD', 'subtotal' => $subtotal, 'eligible_subtotal' => $eligible,
            'base_discount_amount' => $base, 'additional_squad_rounding_discount' => $lo - $base,
            'discount_amount' => $lo, 'tax_total' => $tax,
            'rounding_total' => $residual, 'grand_total' => $target, 'line_targets' => $targets,
        ];
    }

    public static function online(string $channel, array $items, int $subtotal, int $discount, int $tax, int $service = 0): ?array
    {
        if ($channel !== 'DELIVERY' || $subtotal < 0 || $discount < 0 || $discount > $subtotal || $tax < 0 || $service < 0) return null;
        $lines = self::lines($items, 'DELIVERY');
        if ($lines === null) return null;
        $actual = 0; $protected = 0;
        foreach ($lines as $r) {
            $actual += $r['line'];
            if ($r['unit'] === 500) $protected += $r['line'];
        }
        if ($actual !== $subtotal || !$protected) return null;
        $policy = 'EXEMPT_500'; $delta = 0;
        if ($discount || $tax || $service) {
            $policy = 'NO_ROUNDING_AMBIGUOUS_COMPONENTS';
        } else {
            $other = $subtotal - $protected;
            $rem = $other % 1000;
            $delta = ($other > 0 && $other < 500) ? 0 : ($rem === 0 ? 0 : ($rem < 500 ? -$rem : 1000 - $rem));
        }
        return [
            'version' => 2, 'kind' => 'ONLINE_500', 'protected_500_total' => $protected,
            'policy' => $policy, 'rounding_total' => $delta,
            'grand_total' => $subtotal - $discount + $tax + $service + $delta,
        ];
    }
}
