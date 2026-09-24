<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseFulfillmentCheckCommand extends Command
{
    protected $signature = 'warehouse:fulfillment-check';
    protected $description = 'Validate Warehouse Iterasi 06 fulfillment, checker prepare, reservation, and Delivery Order contracts.';

    public function handle(): int
    {
        $tables = ['wh_fulfillments', 'wh_fulfillment_items', 'wh_task_assignments', 'wh_fulfillment_allocations', 'wh_delivery_orders', 'wh_delivery_order_items'];
        $routes = [
            'warehouse.fulfillments.options', 'warehouse.fulfillments.index', 'warehouse.fulfillments.show',
            'warehouse.fulfillments.assign', 'warehouse.fulfillments.delivery-order',
            'warehouse.checker-prepare-tasks.index', 'warehouse.checker-prepare-tasks.show',
            'warehouse.checker-prepare-tasks.scan', 'warehouse.checker-prepare-tasks.allocations.destroy',
            'warehouse.checker-prepare-tasks.shortage', 'warehouse.delivery-orders.index',
            'warehouse.delivery-orders.show', 'warehouse.delivery-orders.print-event',
        ];
        $menus = ['warehouse-stock-request-fulfillment', 'warehouse-checker-prepare-tasks', 'warehouse-delivery-orders'];
        $permissions = [
            'warehouse.fulfillment.review.view', 'warehouse.fulfillment.assign', 'warehouse.fulfillment.task.view',
            'warehouse.fulfillment.task.scan', 'warehouse.fulfillment.task.shortage',
            'warehouse.delivery_order.view', 'warehouse.delivery_order.dispatch', 'warehouse.delivery_order.print',
        ];

        $missingTables = array_values(array_filter($tables, fn ($table) => ! Schema::hasTable($table)));
        $missingColumns = [];
        foreach (['ready_qty_base', 'shortage_qty_base', 'shortage_reason'] as $column) {
            if (! Schema::hasColumn('stk_request_items', $column)) $missingColumns[] = 'stk_request_items.'.$column;
        }
        $named = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->all();
        $missingRoutes = array_values(array_diff($routes, $named));
        $missingMenus = Schema::hasTable('access_menus') ? array_values(array_diff($menus, DB::table('access_menus')->whereIn('code', $menus)->pluck('code')->all())) : $menus;
        $missingPermissions = Schema::hasTable('permissions') ? array_values(array_diff($permissions, DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all())) : $permissions;

        $readyAboveScanned = $missingTables ? -1 : DB::table('wh_fulfillment_items')->whereColumn('ready_qty_base', '>', 'scanned_qty_base')->count();
        $prepareUnassigned = $missingTables ? -1 : DB::table('stk_requests as r')
            ->join('wh_fulfillments as f', 'f.stock_request_id', '=', 'r.id')
            ->join('wh_fulfillment_items as i', 'i.fulfillment_id', '=', 'f.id')
            ->where('r.status', 'prepare')->whereNull('i.assigned_checker_user_id')->count();
        $readyIncomplete = $missingTables ? -1 : DB::table('stk_requests as r')
            ->join('wh_fulfillments as f', 'f.stock_request_id', '=', 'r.id')
            ->join('wh_fulfillment_items as i', 'i.fulfillment_id', '=', 'f.id')
            ->where('r.status', 'ready')->where('i.status', '<>', 'completed')->count();
        $allocationUnitMismatch = $missingTables ? -1 : DB::table('wh_fulfillment_allocations as a')
            ->join('wh_stock_units as u', 'u.id', '=', 'a.stock_unit_id')
            ->where('a.status', 'reserved')->where('u.status', '<>', 'reserved')->count();
        $dispatchedUnitMismatch = $missingTables ? -1 : DB::table('wh_fulfillment_allocations as a')
            ->join('wh_stock_units as u', 'u.id', '=', 'a.stock_unit_id')
            ->where('a.status', 'dispatched')->where('u.status', '<>', 'in_transit')->count();
        $onDeliveryWithoutDo = $missingTables ? -1 : DB::table('stk_requests as r')
            ->leftJoin('wh_delivery_orders as d', 'd.stock_request_id', '=', 'r.id')
            ->where('r.status', 'on-delivery')->whereNull('d.id')->count();
        $dispatchedWithoutLedger = $missingTables ? -1 : DB::table('wh_delivery_orders')->where('status', 'dispatched')->whereNull('ledger_posting_id')->count();
        $doQtyMismatch = $missingTables ? -1 : DB::table('wh_delivery_order_items')->whereColumn('delivered_qty_base', '<>', 'ready_qty_base')->count();

        $rows = [
            ['Missing tables', $this->text($missingTables)], ['Missing request-item columns', $this->text($missingColumns)],
            ['Missing named routes', $this->text($missingRoutes)], ['Missing Access Matrix menus', $this->text($missingMenus)],
            ['Missing permissions', $this->text($missingPermissions)], ['Ready Qty above scanned Qty', $readyAboveScanned],
            ['Prepare items without checker', $prepareUnassigned], ['Ready requests with incomplete items', $readyIncomplete],
            ['Reserved allocation/unit mismatch', $allocationUnitMismatch], ['Dispatched allocation/unit mismatch', $dispatchedUnitMismatch],
            ['On-delivery without DO', $onDeliveryWithoutDo], ['Dispatched DO without ledger', $dispatchedWithoutLedger],
            ['DO delivered/ready mismatch', $doQtyMismatch],
        ];
        $failed = $missingTables || $missingColumns || $missingRoutes || $missingMenus || $missingPermissions
            || collect([$readyAboveScanned, $prepareUnassigned, $readyIncomplete, $allocationUnitMismatch, $dispatchedUnitMismatch, $onDeliveryWithoutDo, $dispatchedWithoutLedger, $doQtyMismatch])->contains(fn ($value) => $value !== 0);
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function text(array $items): string { return $items === [] ? '-' : implode(', ', $items); }
}
