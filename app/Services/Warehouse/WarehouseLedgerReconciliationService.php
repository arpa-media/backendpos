<?php

namespace App\Services\Warehouse;

use App\Models\StockInventory\InventoryBalance;
use App\Models\Warehouse\WarehouseReconciliationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehouseLedgerReconciliationService
{
    public function inspect(string $warehouseId, array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $onlyVariance = (bool) ($filters['only_variance'] ?? false);
        $perPage = max(1, min((int) ($filters['per_page'] ?? 100), 500));

        $batchTotals = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(on_hand_qty) as batch_qty, SUM(inventory_value) as batch_value');

        $query = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_inventory_balances as aggregate', function ($join) use ($warehouseId): void {
                $join->on('aggregate.sku_id', '=', 'sku.id')->where('aggregate.outlet_id', '=', $warehouseId);
            })
            ->leftJoinSub($batchTotals, 'batch', fn ($join) => $join->on('batch.sku_id', '=', 'sku.id'))
            ->where(function ($builder): void {
                $builder->whereNotNull('aggregate.id')->orWhereNotNull('batch.sku_id');
            })
            ->select([
                'sku.id as sku_id', 'sku.sku_code', 'sku.name as item_name', 'uom.code as base_uom_code',
                DB::raw('COALESCE(aggregate.on_hand_qty, 0) as aggregate_qty'),
                DB::raw('COALESCE(aggregate.average_unit_cost, 0) as aggregate_average_cost'),
                DB::raw('COALESCE(aggregate.inventory_value, 0) as aggregate_value'),
                DB::raw('COALESCE(batch.batch_qty, 0) as batch_qty'),
                DB::raw('COALESCE(batch.batch_value, 0) as batch_value'),
                DB::raw('(COALESCE(aggregate.on_hand_qty, 0) - COALESCE(batch.batch_qty, 0)) as qty_variance'),
                DB::raw('(COALESCE(aggregate.inventory_value, 0) - COALESCE(batch.batch_value, 0)) as value_variance'),
            ]);

        if ($q !== '') {
            $query->where(fn ($builder) => $builder
                ->where('sku.sku_code', 'like', "%{$q}%")
                ->orWhere('sku.name', 'like', "%{$q}%"));
        }
        if ($onlyVariance) {
            $query->whereRaw('ABS(COALESCE(aggregate.on_hand_qty, 0) - COALESCE(batch.batch_qty, 0)) > 0.0001 OR ABS(COALESCE(aggregate.inventory_value, 0) - COALESCE(batch.batch_value, 0)) > 0.01');
        }

        $paginator = $query->orderBy('sku.name')->paginate($perPage);
        $items = collect($paginator->items())->map(function ($row): array {
            $batchQty = round((float) $row->batch_qty, 4);
            $batchValue = round((float) $row->batch_value, 2);
            $calculatedAverage = abs($batchQty) > 0.0001 ? round($batchValue / $batchQty, 6) : 0.0;
            $qtyVariance = round((float) $row->qty_variance, 4);
            $valueVariance = round((float) $row->value_variance, 2);

            return [
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'base_uom_code' => (string) ($row->base_uom_code ?? ''),
                'aggregate_qty' => round((float) $row->aggregate_qty, 4),
                'batch_qty' => $batchQty,
                'qty_variance' => $qtyVariance,
                'aggregate_average_cost' => round((float) $row->aggregate_average_cost, 6),
                'calculated_average_cost' => $calculatedAverage,
                'aggregate_value' => round((float) $row->aggregate_value, 2),
                'batch_value' => $batchValue,
                'value_variance' => $valueVariance,
                'is_reconciled' => abs($qtyVariance) <= 0.0001 && abs($valueVariance) <= 0.01,
            ];
        })->values();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $this->summary($warehouseId),
        ];
    }

    public function summary(string $warehouseId): array
    {
        $batch = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) as qty, COALESCE(SUM(inventory_value), 0) as value, COUNT(DISTINCT sku_id) as sku_count')
            ->first();
        $aggregate = DB::table('stk_inventory_balances')
            ->where('outlet_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) as qty, COALESCE(SUM(inventory_value), 0) as value, COUNT(*) as sku_count')
            ->first();

        $varianceCount = $this->varianceQuery($warehouseId)->count();
        $negativeBatchCount = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('on_hand_qty', '<', -0.0001)
            ->count();
        $negativeAggregateCount = DB::table('stk_inventory_balances')
            ->where('outlet_id', $warehouseId)
            ->where('on_hand_qty', '<', -0.0001)
            ->count();

        return [
            'aggregate_qty' => round((float) ($aggregate->qty ?? 0), 4),
            'batch_qty' => round((float) ($batch->qty ?? 0), 4),
            'qty_variance' => round((float) ($aggregate->qty ?? 0) - (float) ($batch->qty ?? 0), 4),
            'aggregate_value' => round((float) ($aggregate->value ?? 0), 2),
            'batch_value' => round((float) ($batch->value ?? 0), 2),
            'value_variance' => round((float) ($aggregate->value ?? 0) - (float) ($batch->value ?? 0), 2),
            'sku_count' => max((int) ($aggregate->sku_count ?? 0), (int) ($batch->sku_count ?? 0)),
            'variance_sku_count' => $varianceCount,
            'negative_balance_count' => $negativeBatchCount + $negativeAggregateCount,
            'unprojected_entry_count' => DB::table('wh_ledger_entries')->where('warehouse_id', $warehouseId)->whereNull('projection_movement_id')->count(),
            'processing_posting_count' => DB::table('wh_ledger_postings')->where('warehouse_id', $warehouseId)->where('status', 'processing')->count(),
        ];
    }

    public function run(string $warehouseId, bool $repair, ?string $userId = null, ?string $skuId = null): WarehouseReconciliationRun
    {
        $run = WarehouseReconciliationRun::query()->create([
            'warehouse_id' => $warehouseId,
            'mode' => $repair ? 'repair' : 'check',
            'status' => 'running',
            'executed_by_user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            $skuIds = $this->skuIds($warehouseId, $skuId);
            $varianceCount = 0;
            $repairCount = 0;
            $negativeCount = 0;

            foreach ($skuIds as $currentSkuId) {
                $result = DB::transaction(function () use ($warehouseId, $currentSkuId, $repair): array {
                    DB::table('stk_inventory_balances')->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'outlet_id' => $warehouseId,
                        'sku_id' => $currentSkuId,
                        'on_hand_qty' => 0,
                        'average_unit_cost' => 0,
                        'inventory_value' => 0,
                        'lock_version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $aggregate = InventoryBalance::query()
                        ->where('outlet_id', $warehouseId)
                        ->where('sku_id', $currentSkuId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $batch = DB::table('wh_batch_balances')
                        ->where('warehouse_id', $warehouseId)
                        ->where('sku_id', $currentSkuId)
                        ->selectRaw('COALESCE(SUM(on_hand_qty), 0) as qty, COALESCE(SUM(inventory_value), 0) as value')
                        ->first();

                    $qty = round((float) ($batch->qty ?? 0), 4);
                    $value = round((float) ($batch->value ?? 0), 2);
                    if (abs($qty) < 0.0001) {
                        $qty = 0.0;
                        $value = 0.0;
                    }
                    $average = abs($qty) > 0.0001 ? round($value / $qty, 6) : 0.0;
                    $hasVariance = abs((float) $aggregate->on_hand_qty - $qty) > 0.0001
                        || abs((float) $aggregate->inventory_value - $value) > 0.01
                        || abs((float) $aggregate->average_unit_cost - $average) > 0.0001;

                    if ($repair && $hasVariance) {
                        $aggregate->forceFill([
                            'on_hand_qty' => $qty,
                            'average_unit_cost' => $average,
                            'inventory_value' => $value,
                            'last_movement_at' => now(),
                            'lock_version' => ((int) $aggregate->lock_version) + 1,
                        ])->save();
                    }

                    return [
                        'variance' => $hasVariance,
                        'repaired' => $repair && $hasVariance,
                        'negative' => $qty < -0.0001,
                    ];
                }, 3);

                $varianceCount += $result['variance'] ? 1 : 0;
                $repairCount += $result['repaired'] ? 1 : 0;
                $negativeCount += $result['negative'] ? 1 : 0;
            }

            $summary = $this->summary($warehouseId);
            $run->forceFill([
                'status' => 'completed',
                'checked_sku_count' => count($skuIds),
                'variance_sku_count' => $varianceCount,
                'repaired_sku_count' => $repairCount,
                'negative_balance_count' => $negativeCount,
                'summary' => $summary,
                'completed_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'summary' => ['error' => $exception->getMessage()],
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }

        return $run->fresh();
    }

    private function skuIds(string $warehouseId, ?string $skuId): array
    {
        if ($skuId) {
            return [$skuId];
        }

        return DB::query()
            ->fromSub(
                DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->select('sku_id')
                    ->union(DB::table('wh_batch_balances')->where('warehouse_id', $warehouseId)->select('sku_id')),
                'source'
            )
            ->distinct()
            ->orderBy('sku_id')
            ->pluck('sku_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function varianceQuery(string $warehouseId)
    {
        $batchTotals = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(on_hand_qty) as qty, SUM(inventory_value) as value');

        return DB::table('stk_skus as sku')
            ->leftJoin('stk_inventory_balances as aggregate', function ($join) use ($warehouseId): void {
                $join->on('aggregate.sku_id', '=', 'sku.id')->where('aggregate.outlet_id', '=', $warehouseId);
            })
            ->leftJoinSub($batchTotals, 'batch', fn ($join) => $join->on('batch.sku_id', '=', 'sku.id'))
            ->where(function ($builder): void {
                $builder->whereNotNull('aggregate.id')->orWhereNotNull('batch.sku_id');
            })
            ->whereRaw('ABS(COALESCE(aggregate.on_hand_qty, 0) - COALESCE(batch.qty, 0)) > 0.0001 OR ABS(COALESCE(aggregate.inventory_value, 0) - COALESCE(batch.value, 0)) > 0.01');
    }
}
