<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseReceivingCheckCommand extends Command
{
    protected $signature = 'warehouse:receiving-check';
    protected $description = 'Validate Warehouse Iterasi 07 receiving, Goods Receipt, discrepancy, and in-transit contracts.';

    public function handle(): int
    {
        $tables = ['wh_receivings', 'wh_receiving_items', 'wh_receiving_units'];
        $routes = [
            'warehouse.outlet-receivings.index', 'warehouse.outlet-receivings.show', 'warehouse.outlet-receivings.start',
            'warehouse.outlet-receivings.scan', 'warehouse.outlet-receivings.units.resolve', 'warehouse.outlet-receivings.goods-receipt',
            'warehouse.outlet-receivings.print-event', 'warehouse.receivings.index', 'warehouse.receivings.show',
            'warehouse.receivings.units.confirm-return', 'warehouse.receivings.units.close-missing', 'warehouse.receivings.print-event',
        ];
        $menus = ['inventory-warehouse-receiving', 'warehouse-goods-receipts'];
        $permissions = [
            'warehouse.receiving.outlet.view', 'warehouse.receiving.outlet.create', 'warehouse.receiving.outlet.update',
            'warehouse.receiving.outlet.scan', 'warehouse.receiving.outlet.resolve',
            'warehouse.receiving.goods_receipt.generate', 'warehouse.receiving.goods_receipt.print',
            'warehouse.receiving.monitor.view', 'warehouse.receiving.monitor.update',
            'warehouse.receiving.discrepancy.return', 'warehouse.receiving.discrepancy.close',
        ];

        $missingTables = array_values(array_filter($tables, fn ($table) => ! Schema::hasTable($table)));
        $missingColumns = [];
        if (! Schema::hasColumn('stk_goods_receipt_items', 'warehouse_receiving_item_id')) {
            $missingColumns[] = 'stk_goods_receipt_items.warehouse_receiving_item_id';
        }
        $named = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
        $missingRoutes = array_values(array_diff($routes, $named));
        $missingMenus = Schema::hasTable('access_menus')
            ? array_values(array_diff($menus, DB::table('access_menus')->whereIn('code', $menus)->pluck('code')->all()))
            : $menus;
        $missingPermissions = Schema::hasTable('permissions')
            ? array_values(array_diff($permissions, DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all()))
            : $permissions;

        $skip = $missingTables !== [];
        $itemUnitMismatch = $skip ? -1 : DB::table('wh_receiving_items as i')
            ->leftJoinSub(
                DB::table('wh_receiving_units')->select('receiving_item_id')->selectRaw('SUM(qty_base) as unit_qty')->groupBy('receiving_item_id'),
                'u', 'u.receiving_item_id', '=', 'i.id'
            )
            ->whereRaw('ABS(i.expected_qty_base - COALESCE(u.unit_qty, 0)) > 0.0001')->count();
        $finalWithPending = $skip ? -1 : DB::table('wh_receivings as r')
            ->join('wh_receiving_units as u', 'u.receiving_id', '=', 'r.id')
            ->where('r.status', 'goods_receipt')->where('u.status', 'pending')->count();
        $finalWithoutReceipt = $skip ? -1 : DB::table('wh_receivings')->where('status', 'goods_receipt')->whereNull('stock_goods_receipt_id')->count();
        $goodsReceivedWithoutReceiving = $skip ? -1 : DB::table('wh_delivery_orders as d')
            ->leftJoin('wh_receivings as r', 'r.delivery_order_id', '=', 'd.id')
            ->where('d.status', 'goods_received')->whereNull('r.stock_goods_receipt_id')->count();
        $requestStatusMismatch = $skip ? -1 : DB::table('wh_receivings as r')
            ->join('stk_requests as sr', 'sr.id', '=', 'r.stock_request_id')
            ->where('r.status', 'goods_receipt')->where('sr.status', '<>', 'goods-receipt')->count();
        $receivedUnitMismatch = $skip ? -1 : DB::table('wh_receiving_units as ru')
            ->join('wh_stock_units as su', 'su.id', '=', 'ru.stock_unit_id')
            ->where('ru.status', 'received')->where('su.status', '<>', 'received')->count();
        $returnUnitMismatch = $skip ? -1 : DB::table('wh_receiving_units as ru')
            ->join('wh_stock_units as su', 'su.id', '=', 'ru.stock_unit_id')
            ->where('ru.status', 'return_pending')->where('su.status', '<>', 'return_in_transit')->count();
        $returnedWithoutLedger = $skip ? -1 : DB::table('wh_receiving_units')->where('status', 'returned')->whereNull('warehouse_resolution_posting_id')->count();
        $missingStatusMismatch = $skip ? -1 : DB::table('wh_receiving_units as ru')
            ->join('wh_stock_units as su', 'su.id', '=', 'ru.stock_unit_id')
            ->where('ru.status', 'missing_closed')->where('su.status', '<>', 'missing')->count();
        $positiveReceiptWithoutMovement = $skip ? -1 : DB::table('stk_goods_receipt_items as gri')
            ->join('wh_receivings as r', 'r.stock_goods_receipt_id', '=', 'gri.goods_receipt_id')
            ->leftJoin('stk_inventory_movements as m', function ($join): void {
                $join->on('m.reference_line_id', '=', 'gri.id')
                    ->where('m.movement_type', '=', 'goods_receipt')
                    ->where('m.reference_type', '=', 'stk_goods_receipt');
            })
            ->where('gri.received_qty', '>', 0)->whereNull('m.id')->count();
        $grQtyMismatch = $skip ? -1 : DB::table('wh_receiving_items as ri')
            ->join('stk_goods_receipt_items as gi', 'gi.warehouse_receiving_item_id', '=', 'ri.id')
            ->whereRaw('ABS(ri.received_qty_base - gi.received_qty) > 0.0001')->count();

        $rows = [
            ['Missing tables', $this->text($missingTables)],
            ['Missing receipt-item columns', $this->text($missingColumns)],
            ['Missing named routes', $this->text($missingRoutes)],
            ['Missing Access Matrix menus', $this->text($missingMenus)],
            ['Missing permissions', $this->text($missingPermissions)],
            ['DO item vs barcode qty mismatch', $itemUnitMismatch],
            ['Final receiving with pending barcode', $finalWithPending],
            ['Final receiving without Goods Receipt', $finalWithoutReceipt],
            ['Goods-received DO without receipt', $goodsReceivedWithoutReceiving],
            ['Goods Receipt request status mismatch', $requestStatusMismatch],
            ['Received barcode status mismatch', $receivedUnitMismatch],
            ['Return-pending barcode status mismatch', $returnUnitMismatch],
            ['Returned barcode without return ledger', $returnedWithoutLedger],
            ['Closed missing barcode status mismatch', $missingStatusMismatch],
            ['Positive GR item without outlet movement', $positiveReceiptWithoutMovement],
            ['Receiving vs GR received qty mismatch', $grQtyMismatch],
        ];

        $counts = [$itemUnitMismatch, $finalWithPending, $finalWithoutReceipt, $goodsReceivedWithoutReceiving, $requestStatusMismatch, $receivedUnitMismatch, $returnUnitMismatch, $returnedWithoutLedger, $missingStatusMismatch, $positiveReceiptWithoutMovement, $grQtyMismatch];
        $failed = $missingTables || $missingColumns || $missingRoutes || $missingMenus || $missingPermissions
            || collect($counts)->contains(fn ($value) => $value !== 0);
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function text(array $items): string
    {
        return $items === [] ? '-' : implode(', ', $items);
    }
}
