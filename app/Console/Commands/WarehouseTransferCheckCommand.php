<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseTransferCheckCommand extends Command
{
    protected $signature = 'warehouse:transfer-check';
    protected $description = 'Validate Warehouse Iterasi 10 stock transfer, checker, in-transit, batch lineage, receiving, and ledger contracts.';

    public function handle(): int
    {
        $tables = [
            'wh_stock_transfers', 'wh_stock_transfer_items', 'wh_stock_transfer_tasks',
            'wh_stock_transfer_allocations', 'wh_transfer_delivery_orders',
            'wh_stock_transfer_units', 'wh_transfer_batch_lineages',
        ];
        $routes = [
            'warehouse.transfers.options', 'warehouse.transfers.index', 'warehouse.transfers.store', 'warehouse.transfers.show',
            'warehouse.transfers.update', 'warehouse.transfers.submit', 'warehouse.transfers.assign', 'warehouse.transfers.dispatch',
            'warehouse.transfers.start-receiving', 'warehouse.transfers.receiving-scans', 'warehouse.transfers.units.resolve',
            'warehouse.transfers.complete-receiving', 'warehouse.transfers.units.confirm-return', 'warehouse.transfers.units.close-missing',
            'warehouse.transfers.print-event', 'warehouse.checker-transfer-tasks.index', 'warehouse.checker-transfer-tasks.show',
            'warehouse.checker-transfer-tasks.scan', 'warehouse.checker-transfer-tasks.allocations.destroy',
            'warehouse.checker-transfer-tasks.confirm-shortage',
        ];
        $menus = ['warehouse-stock-transfers', 'warehouse-checker-transfer-tasks'];
        $permissions = [
            'warehouse.transfer.view', 'warehouse.transfer.create', 'warehouse.transfer.update', 'warehouse.transfer.submit',
            'warehouse.transfer.assign', 'warehouse.transfer.dispatch', 'warehouse.transfer.receive',
            'warehouse.transfer.discrepancy.resolve', 'warehouse.transfer.print',
            'warehouse.transfer.checker.view', 'warehouse.transfer.checker.update', 'warehouse.transfer.checker.scan',
            'warehouse.transfer.checker.override',
        ];

        $missingTables = array_values(array_filter($tables, fn ($table) => ! Schema::hasTable($table)));
        $missingRoutes = array_values(array_filter($routes, fn ($name) => ! Route::has($name)));
        $missingMenus = Schema::hasTable('access_menus')
            ? array_values(array_diff($menus, DB::table('access_menus')->whereIn('code', $menus)->pluck('code')->all()))
            : $menus;
        $missingPermissions = Schema::hasTable('permissions')
            ? array_values(array_diff($permissions, DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all()))
            : $permissions;

        $metrics = [
            'Origin equals destination' => 0,
            'Transfer without items' => 0,
            'Ready transfer with incomplete task' => 0,
            'Duplicate active barcode allocation' => 0,
            'Reserved allocation/unit mismatch' => 0,
            'Dispatched allocation/unit mismatch' => 0,
            'On-delivery without DO/transfer_out' => 0,
            'Receiving/completed with pending unit' => 0,
            'Received unit without lineage/batch' => 0,
            'Received unit barcode not available at destination' => 0,
            'Return-pending barcode mismatch' => 0,
            'Returned unit without return ledger' => 0,
            'Missing-closed barcode mismatch' => 0,
            'Received qty without transfer_in ledger' => 0,
            'Transfer quantity reconciliation mismatch' => 0,
            'Batch lineage cost mismatch' => 0,
            'Duplicate transfer ledger phase' => 0,
        ];

        if ($missingTables === []) {
            $metrics['Origin equals destination'] = (int) DB::table('wh_stock_transfers')->whereColumn('origin_warehouse_id', 'destination_warehouse_id')->count();
            $metrics['Transfer without items'] = (int) DB::table('wh_stock_transfers as t')
                ->leftJoin('wh_stock_transfer_items as i', 'i.transfer_id', '=', 't.id')
                ->select('t.id')->groupBy('t.id')->havingRaw('COUNT(i.id) = 0')->get()->count();
            $metrics['Duplicate active barcode allocation'] = (int) DB::table('wh_stock_transfer_allocations')
                ->whereIn('status', ['reserved', 'dispatched', 'receiving', 'return_pending', 'not_received'])
                ->select('stock_unit_id')->groupBy('stock_unit_id')->havingRaw('COUNT(*) > 1')->get()->count();
            $metrics['Ready transfer with incomplete task'] = (int) DB::table('wh_stock_transfers as t')
                ->join('wh_stock_transfer_items as i', 'i.transfer_id', '=', 't.id')
                ->leftJoin('wh_stock_transfer_tasks as k', 'k.transfer_item_id', '=', 'i.id')
                ->whereIn('t.status', ['ready', 'on_delivery', 'receiving', 'received', 'discrepancy', 'completed'])
                ->where(fn ($query) => $query->whereNull('k.id')->orWhere('k.status', '!=', 'completed'))->count();
            $metrics['Reserved allocation/unit mismatch'] = (int) DB::table('wh_stock_transfer_allocations as a')
                ->join('wh_stock_units as u', 'u.id', '=', 'a.stock_unit_id')
                ->where('a.status', 'reserved')->where('u.status', '!=', 'reserved')->count();
            $metrics['Dispatched allocation/unit mismatch'] = (int) DB::table('wh_stock_transfer_allocations as a')
                ->join('wh_stock_units as u', 'u.id', '=', 'a.stock_unit_id')
                ->whereIn('a.status', ['dispatched', 'receiving'])
                ->whereNotIn('u.status', ['in_transit', 'receiving', 'return_in_transit', 'missing_in_transit'])->count();
            $metrics['On-delivery without DO/transfer_out'] = (int) DB::table('wh_stock_transfers as t')
                ->leftJoin('wh_transfer_delivery_orders as d', 'd.transfer_id', '=', 't.id')
                ->whereIn('t.status', ['on_delivery', 'receiving', 'received', 'discrepancy', 'completed'])
                ->where(fn ($query) => $query->whereNull('d.id')->orWhereNull('d.dispatch_ledger_posting_id'))->count();
            $metrics['Receiving/completed with pending unit'] = (int) DB::table('wh_stock_transfers as t')
                ->join('wh_stock_transfer_units as u', 'u.transfer_id', '=', 't.id')
                ->whereIn('t.status', ['received', 'discrepancy', 'completed'])->where('u.status', 'pending')->count();
            $metrics['Received unit without lineage/batch'] = (int) DB::table('wh_stock_transfer_units as u')
                ->leftJoin('wh_transfer_batch_lineages as l', function ($join): void {
                    $join->on('l.transfer_id', '=', 'u.transfer_id')
                        ->on('l.transfer_item_id', '=', 'u.transfer_item_id')
                        ->on('l.origin_batch_id', '=', 'u.origin_batch_id')
                        ->on('l.destination_storage_id', '=', 'u.destination_storage_id');
                })
                ->where('u.status', 'received')
                ->where(fn ($query) => $query->whereNull('u.destination_batch_id')->orWhereNull('l.id'))->count();
            $metrics['Received unit barcode not available at destination'] = (int) DB::table('wh_stock_transfer_units as tu')
                ->join('wh_stock_transfers as t', 't.id', '=', 'tu.transfer_id')
                ->join('wh_stock_units as su', 'su.id', '=', 'tu.stock_unit_id')
                ->where('tu.status', 'received')
                ->where(fn ($query) => $query->where('su.status', '!=', 'available')->orWhereColumn('su.warehouse_id', '!=', 't.destination_warehouse_id')->orWhereColumn('su.batch_id', '!=', 'tu.destination_batch_id'))->count();
            $metrics['Return-pending barcode mismatch'] = (int) DB::table('wh_stock_transfer_units as tu')
                ->join('wh_stock_units as su', 'su.id', '=', 'tu.stock_unit_id')
                ->where('tu.status', 'return_pending')->where('su.status', '!=', 'return_in_transit')->count();
            $metrics['Returned unit without return ledger'] = (int) DB::table('wh_stock_transfer_units as tu')
                ->join('wh_stock_transfers as t', 't.id', '=', 'tu.transfer_id')
                ->join('wh_stock_units as su', 'su.id', '=', 'tu.stock_unit_id')
                ->where('tu.status', 'returned')
                ->where(fn ($query) => $query->whereNull('tu.origin_resolution_posting_id')->orWhere('su.status', '!=', 'available')->orWhereColumn('su.warehouse_id', '!=', 't.origin_warehouse_id'))->count();
            $metrics['Missing-closed barcode mismatch'] = (int) DB::table('wh_stock_transfer_units as tu')
                ->join('wh_stock_units as su', 'su.id', '=', 'tu.stock_unit_id')
                ->where('tu.status', 'missing_closed')->where('su.status', '!=', 'missing')->count();
            $metrics['Received qty without transfer_in ledger'] = (int) DB::table('wh_stock_transfers')
                ->where('received_qty_base', '>', 0)->whereNull('receive_ledger_posting_id')->count();
            $metrics['Transfer quantity reconciliation mismatch'] = (int) DB::table('wh_stock_transfers as t')
                ->leftJoinSub(
                    DB::table('wh_stock_transfer_units')->selectRaw("transfer_id, SUM(qty_base) dispatched_qty, SUM(CASE WHEN status='received' THEN qty_base ELSE 0 END) received_qty, SUM(CASE WHEN status IN ('return_pending','returned') THEN qty_base ELSE 0 END) return_qty, SUM(CASE WHEN status IN ('not_received','missing_closed') THEN qty_base ELSE 0 END) missing_qty, SUM(CASE WHEN status='pending' THEN qty_base ELSE 0 END) pending_qty")->groupBy('transfer_id'),
                    'u', 'u.transfer_id', '=', 't.id'
                )
                ->whereIn('t.status', ['received', 'discrepancy', 'completed'])
                ->whereRaw('ABS(COALESCE(u.dispatched_qty,0) - (COALESCE(u.received_qty,0)+COALESCE(u.return_qty,0)+COALESCE(u.missing_qty,0)+COALESCE(u.pending_qty,0))) > 0.0001')
                ->count();
            $metrics['Batch lineage cost mismatch'] = (int) DB::table('wh_transfer_batch_lineages as l')
                ->join('wh_batches as b', 'b.id', '=', 'l.destination_batch_id')
                ->whereRaw('ABS(l.unit_cost_snapshot - b.actual_unit_cost) > 0.000001')->count();
            $metrics['Duplicate transfer ledger phase'] = (int) DB::table('wh_ledger_postings')
                ->where('reference_type', 'wh_stock_transfer')->whereIn('movement_type', ['transfer_out', 'transfer_in'])
                ->select('reference_id', 'movement_type')->groupBy('reference_id', 'movement_type')->havingRaw('COUNT(*) > 1')->get()->count();
        }

        $failed = $missingTables !== [] || $missingRoutes !== [] || $missingMenus !== [] || $missingPermissions !== []
            || collect($metrics)->contains(fn ($value) => $value > 0);
        $rows = [
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing named routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Missing Access Matrix menus', $missingMenus ? implode(', ', $missingMenus) : '-'],
            ['Missing permissions', $missingPermissions ? implode(', ', $missingPermissions) : '-'],
        ];
        foreach ($metrics as $name => $value) {
            $rows[] = [$name, (string) $value];
        }
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
