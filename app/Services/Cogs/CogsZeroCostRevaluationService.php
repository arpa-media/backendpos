<?php

namespace App\Services\Cogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CogsZeroCostRevaluationService
{
    public function __construct(private readonly CogsValuationResolverService $valuationResolver)
    {
    }

    /** @return array<string,int> */
    public function revalue(?string $outletId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $dryRun = false): array
    {
        if (! Schema::hasTable('cogs_sale_consumption_items') || ! Schema::hasTable('cogs_sale_consumptions')) {
            return ['scanned' => 0, 'revalued' => 0, 'unresolved' => 0, 'closed_period_skipped' => 0, 'parents_recalculated' => 0];
        }

        $this->valuationResolver->clearCache();

        $query = DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->whereIn('c.movement_type', ['sale_consumption', 'sale_consumption_reversal'])
            ->whereIn('c.status', ['posted', 'reversed'])
            ->where(function ($q): void {
                $q->where('item.unit_cost_snapshot', '<=', 0)
                    ->orWhere('item.total_cost', '<=', 0);
            });
        if ($outletId) $query->where('c.outlet_id', $outletId);
        if ($dateFrom) $query->where('c.business_date', '>=', $dateFrom);
        if ($dateTo) $query->where('c.business_date', '<=', $dateTo);

        $rows = $query->orderBy('c.business_date')->orderBy('item.id')->get([
            'item.*', 'c.outlet_id', 'c.business_date', 'c.movement_type as consumption_type', 'c.status as consumption_status',
        ]);

        $summary = ['scanned' => 0, 'revalued' => 0, 'unresolved' => 0, 'closed_period_skipped' => 0, 'parents_recalculated' => 0];

        foreach ($rows as $row) {
            $summary['scanned']++;
            $date = substr((string) $row->business_date, 0, 10);
            if ($this->isClosedPeriod((string) $row->outlet_id, $date)) {
                $summary['closed_period_skipped']++;
                continue;
            }

            $cost = null;
            $source = null;
            $sourceId = null;
            if ($row->original_consumption_item_id) {
                $original = DB::table('cogs_sale_consumption_items')->where('id', $row->original_consumption_item_id)->first(['unit_cost_snapshot']);
                if ($original && (float) $original->unit_cost_snapshot > 0) {
                    $cost = round((float) $original->unit_cost_snapshot, 4);
                    $source = 'original_consumption_item';
                    $sourceId = (string) $row->original_consumption_item_id;
                }
            }
            if (! $cost) {
                $resolved = $this->valuationResolver->resolve((string) $row->outlet_id, (string) $row->sku_id, $date, 0.0);
                $cost = $resolved['unit_cost'];
                $source = $resolved['source'];
                $sourceId = $resolved['source_id'];
            }
            if ($cost <= 0) {
                $summary['unresolved']++;
                continue;
            }

            $qty = abs((float) $row->movement_quantity);
            if ($qty <= 0.00000001) {
                $qty = abs((float) $row->quantity_base);
            }
            $lineCost = round($qty * $cost, 2);
            $summary['revalued']++;
            if ($dryRun) continue;

            $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) ($row->metadata ?? []);
            $meta = array_merge($meta ?: [], [
                'zero_average_cost' => false,
                'cost_source' => $source,
                'cost_source_id' => $sourceId,
                'erp_v5_iteration_15_revalued' => true,
                'revalued_at' => now()->toIso8601String(),
            ]);

            $averageBefore = (float) $row->average_cost_before > 0 ? (float) $row->average_cost_before : $cost;
            $averageAfter = (float) $row->average_cost_after > 0 ? (float) $row->average_cost_after : $cost;
            $valueBefore = (float) $row->inventory_value_before > 0
                ? (float) $row->inventory_value_before
                : round((float) $row->balance_qty_before * $averageBefore, 2);
            $valueAfter = (float) $row->inventory_value_after > 0
                ? (float) $row->inventory_value_after
                : round((float) $row->balance_qty_after * $averageAfter, 2);

            DB::table('cogs_sale_consumption_items')->where('id', $row->id)->update([
                'unit_cost_snapshot' => $cost,
                'total_cost' => $lineCost,
                'average_cost_before' => $averageBefore,
                'average_cost_after' => $averageAfter,
                'inventory_value_before' => $valueBefore,
                'inventory_value_after' => $valueAfter,
                'metadata' => json_encode($meta),
                'updated_at' => now(),
            ]);

            if ($row->inventory_movement_id) {
                $movement = DB::table('stk_inventory_movements')->where('id', $row->inventory_movement_id)->first();
                if ($movement) {
                    $movementMeta = is_string($movement->metadata) ? json_decode($movement->metadata, true) : (array) ($movement->metadata ?? []);
                    DB::table('stk_inventory_movements')->where('id', $movement->id)->update([
                        'unit_cost' => $cost,
                        'total_cost' => round((float) $movement->quantity * $cost, 2),
                        'average_cost_after' => (float) $movement->average_cost_after > 0 ? $movement->average_cost_after : $cost,
                        'inventory_value_after' => (float) ($movement->inventory_value_after ?? 0) > 0
                            ? $movement->inventory_value_after
                            : round((float) $movement->balance_qty_after * $cost, 2),
                        'metadata' => json_encode(array_merge($movementMeta ?: [], [
                            'erp_v5_iteration_15_revalued' => true,
                            'cost_source' => $source,
                            'cost_source_id' => $sourceId,
                        ])),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // ERP V5 I02: parent total is a materialized aggregate and may be stale even
        // when a child line was already revalued by an older hotfix. Reconcile every
        // scoped parent, not only parents changed during this invocation.
        $summary['parents_recalculated'] = $this->reconcileParentTotals($outletId, $dateFrom, $dateTo, $dryRun);
        $this->valuationResolver->clearCache();

        return $summary;
    }

    private function reconcileParentTotals(?string $outletId, ?string $dateFrom, ?string $dateTo, bool $dryRun): int
    {
        $query = DB::table('cogs_sale_consumptions as c')
            ->whereIn('c.movement_type', ['sale_consumption', 'sale_consumption_reversal'])
            ->whereIn('c.status', ['posted', 'reversed']);
        if ($outletId) $query->where('c.outlet_id', $outletId);
        if ($dateFrom) $query->where('c.business_date', '>=', $dateFrom);
        if ($dateTo) $query->where('c.business_date', '<=', $dateTo);

        $changed = 0;
        $query->orderBy('c.id')->chunkById(200, function ($rows) use (&$changed, $dryRun): void {
            foreach ($rows as $parent) {
                if ($this->isClosedPeriod((string) $parent->outlet_id, substr((string) $parent->business_date, 0, 10))) {
                    continue;
                }
                $sum = round((float) DB::table('cogs_sale_consumption_items')
                    ->where('consumption_id', $parent->id)
                    ->sum('total_cost'), 2);
                if (abs((float) $parent->total_cost - $sum) <= 0.01) {
                    continue;
                }
                $changed++;
                if ($dryRun) continue;

                $meta = is_string($parent->metadata ?? null)
                    ? json_decode((string) $parent->metadata, true)
                    : (array) ($parent->metadata ?? []);
                DB::table('cogs_sale_consumptions')->where('id', $parent->id)->update([
                    'total_cost' => $sum,
                    'metadata' => json_encode(array_merge($meta ?: [], [
                        'erp_v5_i02_parent_total_reconciled' => true,
                        'revalued_at' => now()->toIso8601String(),
                    ])),
                    'updated_at' => now(),
                ]);
            }
        }, 'c.id', 'id');

        return $changed;
    }

    private function isClosedPeriod(string $outletId, string $businessDate): bool
    {
        if (! Schema::hasTable('cogs_calculation_runs')) return false;
        return DB::table('cogs_calculation_runs')
            ->where('outlet_id', $outletId)
            ->where('status', 'closed')
            ->where('period_from', '<=', $businessDate)
            ->where('period_to', '>=', $businessDate)
            ->exists();
    }
}
