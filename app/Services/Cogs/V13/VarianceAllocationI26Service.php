<?php

namespace App\Services\Cogs\V13;

use App\Models\Cogs\StockVariance;
use App\Services\Cogs\CanonicalStockVarianceLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V13 I26: deterministic read-only allocation proposal.
 * Does not post inventory, journal, or alter historical sale cost snapshots.
 * Fails closed if canonical SKU consumption and eligible sale-item lines disagree.
 */
final class VarianceAllocationI26Service
{
    private const MAX_SOURCE_LINES = 12000;
    private const MAX_PERIOD_DAYS = 35;

    public function __construct(private readonly CanonicalStockVarianceLedgerService $canonical)
    {
    }

    /** Allocate signed integer cents by positive consumption quantity, largest remainder and stable key. */
    public static function distribute(int $cents, array $weights): array
    {
        ksort($weights, SORT_STRING);
        $weights = array_filter($weights, static fn ($value) => (float) $value > 0);
        $total = array_sum(array_map('floatval', $weights));
        if ($total <= 0 || !$cents) {
            return array_fill_keys(array_keys($weights), 0);
        }
        $sign = $cents < 0 ? -1 : 1;
        $target = abs($cents);
        $allocations = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $key => $weight) {
            $raw = $target * ((float) $weight / $total);
            $base = (int) floor($raw);
            $allocations[$key] = $base;
            $remainders[] = ['key' => $key, 'fraction' => $raw - $base];
            $assigned += $base;
        }
        usort($remainders, static fn (array $a, array $b) => ($b['fraction'] <=> $a['fraction']) ?: strcmp((string) $a['key'], (string) $b['key']));
        $left = $target - $assigned;
        for ($i = 0; $i < $left; $i++) {
            $allocations[$remainders[$i % count($remainders)]['key']]++;
        }
        return array_map(static fn (int $value) => $value * $sign, $allocations);
    }

    public function report(string $varianceId, string $outletId, int $page = 1, int $perPage = 25): array
    {
        $variance = StockVariance::query()
            ->with(['items', 'stockOpname', 'previousStockOpname'])
            ->where('outlet_id', $outletId)
            ->findOrFail($varianceId);

        if ($variance->status !== StockVariance::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['variance_id' => ['Hanya Stock Variance berstatus submitted yang dapat dialokasikan.']]);
        }
        if (!$variance->stockOpname || !$this->canonical->isCanonicalSubmittedOpname($variance->stockOpname)) {
            throw ValidationException::withMessages(['variance_id' => ['Opname bukan dokumen canonical submitted yang valid.']]);
        }
        if (data_get($variance->metadata, 'engine') !== CanonicalStockVarianceLedgerService::ENGINE_VERSION) {
            throw ValidationException::withMessages(['variance_id' => ['Variance masih menggunakan mesin legacy; hitung ulang canonical dahulu.']]);
        }

        $opening = $variance->previousStockOpname;
        $boundary = $this->canonical->resetBoundary($outletId);
        if ($opening && !$this->canonical->isCanonicalSubmittedOpname($opening)) {
            throw ValidationException::withMessages(['variance_id' => ['Opening opname tidak canonical.']]);
        }
        // No opening or reset anchor means an unbounded, unauditable movement window.
        if (!$opening && !$boundary) {
            throw ValidationException::withMessages(['variance_id' => ['Periode belum memiliki opening opname atau reset boundary yang dapat diaudit.']]);
        }
        $startAt = $opening?->submitted_at
            ? CarbonImmutable::parse((string) $opening->submitted_at, config('app.timezone', 'UTC'))
            : CarbonImmutable::parse((string) $boundary['executed_at'], config('app.timezone', 'UTC'));
        $endAt = CarbonImmutable::parse((string) $variance->stockOpname->submitted_at, config('app.timezone', 'UTC'));
        if (!$endAt->greaterThan($startAt) || $startAt->diffInDays($endAt) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['variance_id' => ['Periode opname melebihi 35 hari atau timestamp opening/closing tidak valid.']]);
        }
        $fromDate = $opening?->opname_date?->toDateString() ?? $boundary['business_date'];
        $closingDate = $variance->variance_date->toDateString();
        $skuIds = $variance->items->pluck('sku_id')->map(fn ($v) => (string) $v)->all();
        if (!$skuIds) {
            throw ValidationException::withMessages(['variance_id' => ['Tidak ada SKU pada variance.']]);
        }

        // Ensure a submitted variance is still based on exactly the same canonical
        // GR + consumption movement set. A replay or late offline sync must block
        // a stale variance rather than silently reallocating a changed source.
        $currentCanonical = $this->canonical->movementSet($variance->stockOpname, $opening, $skuIds);
        $storedCanonical = (string) data_get($variance->metadata, 'canonical_movement_fingerprint', '');
        if ($storedCanonical === '' || !hash_equals($storedCanonical, (string) $currentCanonical['fingerprint'])) {
            throw ValidationException::withMessages(['variance_id' => ['Movement berubah sejak variance disubmit (late sync/reversal/GR). Recalculate Stock Variance secara canonical sebelum alokasi.']]);
        }

        // Include reversals for quantity parity with the canonical ledger. Only still-posted
        // consumption items are eligible to RECEIVE a cost allocation.
        $source = DB::table('cogs_sale_consumption_items as i')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'i.consumption_id')
            ->leftJoin('sales as s', 's.id', '=', 'c.sale_id')
            ->where('c.outlet_id', $outletId)
            ->whereIn('i.sku_id', $skuIds)
            ->whereBetween('c.business_date', [$fromDate, $closingDate])
            ->where(function ($q): void {
                $q->where(function ($p): void {
                    $p->where('c.movement_type', 'sale_consumption')->whereIn('c.status', ['posted', 'reversed']);
                })->orWhere(function ($r): void {
                    $r->where('c.movement_type', 'sale_consumption_reversal')->where('c.status', 'posted');
                });
            })
            ->orderBy('i.id')
            ->limit(self::MAX_SOURCE_LINES + 1)
            ->get([
                'i.id as line_id', 'i.sku_id', 'i.movement_quantity', 'i.quantity_base',
                'i.unit_cost_snapshot', 'i.total_cost as line_cost',
                'c.id as consumption_id', 'c.sale_item_id', 'c.sale_id', 'c.product_id', 'c.product_variant_id',
                'c.product_name_snapshot', 'c.variant_name_snapshot', 'c.sold_quantity',
                'c.total_cost as header_cost', 'c.movement_type', 'c.status', 'c.business_date',
                'c.processed_at', 'c.created_at as consumption_created_at', 's.created_at as sale_created_at',
            ]);
        if ($source->count() > self::MAX_SOURCE_LINES) {
            throw ValidationException::withMessages(['variance_id' => ['Lebih dari 12.000 baris konsumsi; gunakan periode opname lebih pendek atau proses batch audit khusus.']]);
        }

        $skuNames = $variance->items->mapWithKeys(fn ($r) => [(string) $r->sku_id => (string) $r->sku_name_snapshot])->all();
        $eligible = [];
        $netBySku = [];
        $eligibleQtyBySku = [];
        $missingHistoricalCostBySku = [];
        $eventIds = [];
        $consumptionHeader = [];
        foreach ($source as $line) {
            $eventRaw = $line->movement_type === 'sale_consumption_reversal'
                ? ($line->processed_at ?: $line->consumption_created_at)
                : ($line->sale_created_at ?: $line->processed_at ?: $line->consumption_created_at);
            // Follow CanonicalStockVarianceLedgerService's event timestamp preference.
            $eventAt = $eventRaw
                ? CarbonImmutable::parse((string) $eventRaw, config('app.timezone', 'UTC'))
                : CarbonImmutable::parse((string) $line->business_date.' 00:00:00', 'Asia/Jakarta')->setTimezone(config('app.timezone', 'UTC'));
            if (!$eventAt->greaterThan($startAt) || $eventAt->greaterThan($endAt)) {
                continue;
            }
            $sku = (string) $line->sku_id;
            $netBySku[$sku] = ($netBySku[$sku] ?? 0) + (float) $line->movement_quantity;
            $eventIds[] = implode(':', [(string) $line->line_id, (string) $line->consumption_id, (string) $line->movement_quantity, (string) $line->quantity_base, (string) $line->line_cost, (string) $line->header_cost, (string) $line->status]);
            if ($line->movement_type !== 'sale_consumption' || $line->status !== 'posted') {
                continue;
            }
            $qty = (float) $line->quantity_base;
            if ($qty <= 0 || (float) $line->movement_quantity >= 0) {
                continue;
            }
            $eligible[] = $line;
            if ((float) $line->unit_cost_snapshot <= 0 || (float) $line->line_cost <= 0) {
                $missingHistoricalCostBySku[$sku] = true;
            }
            $eligibleQtyBySku[$sku] = ($eligibleQtyBySku[$sku] ?? 0) + $qty;
            $consumptionHeader[(string) $line->consumption_id] = (float) $line->header_cost;
        }
        sort($eventIds, SORT_STRING);
        $blockers = [];
        $skuSummary = [];
        $weightsBySku = [];
        $items = [];
        foreach ($eligible as $line) {
            $sku = (string) $line->sku_id;
            $key = (string) $line->line_id;
            $weightsBySku[$sku][$key] = (float) $line->quantity_base;
            $items[$key] = [
                'line_id' => $key, 'sku_id' => $sku, 'sku' => (string) ($skuNames[$sku] ?? $sku), 'consumption_id' => (string) $line->consumption_id,
                'sale_id' => (string) $line->sale_id, 'sale_item_id' => (string) $line->sale_item_id,
                'product' => (string) $line->product_name_snapshot, 'variant' => (string) $line->variant_name_snapshot,
                'quantity_sold' => (string) $line->sold_quantity, 'business_date' => (string) $line->business_date,
                'ingredient_quantity_base' => (string) $line->quantity_base,
                'historical_line_hpp' => number_format((float) $line->line_cost, 2, '.', ''),
                'historical_line_cents' => (int) round((float) $line->line_cost * 100),
                'allocation_cents' => 0,
            ];
        }

        foreach ($variance->items as $skuRow) {
            $sku = (string) $skuRow->sku_id;
            $net = (float) ($netBySku[$sku] ?? 0);
            $canonicalNet = (float) $skuRow->sale_consumption_qty;
            $costCents = -(int) round((float) $skuRow->net_variance_value * 100);
            $warnings = (array) ($skuRow->warning_codes ?: []);
            $reason = [];
            if (abs($net - $canonicalNet) > 0.00001) $reason[] = 'source_consumption_mismatch';
            if ($costCents !== 0 && (float) ($eligibleQtyBySku[$sku] ?? 0) <= 0) $reason[] = 'no_eligible_item_sold';
            if (abs((float) $skuRow->variance_qty) > 0.00000001 && (float) $skuRow->unit_cost_snapshot <= 0) $reason[] = 'missing_unit_cost';
            if ($warnings) $reason[] = 'variance_warning_requires_review';
            if (!empty($missingHistoricalCostBySku[$sku])) $reason[] = 'missing_historical_consumption_cost';
            if ($reason) $blockers[] = ['sku_id' => $sku, 'sku' => (string) $skuRow->sku_name_snapshot, 'codes' => $reason, 'source_warnings' => $warnings];
            $assigned = $reason ? 0 : $costCents;
            if ($assigned !== 0) {
                foreach (self::distribute($assigned, $weightsBySku[$sku] ?? []) as $id => $value) {
                    $items[$id]['allocation_cents'] += $value;
                }
            }
            $skuSummary[] = [
                'sku_id' => $sku, 'sku' => (string) $skuRow->sku_name_snapshot,
                'variance_qty' => (string) $skuRow->variance_qty,
                'variance_cost' => number_format(-$costCents / 100, 2, '.', ''),
                'eligible_quantity' => number_format((float) ($eligibleQtyBySku[$sku] ?? 0), 8, '.', ''),
                'allocated_cost' => number_format($assigned / 100, 2, '.', ''),
                'unallocated_cost' => number_format(($costCents - $assigned) / 100, 2, '.', ''),
                'status' => $reason ? 'blocked' : 'reconciled',
            ];
        }
        foreach ($items as &$item) {
            $item['allocation_cost'] = number_format($item['allocation_cents'] / 100, 2, '.', '');
            $item['adjusted_line_hpp'] = number_format(($item['historical_line_cents'] + $item['allocation_cents']) / 100, 2, '.', '');
            unset($item['historical_line_cents'], $item['allocation_cents']);
        }
        unset($item);
        $values = array_values($items);
        usort($values, static fn ($a, $b) => strcmp($a['line_id'], $b['line_id']));
        $totalAllocated = round(array_sum(array_map(static fn ($v) => (float) $v['allocation_cost'], $values)), 2);
        $target = -(float) $variance->net_variance_value;
        $sumUnallocated = round($target - $totalAllocated, 2);
        if ((int) $variance->open_exception_count > 0 || (int) $variance->uncounted_movement_sku_count > 0) {
            $blockers[] = ['sku_id' => null, 'sku' => 'Seluruh opname', 'codes' => ['open_cogs_exception_or_uncounted_movement'], 'source_warnings' => []];
        }
        if (abs(round(array_sum(array_map(static fn ($i) => (float) $i->net_variance_value, $variance->items->all())), 2) - (float) $variance->net_variance_value) > 0.011) {
            $blockers[] = ['sku_id' => null, 'sku' => 'Total variance', 'codes' => ['variance_header_items_mismatch'], 'source_warnings' => []];
        }
        // Sale level = original historical consumption header, PLUS allocated ingredient deltas.
        // Do not sum the ingredient cost of only the counted SKU subset as total item HPP.
        $soldItems = [];
        foreach ($values as $line) {
            $id = $line['consumption_id'];
            if (!isset($soldItems[$id])) {
                $soldItems[$id] = [
                    'consumption_id' => $id,
                    'sale_id' => $line['sale_id'], 'sale_item_id' => $line['sale_item_id'],
                    'business_date' => $line['business_date'], 'product' => $line['product'],
                    'variant' => $line['variant'], 'quantity_sold' => $line['quantity_sold'],
                    'historical_hpp_cents' => (int) round(($consumptionHeader[$id] ?? 0) * 100),
                    'allocation_cents' => 0, 'ingredients' => [],
                ];
            }
            $soldItems[$id]['allocation_cents'] += (int) round((float) $line['allocation_cost'] * 100);
            $soldItems[$id]['ingredients'][] = [
                'line_id' => $line['line_id'], 'sku' => $line['sku'], 'sku_id' => $line['sku_id'],
                'quantity_base' => $line['ingredient_quantity_base'],
                'historical_cost' => $line['historical_line_hpp'], 'allocation_cost' => $line['allocation_cost'],
            ];
        }
        $productSummary = [];
        foreach ($soldItems as &$sold) {
            $adjusted = $sold['historical_hpp_cents'] + $sold['allocation_cents'];
            if ($adjusted < 0) {
                $blockers[] = ['sku_id' => null, 'sku' => $sold['product'], 'codes' => ['negative_adjusted_item_hpp'], 'source_warnings' => []];
            }
            $sold['historical_hpp'] = number_format($sold['historical_hpp_cents'] / 100, 2, '.', '');
            $sold['allocation_cost'] = number_format($sold['allocation_cents'] / 100, 2, '.', '');
            $sold['adjusted_hpp'] = number_format($adjusted / 100, 2, '.', '');
            $groupKey = $sold['product'].' | '.$sold['variant'];
            if (!isset($productSummary[$groupKey])) {
                $productSummary[$groupKey] = ['product' => $sold['product'], 'variant' => $sold['variant'], 'item_count' => 0, 'historical_cents' => 0, 'adjustment_cents' => 0];
            }
            $productSummary[$groupKey]['item_count']++;
            $productSummary[$groupKey]['historical_cents'] += $sold['historical_hpp_cents'];
            $productSummary[$groupKey]['adjustment_cents'] += $sold['allocation_cents'];
            unset($sold['historical_hpp_cents'], $sold['allocation_cents']);
        }
        unset($sold);
        foreach ($productSummary as &$p) {
            $p['historical_hpp'] = number_format($p['historical_cents'] / 100, 2, '.', '');
            $p['allocation_cost'] = number_format($p['adjustment_cents'] / 100, 2, '.', '');
            $p['adjusted_hpp'] = number_format(($p['historical_cents'] + $p['adjustment_cents']) / 100, 2, '.', '');
            unset($p['historical_cents'], $p['adjustment_cents']);
        }
        unset($p);
        ksort($soldItems, SORT_STRING);
        $soldValues = array_values($soldItems);
        $allGood = !$blockers && abs($sumUnallocated) < 0.011;
        $fingerprint = hash('sha256', json_encode([
            'engine' => 'v13-i26-readonly-v1', 'variance_id' => $varianceId,
            'variance_fingerprint' => (string) $variance->source_fingerprint,
            'events' => $eventIds,
            'sku' => $skuSummary,
            'allocations' => array_map(static fn ($v) => [$v['line_id'], $v['allocation_cost']], $values),
            'sales' => array_map(static fn ($v) => [$v['consumption_id'], $v['historical_hpp'], $v['adjusted_hpp']], $soldValues),
        ], JSON_UNESCAPED_SLASHES));
        $total = count($values);
        return [
            'engine' => 'v13-i26-readonly-v1', 'status' => $allGood ? 'reconciled_preview' : 'blocked_preview',
            'posting_enabled' => false, 'variance_id' => $varianceId,
            'outlet_id' => $outletId, 'variance_date' => $closingDate,
            'period' => ['from_exclusive' => $startAt->toIso8601String(), 'to_inclusive' => $endAt->toIso8601String()],
            'fingerprint' => $fingerprint,
            'summary' => [
                'item_lines' => count($values), 'sale_items' => count($soldValues), 'sku_count' => count($skuSummary),
                'blocked_sku_count' => count($blockers),
                'variance_hpp_adjustment' => number_format($target, 2, '.', ''),
                'allocated_hpp_adjustment' => number_format($totalAllocated, 2, '.', ''),
                'unallocated_hpp_adjustment' => number_format($sumUnallocated, 2, '.', ''),
            ],
            'blockers' => $blockers,
            'sku_summary' => $skuSummary,
            'product_summary' => array_values($productSummary),
            'items' => array_slice($soldValues, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => count($soldValues),
                'last_page' => max(1, (int) ceil(count($soldValues) / $perPage)),
            ],
            'disclaimer' => 'Read-only proposal. Excludes unavailable, reversed or unauditable consumption; does not change historical HPP, inventory movements or Finance GL.',
        ];
    }
}
