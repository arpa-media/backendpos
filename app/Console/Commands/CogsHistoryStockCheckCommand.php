<?php

namespace App\Console\Commands;

use App\Services\Cogs\PurchasingCostSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CogsHistoryStockCheckCommand extends Command
{
    protected $signature = 'cogs:history-stock-check {--repair-snapshots : Backfill missing immutable purchasing cost snapshots and movement values}';

    protected $description = 'Validate History Stock, purchasing cost snapshots, traceability routes, permissions, and Access Matrix.';

    public function handle(PurchasingCostSnapshotService $snapshots): int
    {
        if ($this->option('repair-snapshots')) {
            $movementValues = $snapshots->repairMovementValues();
            $backfill = $snapshots->backfillReleasedReceipts();
            $this->info(sprintf(
                'Repair selesai: %d movement values diperbarui, %d receipt diperiksa, %d snapshot dibuat.',
                $movementValues,
                $backfill['receipts'],
                $backfill['snapshots_created'],
            ));
        }

        $requiredTables = [
            'cogs_purchasing_cost_snapshots',
            'stk_goods_receipts',
            'stk_goods_receipt_items',
            'stk_inventory_balances',
            'stk_inventory_movements',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn ($table) => ! Schema::hasTable($table)));

        $requiredSnapshotColumns = [
            'goods_receipt_id', 'goods_receipt_item_id', 'inventory_movement_id', 'outlet_id', 'sku_id',
            'gr_number_snapshot', 'receipt_type_snapshot', 'receipt_date', 'supplier_name_snapshot',
            'request_number_snapshot', 'po_number_snapshot', 'sku_code_snapshot', 'sku_name_snapshot', 'received_qty', 'unit_cost',
            'line_total', 'average_cost_before', 'average_cost_after', 'inventory_value_after', 'source_snapshot',
        ];
        $missingColumns = [];
        if (Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            foreach ($requiredSnapshotColumns as $column) {
                if (! Schema::hasColumn('cogs_purchasing_cost_snapshots', $column)) {
                    $missingColumns[] = 'cogs_purchasing_cost_snapshots.'.$column;
                }
            }
        }
        if (Schema::hasTable('stk_inventory_movements') && ! Schema::hasColumn('stk_inventory_movements', 'inventory_value_after')) {
            $missingColumns[] = 'stk_inventory_movements.inventory_value_after';
        }

        $requiredRoutes = [
            'cogs.history-stock.catalogs',
            'cogs.history-stock.traceability',
            'cogs.history-stock.movements',
            'cogs.history-stock.average-cost-timeline',
            'cogs.history-stock.export',
            'cogs.history-stock.index',
            'cogs.history-stock.show',
        ];
        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
        $missingRoutes = array_values(array_diff($requiredRoutes, $routeNames));

        $requiredPermissions = collect(['view', 'create', 'update', 'delete'])
            ->map(fn ($action) => 'cogs.history_stock.'.$action)
            ->all();
        $missingPermissions = Schema::hasTable('permissions')
            ? array_values(array_diff($requiredPermissions, DB::table('permissions')->whereIn('name', $requiredPermissions)->pluck('name')->all()))
            : $requiredPermissions;

        $menu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'cogs-history-stock')->where('path', '/cogs/history-stock')->where('is_active', true)->first()
            : null;

        $releasedLineCount = 0;
        $snapshotCount = 0;
        $missingSnapshots = 0;
        $untracedSnapshots = 0;
        $orphanSnapshots = 0;
        $movementValueMissing = 0;
        $invalidSnapshotStatus = 0;
        $costMismatch = 0;
        $balancesWithoutMovement = 0;

        if ($missingTables === [] && Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            $releasedLineCount = DB::table('stk_goods_receipt_items as gri')
                ->join('stk_goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
                ->where('gr.status', 'released')
                ->count();
            $snapshotCount = DB::table('cogs_purchasing_cost_snapshots')->count();
            $missingSnapshots = DB::table('stk_goods_receipt_items as gri')
                ->join('stk_goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
                ->leftJoin('cogs_purchasing_cost_snapshots as pcs', 'pcs.goods_receipt_item_id', '=', 'gri.id')
                ->where('gr.status', 'released')
                ->whereNull('pcs.id')
                ->count();
            $untracedSnapshots = DB::table('cogs_purchasing_cost_snapshots')->whereNull('inventory_movement_id')->count();
            $orphanSnapshots = DB::table('cogs_purchasing_cost_snapshots as pcs')
                ->leftJoin('stk_goods_receipt_items as gri', 'gri.id', '=', 'pcs.goods_receipt_item_id')
                ->whereNull('gri.id')
                ->count();
            $movementValueMissing = DB::table('stk_inventory_movements')->whereNull('inventory_value_after')->count();
            $invalidSnapshotStatus = DB::table('cogs_purchasing_cost_snapshots')->where('status_snapshot', '!=', 'released')->count();
            $costMismatch = DB::table('cogs_purchasing_cost_snapshots')
                ->whereRaw('ABS(line_total - ROUND(received_qty * unit_cost, 2)) > 0.02')
                ->count();
            $balancesWithoutMovement = DB::table('stk_inventory_balances as b')
                ->leftJoin('stk_inventory_movements as im', function ($join): void {
                    $join->on('im.outlet_id', '=', 'b.outlet_id')->on('im.sku_id', '=', 'b.sku_id');
                })
                ->whereNull('im.id')
                ->where(function ($query): void {
                    $query->where('b.on_hand_qty', '!=', 0)->orWhere('b.inventory_value', '!=', 0);
                })
                ->count();
        }

        $rows = [
            ['Missing tables', $this->display($missingTables)],
            ['Missing columns', $this->display($missingColumns)],
            ['Missing named routes', $this->display($missingRoutes)],
            ['History Stock Access Matrix menu', $menu ? 'OK' : 'MISSING'],
            ['Missing permissions', $this->display($missingPermissions)],
            ['Released GR item lines', (string) $releasedLineCount],
            ['Purchasing cost snapshots', (string) $snapshotCount],
            ['Released lines without snapshot', (string) $missingSnapshots],
            ['Snapshots without movement trace', (string) $untracedSnapshots],
            ['Orphan snapshots', (string) $orphanSnapshots],
            ['Movements without inventory value', (string) $movementValueMissing],
            ['Snapshots with invalid release status', (string) $invalidSnapshotStatus],
            ['Snapshot cost formula mismatches', (string) $costMismatch],
            ['Non-zero balances without movement', (string) $balancesWithoutMovement],
        ];

        $failed = $missingTables !== []
            || $missingColumns !== []
            || $missingRoutes !== []
            || $missingPermissions !== []
            || ! $menu
            || $missingSnapshots > 0
            || $orphanSnapshots > 0
            || $movementValueMissing > 0
            || $invalidSnapshotStatus > 0
            || $costMismatch > 0;

        // Legacy released receipts may have no active stk_inventory_movements. This is surfaced
        // as ATTENTION but does not invalidate immutable price history.
        $attention = $untracedSnapshots > 0 || $balancesWithoutMovement > 0;
        $status = $failed ? 'FAILED' : ($attention ? 'PASSED_WITH_ATTENTION' : 'PASSED');
        $rows[] = ['Status', $status];
        $this->table(['Check', 'Result'], $rows);

        if ($untracedSnapshots > 0) {
            $this->warn('Sebagian GR legacy belum memiliki active inventory movement. Harga tetap terlindungi oleh snapshot, tetapi timeline balance perlu rekonsiliasi ledger legacy sebelum posting COGS final.');
        }
        if ($balancesWithoutMovement > 0) {
            $this->warn('Terdapat saldo legacy non-zero tanpa movement aktif. Jangan membuat movement GR sintetis; rekonsiliasikan sebagai opening balance terkontrol sebelum posting COGS final.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function display(array $items): string
    {
        return $items === [] ? '-' : implode(', ', $items);
    }
}
