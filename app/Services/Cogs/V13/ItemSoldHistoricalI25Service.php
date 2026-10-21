<?php

namespace App\Services\Cogs\V13;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * V13 I25: read-only posted COGS cost evidence.
 * No replay, pricing recomputation, balance mutations, or recipe cutover.
 */
final class ItemSoldHistoricalI25Service
{
    private function base(string $outletId, array $filters): Builder
    {
        $query = DB::table('cogs_sale_consumptions as c')
            ->leftJoin('cogs_recipes as r', 'r.id', '=', 'c.recipe_id')
            ->leftJoin('sale_items as si', 'si.id', '=', 'c.sale_item_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', 'sale_consumption')
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']]);

        $status = (string) ($filters['status'] ?? 'posted');
        if ($status !== 'all') {
            $query->where('c.status', $status);
        } else {
            $query->whereIn('c.status', ['posted', 'reversed']);
        }

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('c.sale_number_snapshot', 'like', $like)
                    ->orWhere('c.product_name_snapshot', 'like', $like)
                    ->orWhere('c.variant_name_snapshot', 'like', $like);
            });
        }

        return $query;
    }

    public static function flags(string $status, float $headerCost, int $lineCount, float $lineCost, int $zeroCostLines, ?string $processedAt, string $businessDate): array
    {
        $flags = [];
        if ($status === 'reversed') {
            $flags[] = 'reversed_excluded_from_active';
        }
        if ($lineCount === 0) {
            $flags[] = 'missing_ingredient_snapshot';
        }
        if ($zeroCostLines > 0) {
            $flags[] = 'zero_cost_ingredient';
        }
        if ($lineCount > 0 && abs($headerCost - $lineCost) > 0.05) {
            $flags[] = 'header_line_cost_mismatch';
        }
        if (! $processedAt) {
            $flags[] = 'posting_time_unknown';
        } elseif (substr($processedAt, 0, 10) > $businessDate) {
            // Conservative only: assumes stored time has same day-boundary as business date.
            $flags[] = 'posting_date_after_business_date_check_timezone';
        }
        return $flags;
    }

    public function report(string $outletId, array $filters): array
    {
        $base = $this->base($outletId, $filters);
        $summary = (clone $base)
            ->selectRaw('COUNT(*) as lines')
            ->selectRaw('COUNT(DISTINCT c.sale_id) as sales')
            ->selectRaw("COALESCE(SUM(CASE WHEN c.status = 'posted' THEN c.sold_quantity ELSE 0 END), 0) as active_sold_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN c.status = 'posted' THEN c.total_cost ELSE 0 END), 0) as active_snapshot_cost")
            ->selectRaw("SUM(CASE WHEN c.status = 'reversed' THEN 1 ELSE 0 END) as reversed_lines")
            ->first();

        $topVariants = (clone $base)
            ->where('c.status', 'posted')
            ->select(['c.product_id', 'c.product_variant_id', 'c.product_name_snapshot', 'c.variant_name_snapshot'])
            ->selectRaw('SUM(c.sold_quantity) as sold_qty')
            ->selectRaw('SUM(c.total_cost) as snapshot_cost')
            ->groupBy('c.product_id', 'c.product_variant_id', 'c.product_name_snapshot', 'c.variant_name_snapshot')
            ->orderByDesc('snapshot_cost')
            ->limit(10)
            ->get()->map(fn ($r) => [
                'product_name' => (string) $r->product_name_snapshot,
                'variant_name' => (string) $r->variant_name_snapshot,
                'sold_qty' => (string) $r->sold_qty,
                'snapshot_cost' => (string) $r->snapshot_cost,
            ])->all();

        $page = (clone $base)
            ->select([
                'c.id', 'c.sale_id', 'c.sale_item_id', 'c.sale_number_snapshot', 'c.business_date',
                'c.business_timezone', 'c.product_id', 'c.product_variant_id',
                'c.product_name_snapshot', 'c.variant_name_snapshot', 'c.sold_quantity',
                'c.recipe_id', 'c.recipe_yield_quantity', 'c.total_cost', 'c.status', 'c.processed_at',
                'r.version_no as linked_recipe_version', 'si.note as sale_item_note',
            ])
            ->orderByDesc('c.business_date')
            ->orderByDesc('c.processed_at')
            ->orderByDesc('c.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $records = collect($page->items());
        $ids = $records->pluck('id')->filter()->all();
        $saleItemIds = $records->pluck('sale_item_id')->filter()->all();

        $costLines = empty($ids) ? collect() : DB::table('cogs_sale_consumption_items as ci')
            ->whereIn('ci.consumption_id', $ids)
            ->select('ci.consumption_id')
            ->selectRaw('COUNT(*) as ingredient_lines')
            ->selectRaw('COALESCE(SUM(ci.total_cost), 0) as line_cost')
            ->selectRaw('SUM(CASE WHEN ci.unit_cost_snapshot <= 0 THEN 1 ELSE 0 END) as zero_cost_lines')
            ->groupBy('ci.consumption_id')
            ->get()->keyBy('consumption_id');

        $addons = empty($saleItemIds) ? collect() : DB::table('sale_item_addons')
            ->whereIn('sale_item_id', $saleItemIds)
            ->orderBy('id')
            ->get(['sale_item_id', 'addon_name', 'qty_per_item'])
            ->groupBy('sale_item_id');

        $items = $records->map(function ($row) use ($costLines, $addons): array {
            $cost = $costLines->get($row->id);
            $lineCount = (int) ($cost->ingredient_lines ?? 0);
            $lineCost = (float) ($cost->line_cost ?? 0);
            $headerCost = (float) $row->total_cost;
            $saleQty = (float) $row->sold_quantity;
            $status = (string) $row->status;
            $flags = self::flags(
                $status, $headerCost, $lineCount, $lineCost,
                (int) ($cost->zero_cost_lines ?? 0),
                $row->processed_at ? (string) $row->processed_at : null,
                (string) $row->business_date,
            );

            $addonList = $addons->get($row->sale_item_id, collect())
                ->map(fn ($a) => [
                    'name' => (string) $a->addon_name,
                    'qty_per_item' => (int) $a->qty_per_item,
                ])->values()->all();
            if ($addonList) {
                $flags[] = 'addons_not_proven_in_recipe_cost';
            }

            return [
                'consumption_id' => (string) $row->id,
                'sale_id' => (string) $row->sale_id,
                'sale_item_id' => (string) $row->sale_item_id,
                'business_date' => (string) $row->business_date,
                'business_timezone' => (string) $row->business_timezone,
                'sale_number' => (string) ($row->sale_number_snapshot ?? ''),
                'product_name' => (string) $row->product_name_snapshot,
                'variant_name' => (string) $row->variant_name_snapshot,
                'sold_qty' => (string) $row->sold_quantity,
                'status' => $status,
                'recipe_id' => $row->recipe_id ? (string) $row->recipe_id : null,
                'recipe_version_linked' => $row->linked_recipe_version !== null ? (int) $row->linked_recipe_version : null,
                'recipe_yield_snapshot' => $row->recipe_yield_quantity !== null ? (string) $row->recipe_yield_quantity : null,
                'snapshot_total_cost' => (string) $row->total_cost,
                'snapshot_cost_per_sold' => $saleQty > 0 ? number_format($headerCost / $saleQty, 2, '.', '') : null,
                'ingredient_line_count' => $lineCount,
                'ingredient_lines_cost' => number_format($lineCost, 2, '.', ''),
                'posted_at' => $row->processed_at ? (string) $row->processed_at : null,
                'sale_item_note' => (string) ($row->sale_item_note ?? ''),
                'addons' => $addonList,
                'modifier_recipe_evidence' => 'unverified_legacy_engine',
                'cost_provenance' => 'posted_consumption_ingredient_snapshot',
                'flags' => $flags,
            ];
        })->all();

        return [
            'period' => ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']],
            'summary' => [
                'lines' => (int) ($summary->lines ?? 0),
                'sales' => (int) ($summary->sales ?? 0),
                'active_sold_qty' => (string) ($summary->active_sold_qty ?? 0),
                'active_snapshot_cost' => (string) ($summary->active_snapshot_cost ?? 0),
                'reversed_lines' => (int) ($summary->reversed_lines ?? 0),
            ],
            'top_variants' => $topVariants,
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'limitations' => [
                'Costs were captured when COGS consumption posted; may be after the actual sale date.',
                'Addon snapshot is shown for context only. Modifier-specific recipe application is not proved by legacy consumption records.',
                'Unposted sales and open exceptions are not in this cost report. Use existing Exception Queue.',
                'This is provisional item-sold HPP prior to variance allocation and final COGS close.',
            ],
        ];
    }

    public function ingredients(string $outletId, string $consumptionId): ?array
    {
        $event = DB::table('cogs_sale_consumptions')
            ->where('id', $consumptionId)
            ->where('outlet_id', $outletId)
            ->where('movement_type', 'sale_consumption')
            ->first(['id','sale_number_snapshot','product_name_snapshot','variant_name_snapshot',
                'sold_quantity','business_date','processed_at','total_cost','status','recipe_id','recipe_yield_quantity']);

        if (! $event) {
            return null;
        }

        $lines = DB::table('cogs_sale_consumption_items as ci')
            ->where('ci.consumption_id', $consumptionId)
            ->orderBy('ci.id')
            ->get([
                'ci.id','ci.sku_id','ci.sku_code_snapshot','ci.sku_name_snapshot',
                'ci.base_uom_code_snapshot','ci.base_uom_symbol_snapshot',
                'ci.quantity_per_sold_base','ci.quantity_base','ci.unit_cost_snapshot',
                'ci.total_cost','ci.inventory_movement_id','ci.metadata',
            ])->map(function ($line): array {
                $meta = is_string($line->metadata ?? null) ? json_decode($line->metadata, true) : (array) ($line->metadata ?? []);
                return [
                    'sku_id' => (string) $line->sku_id,
                    'sku_code' => (string) $line->sku_code_snapshot,
                    'sku_name' => (string) $line->sku_name_snapshot,
                    'base_uom' => (string) ($line->base_uom_symbol_snapshot ?: $line->base_uom_code_snapshot),
                    'quantity_per_sold_base' => (string) $line->quantity_per_sold_base,
                    'quantity_base' => (string) $line->quantity_base,
                    'unit_cost_snapshot' => (string) $line->unit_cost_snapshot,
                    'cost_snapshot' => (string) $line->total_cost,
                    'movement_id' => $line->inventory_movement_id ? (string) $line->inventory_movement_id : null,
                    'cost_source' => is_array($meta) ? ($meta['cost_source'] ?? null) : null,
                    'zero_cost' => (float) $line->unit_cost_snapshot <= 0,
                ];
            })->all();

        return [
            'consumption_id' => (string) $event->id,
            'sale_number' => (string) $event->sale_number_snapshot,
            'product_name' => (string) $event->product_name_snapshot,
            'variant_name' => (string) $event->variant_name_snapshot,
            'business_date' => (string) $event->business_date,
            'posted_at' => $event->processed_at,
            'status' => (string) $event->status,
            'recipe_id' => $event->recipe_id,
            'recipe_yield_snapshot' => $event->recipe_yield_quantity,
            'snapshot_total_cost' => (string) $event->total_cost,
            'ingredient_lines' => $lines,
        ];
    }
}
