<?php

namespace App\Services\Cogs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CogsValuationResolverService
{
    /** @var array<string,array{unit_cost:float,source:string,source_id:?string}> */
    private array $cache = [];

    /**
     * Resolve historical COGS unit cost.
     *
     * ERP V5 I02 policy:
     * - Warehouse V3 outlet receipt uses Warehouse selling price / invoice value.
     * - Canonical COGS receipt snapshots outrank generic inventory movements.
     * - Procurement mirrors (`is_canonical = 0`) never become a second valuation source.
     *
     * @return array{unit_cost:float,source:string,source_id:?string}
     */
    public function resolve(string $outletId, string $skuId, string $businessDate, float $currentAverage = 0.0): array
    {
        $date = substr($businessDate, 0, 10);
        $cacheKey = implode('|', [$outletId, $skuId, $date, number_format($currentAverage, 4, '.', '')]);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Direct Warehouse invoice is the strongest commercial evidence and remains
        // usable immediately after a COGS reset before snapshot backfill completes.
        $warehouseSelling = $this->warehouseSellingPrice($outletId, $skuId, $date);
        if ($warehouseSelling !== null) {
            return $this->cache[$cacheKey] = $warehouseSelling;
        }

        // History Stock already carries the authoritative valuation. Use the same
        // canonical snapshot here so Item Sold cannot diverge from History Stock.
        $snapshotCost = $this->canonicalSnapshotCost($outletId, $skuId, $date);
        if ($snapshotCost !== null) {
            return $this->cache[$cacheKey] = $snapshotCost;
        }

        if (Schema::hasTable('stk_inventory_movements')) {
            $movement = DB::table('stk_inventory_movements')
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->where('business_date', '<=', $date)
                ->whereNotIn('movement_type', ['sale_consumption', 'sale_consumption_reversal'])
                ->where(function ($query): void {
                    $query->where('average_cost_after', '>', 0)
                        ->orWhere('unit_cost', '>', 0);
                })
                ->orderByDesc('business_date')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'average_cost_after', 'unit_cost']);

            if ($movement) {
                $cost = (float) ($movement->average_cost_after ?? 0);
                if ($cost <= 0) {
                    $cost = (float) ($movement->unit_cost ?? 0);
                }
                if ($cost > 0) {
                    return $this->cache[$cacheKey] = [
                        'unit_cost' => round($cost, 4),
                        'source' => 'inventory_movement_average',
                        'source_id' => (string) $movement->id,
                    ];
                }
            }
        }

        if ($currentAverage > 0) {
            return $this->cache[$cacheKey] = [
                'unit_cost' => round($currentAverage, 4),
                'source' => 'current_inventory_average_fallback',
                'source_id' => null,
            ];
        }

        if (Schema::hasTable('stk_inventory_balances')) {
            $average = (float) (DB::table('stk_inventory_balances')
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->value('average_unit_cost') ?? 0);
            if ($average > 0) {
                return $this->cache[$cacheKey] = [
                    'unit_cost' => round($average, 4),
                    'source' => 'current_inventory_balance_fallback',
                    'source_id' => null,
                ];
            }
        }

        return $this->cache[$cacheKey] = ['unit_cost' => 0.0, 'source' => 'unavailable', 'source_id' => null];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /** @return array{unit_cost:float,source:string,source_id:?string}|null */
    private function canonicalSnapshotCost(string $outletId, string $skuId, string $businessDate): ?array
    {
        if (! Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            return null;
        }

        $query = DB::table('cogs_purchasing_cost_snapshots')
            ->where('outlet_id', $outletId)
            ->where('sku_id', $skuId)
            ->where('receipt_date', '<=', $businessDate)
            ->where(function (Builder $query): void {
                $query->where('average_cost_after', '>', 0)
                    ->orWhere('unit_cost', '>', 0);
            });

        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $query->where('is_canonical', true);
        }

        $snapshot = $query
            ->orderByDesc('receipt_date')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first(['id', 'average_cost_after', 'unit_cost', 'receipt_type_snapshot', 'price_source_snapshot']);

        if (! $snapshot) {
            return null;
        }

        $warehousePrice = in_array((string) $snapshot->receipt_type_snapshot, ['warehouse_v3', 'warehouse'], true)
            || str_contains((string) $snapshot->price_source_snapshot, 'warehouse_selling');
        $cost = $warehousePrice
            ? (float) ($snapshot->unit_cost ?? 0)
            : (float) ($snapshot->average_cost_after ?? 0);
        if ($cost <= 0) {
            $cost = (float) ($snapshot->unit_cost ?? 0);
        }
        if ($cost <= 0) {
            return null;
        }

        return [
            'unit_cost' => round($cost, 4),
            'source' => $warehousePrice ? 'canonical_warehouse_cost_snapshot' : 'canonical_purchasing_cost_snapshot',
            'source_id' => (string) $snapshot->id,
        ];
    }

    /** @return array{unit_cost:float,source:string,source_id:?string}|null */
    private function warehouseSellingPrice(string $outletId, string $skuId, string $businessDate): ?array
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoice_items')
            || ! Schema::hasTable('wh_v3_outgoing_invoices')
            || ! Schema::hasTable('wh_v3_goods_receipts')) {
            return null;
        }

        $row = DB::table('wh_v3_outgoing_invoice_items as item')
            ->join('wh_v3_outgoing_invoices as invoice', 'invoice.id', '=', 'item.outgoing_invoice_id')
            ->join('wh_v3_goods_receipts as gr', 'gr.id', '=', 'invoice.goods_receipt_id')
            ->where('invoice.destination_type', 'outlet')
            ->where('invoice.destination_id', $outletId)
            ->where('item.sku_id', $skuId)
            ->where('gr.status', 'completed')
            ->where('invoice.invoice_date', '<=', $businessDate)
            ->where('item.billed_qty_base', '>', 0)
            ->where('item.line_total', '>', 0)
            ->orderByDesc('invoice.invoice_date')
            ->orderByDesc('invoice.created_at')
            ->orderByDesc('item.id')
            ->first(['item.id', 'item.billed_qty_base', 'item.line_total']);

        if (! $row) {
            return null;
        }

        $qty = (float) $row->billed_qty_base;
        $lineTotal = (float) $row->line_total;
        if ($qty <= 0 || $lineTotal <= 0) {
            return null;
        }

        return [
            'unit_cost' => round($lineTotal / $qty, 4),
            'source' => 'warehouse_selling_price',
            'source_id' => (string) $row->id,
        ];
    }
}
