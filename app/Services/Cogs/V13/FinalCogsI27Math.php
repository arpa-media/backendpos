<?php

namespace App\Services\Cogs\V13;

use Illuminate\Validation\ValidationException;

/** Pure cents-based snapshot rules. No GL posting and no inventory mutation. */
final class FinalCogsI27Math
{
    public static function cents(string|int|float $value): int
    {
        $raw = trim((string) $value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $raw, $m)) {
            throw ValidationException::withMessages(['amount' => ['Nominal bukan decimal 2 digit yang valid.']]);
        }
        $major = (int) $m[2];
        if ($major > 90000000000000) {
            throw ValidationException::withMessages(['amount' => ['Nominal melampaui batas aman.']]);
        }
        $minor = (int) str_pad($m[3] ?? '', 2, '0');
        return ($m[1] === '-' ? -1 : 1) * ($major * 100 + $minor);
    }

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{lines: array, totals: array, snapshot_hash: string} */
    public static function snapshot(array $report): array
    {
        if (($report['status'] ?? '') !== 'reconciled_preview' || ($report['blockers'] ?? []) !== []) {
            throw ValidationException::withMessages(['variance_id' => ['Alokasi selisih stok masih memiliki masalah. Perbaiki masalah yang ditampilkan dan hitung ulang selisih stok.']]);
        }
        $expected = self::cents((string) ($report['summary']['variance_hpp_adjustment'] ?? '0.00'));
        $allocated = self::cents((string) ($report['summary']['allocated_hpp_adjustment'] ?? '0.00'));
        $unallocated = self::cents((string) ($report['summary']['unallocated_hpp_adjustment'] ?? '0.00'));
        if ($unallocated !== 0 || $expected !== $allocated) {
            throw ValidationException::withMessages(['variance_id' => ['Alokasi variance belum 100% rekonsiliasi.']]);
        }
        $lines = [];
        $historicalTotal = 0;
        $allocatedTotal = 0;
        $ingredientCount = 0;
        foreach ($report['items'] ?? [] as $row) {
            $hist = self::cents((string) $row['historical_hpp']);
            $allocation = self::cents((string) $row['allocation_cost']);
            $final = self::cents((string) $row['adjusted_hpp']);
            if ($hist < 0 || $final < 0 || $hist + $allocation !== $final) {
                throw ValidationException::withMessages(['variance_id' => ['HPP item tidak seimbang atau negatif.']]);
            }
            $ingredients = $row['ingredients'] ?? [];
            if (!is_array($ingredients) || !$ingredients) {
                throw ValidationException::withMessages(['variance_id' => ['Rincian ingredient untuk item tidak lengkap.']]);
            }
            $sumIngredientAllocation = array_sum(array_map(static fn ($i) => self::cents((string) $i['allocation_cost']), $ingredients));
            if ($sumIngredientAllocation !== $allocation) {
                throw ValidationException::withMessages(['variance_id' => ['Total allocation per ingredient tidak sesuai item.']]);
            }
            $line = [
                'consumption_id' => (string) $row['consumption_id'],
                'sale_id' => (string) ($row['sale_id'] ?? ''), 'sale_item_id' => (string) ($row['sale_item_id'] ?? ''),
                'business_date' => (string) $row['business_date'], 'product' => (string) $row['product'],
                'variant' => (string) ($row['variant'] ?? ''), 'quantity_sold' => (string) $row['quantity_sold'],
                'historical_hpp' => self::money($hist), 'allocation_cost' => self::money($allocation),
                'final_hpp' => self::money($final), 'ingredients' => $ingredients,
            ];
            if (isset($lines[$line['consumption_id']])) {
                throw ValidationException::withMessages(['variance_id' => ['Terdapat transaksi pemakaian bahan yang tercatat lebih dari sekali pada hasil alokasi.']]);
            }
            $lines[$line['consumption_id']] = $line;
            $historicalTotal += $hist;
            $allocatedTotal += $allocation;
            $ingredientCount += count($ingredients);
        }
        ksort($lines, SORT_STRING);
        if (count($lines) !== (int) ($report['summary']['sale_items'] ?? -1)
            || $ingredientCount !== (int) ($report['summary']['item_lines'] ?? -1)
            || !$lines || $allocatedTotal !== $expected) {
            throw ValidationException::withMessages(['variance_id' => ['Jumlah item atau total nilai alokasi selisih stok tidak seimbang.']]);
        }
        $totals = [
            'sale_items' => count($lines), 'ingredients' => $ingredientCount,
            'historical_hpp' => self::money($historicalTotal),
            'adjustment' => self::money($allocatedTotal),
            'final_hpp' => self::money($historicalTotal + $allocatedTotal),
            'unallocated' => self::money($unallocated),
        ];
        $hash = hash('sha256', json_encode([
            'engine' => 'v13-i27-snapshot-v1', 'source' => $report['fingerprint'],
            'outlet_id' => $report['outlet_id'], 'variance_id' => $report['variance_id'],
            'period' => $report['period'], 'totals' => $totals, 'lines' => array_values($lines),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ['lines' => array_values($lines), 'totals' => $totals, 'snapshot_hash' => $hash];
    }
}
