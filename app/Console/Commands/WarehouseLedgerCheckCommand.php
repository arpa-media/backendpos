<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseLedgerCheckCommand extends Command
{
    protected $signature = 'warehouse:ledger-check';

    protected $description = 'Memeriksa kontrak ledger, projection, valuation, dan reconciliation Warehouse Iterasi 04.';

    public function handle(): int
    {
        $requiredTables = [
            'wh_ledger_postings', 'wh_ledger_entries', 'wh_reconciliation_runs',
            'wh_batch_balances', 'stk_inventory_balances', 'stk_inventory_movements',
        ];
        $requiredRoutes = [
            'warehouse.ledger.options', 'warehouse.ledger.history.index',
            'warehouse.ledger.adjustments.store', 'warehouse.ledger.postings.reverse',
            'warehouse.ledger.reconciliation.index', 'warehouse.ledger.reconciliation.repair',
        ];
        $requiredMenus = [
            'warehouse-ledger-history', 'warehouse-ledger-adjustments', 'warehouse-ledger-reconciliation',
        ];
        $requiredPermissions = [
            'warehouse.ledger.history.view', 'warehouse.ledger.adjustment.create',
            'warehouse.ledger.adjustment.reverse', 'warehouse.ledger.negative_override',
            'warehouse.ledger.reconciliation.view', 'warehouse.ledger.reconciliation.repair',
        ];

        $missingTables = collect($requiredTables)->reject(fn ($table) => Schema::hasTable($table))->values();
        $missingRoutes = collect($requiredRoutes)->reject(fn ($name) => Route::has($name))->values();
        $missingMenus = Schema::hasTable('access_menus')
            ? collect($requiredMenus)->reject(fn ($code) => DB::table('access_menus')->where('code', $code)->exists())->values()
            : collect($requiredMenus);
        $missingPermissions = Schema::hasTable('permissions')
            ? collect($requiredPermissions)->reject(fn ($name) => DB::table('permissions')->where('name', $name)->exists())->values()
            : collect($requiredPermissions);

        $varianceSku = $this->varianceCount();
        $negativeBatch = Schema::hasTable('wh_batch_balances')
            ? DB::table('wh_batch_balances')->where('on_hand_qty', '<', -0.0001)->count()
            : -1;
        $negativeAggregate = Schema::hasTable('stk_inventory_balances')
            ? DB::table('stk_inventory_balances as balance')
                ->join('outlets as warehouse', 'warehouse.id', '=', 'balance.outlet_id')
                ->whereRaw("LOWER(COALESCE(warehouse.type, '')) = 'warehouse'")
                ->where('balance.on_hand_qty', '<', -0.0001)
                ->count()
            : -1;
        $processingPostings = Schema::hasTable('wh_ledger_postings')
            ? DB::table('wh_ledger_postings')->where('status', 'processing')->count()
            : -1;
        $unprojectedEntries = Schema::hasTable('wh_ledger_entries')
            ? DB::table('wh_ledger_entries')->whereNull('projection_movement_id')->count()
            : -1;
        $orphanProjection = $this->orphanProjectionCount();
        $duplicateProjection = Schema::hasTable('wh_ledger_entries')
            ? DB::table('wh_ledger_entries')->whereNotNull('projection_movement_id')
                ->select('projection_movement_id')->groupBy('projection_movement_id')->havingRaw('COUNT(*) > 1')->count()
            : -1;

        $passed = $missingTables->isEmpty()
            && $missingRoutes->isEmpty()
            && $missingMenus->isEmpty()
            && $missingPermissions->isEmpty()
            && $varianceSku === 0
            && $processingPostings === 0
            && $unprojectedEntries === 0
            && $orphanProjection === 0
            && $duplicateProjection === 0;

        $negativeWarning = ($negativeBatch + $negativeAggregate) > 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->implode(', ') ?: '-'],
            ['Missing named routes', $missingRoutes->implode(', ') ?: '-'],
            ['Missing Access Matrix menus', $missingMenus->implode(', ') ?: '-'],
            ['Missing permissions', $missingPermissions->implode(', ') ?: '-'],
            ['Aggregate vs batch variance SKU', (string) $varianceSku],
            ['Negative batch balances', (string) $negativeBatch],
            ['Negative aggregate balances', (string) $negativeAggregate],
            ['Processing postings', (string) $processingPostings],
            ['Unprojected ledger entries', (string) $unprojectedEntries],
            ['Orphan projection movements', (string) $orphanProjection],
            ['Duplicate projection references', (string) $duplicateProjection],
            ['Status', $passed ? ($negativeWarning ? 'PASSED WITH NEGATIVE BALANCE WARNING' : 'PASSED') : 'FAILED'],
        ]);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function varianceCount(): int
    {
        if (! Schema::hasTable('stk_inventory_balances') || ! Schema::hasTable('wh_batch_balances')) {
            return -1;
        }

        $batch = DB::table('wh_batch_balances')
            ->selectRaw('warehouse_id, sku_id, SUM(on_hand_qty) as qty, SUM(inventory_value) as value')
            ->groupBy('warehouse_id', 'sku_id');

        return DB::table('stk_skus as sku')
            ->join('outlets as warehouse', fn ($join) => $join->whereRaw("LOWER(COALESCE(warehouse.type, '')) = 'warehouse'"))
            ->leftJoin('stk_inventory_balances as aggregate', function ($join): void {
                $join->on('aggregate.sku_id', '=', 'sku.id')->on('aggregate.outlet_id', '=', 'warehouse.id');
            })
            ->leftJoinSub($batch, 'batch', function ($join): void {
                $join->on('batch.sku_id', '=', 'sku.id')->on('batch.warehouse_id', '=', 'warehouse.id');
            })
            ->where(function ($query): void {
                $query->whereNotNull('aggregate.id')->orWhereNotNull('batch.sku_id');
            })
            ->whereRaw('ABS(COALESCE(aggregate.on_hand_qty, 0) - COALESCE(batch.qty, 0)) > 0.0001 OR ABS(COALESCE(aggregate.inventory_value, 0) - COALESCE(batch.value, 0)) > 0.01')
            ->count();
    }

    private function orphanProjectionCount(): int
    {
        if (! Schema::hasTable('wh_ledger_entries') || ! Schema::hasTable('stk_inventory_movements')) {
            return -1;
        }

        return DB::table('wh_ledger_entries as entry')
            ->leftJoin('stk_inventory_movements as movement', 'movement.id', '=', 'entry.projection_movement_id')
            ->whereNotNull('entry.projection_movement_id')
            ->whereNull('movement.id')
            ->count();
    }
}
