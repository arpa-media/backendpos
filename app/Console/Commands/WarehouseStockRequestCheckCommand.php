<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseStockRequestCheckCommand extends Command
{
    protected $signature = 'warehouse:stock-request-check';

    protected $description = 'Memeriksa kontrak Stock Request Intake dan Purchasing Handoff Warehouse Iterasi 05.';

    public function handle(): int
    {
        $requiredTables = [
            'stk_requests', 'stk_request_items', 'stk_request_timelines',
            'wh_chain_supplies', 'wh_stock_request_handoffs',
            'pur_purchase_orders', 'pur_purchase_order_items',
        ];
        $requiredRequestColumns = [
            'destination_warehouse_id', 'chain_supply_id', 'origin_assignment_id',
            'request_channel', 'purchasing_handoff_status', 'warehouse_locked_at',
            'accepted_by_user_id', 'accepted_at', 'destination_snapshot', 'request_policy_snapshot',
        ];
        $requiredItemColumns = [
            'request_uom_id', 'base_uom_id_snapshot', 'requested_qty_uom',
            'conversion_factor_snapshot', 'requested_qty_base', 'request_uom_code_snapshot',
            'request_uom_name_snapshot', 'base_uom_code_snapshot',
            'warehouse_available_qty_snapshot', 'fulfillment_status',
        ];
        $requiredRoutes = [
            'warehouse.stock-requests.outlet.options', 'warehouse.stock-requests.outlet.index',
            'warehouse.stock-requests.outlet.store', 'warehouse.stock-requests.outlet.show',
            'warehouse.stock-requests.outlet.update', 'warehouse.stock-requests.outlet.submit',
            'warehouse.stock-requests.inbox.index', 'warehouse.stock-requests.inbox.show',
            'warehouse.stock-requests.inbox.accept', 'warehouse.stock-requests.handoffs.index',
            'warehouse.stock-requests.handoffs.generate',
        ];
        $requiredMenus = [
            'warehouse-stock-request-inbox',
            'warehouse-stock-request-purchasing',
        ];
        $requiredPermissions = [
            'warehouse.stock_request.outlet.view', 'warehouse.stock_request.outlet.create',
            'warehouse.stock_request.submit', 'warehouse.stock_request.inbox.view',
            'warehouse.stock_request.accept', 'warehouse.stock_request.handoff.view',
            'warehouse.stock_request.handoff.generate',
        ];

        $missingTables = collect($requiredTables)->reject(fn ($table) => Schema::hasTable($table))->values();
        $missingRequestColumns = Schema::hasTable('stk_requests')
            ? collect($requiredRequestColumns)->reject(fn ($column) => Schema::hasColumn('stk_requests', $column))->values()
            : collect($requiredRequestColumns);
        $missingItemColumns = Schema::hasTable('stk_request_items')
            ? collect($requiredItemColumns)->reject(fn ($column) => Schema::hasColumn('stk_request_items', $column))->values()
            : collect($requiredItemColumns);
        $missingRoutes = collect($requiredRoutes)->reject(fn ($name) => Route::has($name))->values();
        $missingMenus = Schema::hasTable('access_menus')
            ? collect($requiredMenus)->reject(fn ($code) => DB::table('access_menus')->where('code', $code)->exists())->values()
            : collect($requiredMenus);
        $missingPermissions = Schema::hasTable('permissions')
            ? collect($requiredPermissions)->reject(fn ($name) => DB::table('permissions')->where('name', $name)->exists())->values()
            : collect($requiredPermissions);

        $requestedWithoutWarehouse = $this->requestedWithoutWarehouse();
        $invalidSnapshots = $this->invalidSnapshotCount();
        $duplicateHandoffs = $this->duplicateHandoffCount();
        $generatedWithoutPo = $this->generatedWithoutPurchaseOrder();
        $poLineMismatch = $this->purchaseOrderLineMismatch();
        $acceptedWrongStatus = $this->acceptedWrongStatus();
        $itemStatusMismatch = $this->itemStatusMismatch();
        $missingSquadRequestAccess = $this->missingSquadRequestAccess();

        $passed = $missingTables->isEmpty()
            && $missingRequestColumns->isEmpty()
            && $missingItemColumns->isEmpty()
            && $missingRoutes->isEmpty()
            && $missingMenus->isEmpty()
            && $missingPermissions->isEmpty()
            && $requestedWithoutWarehouse === 0
            && $invalidSnapshots === 0
            && $duplicateHandoffs === 0
            && $generatedWithoutPo === 0
            && $poLineMismatch === 0
            && $acceptedWrongStatus === 0
            && $itemStatusMismatch === 0
            && $missingSquadRequestAccess === 0;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->implode(', ') ?: '-'],
            ['Missing stk_requests columns', $missingRequestColumns->implode(', ') ?: '-'],
            ['Missing stk_request_items columns', $missingItemColumns->implode(', ') ?: '-'],
            ['Missing named routes', $missingRoutes->implode(', ') ?: '-'],
            ['Missing Access Matrix menus', $missingMenus->implode(', ') ?: '-'],
            ['Missing permissions', $missingPermissions->implode(', ') ?: '-'],
            ['Requested/review without warehouse lock', (string) $requestedWithoutWarehouse],
            ['Invalid UoM/conversion snapshots', (string) $invalidSnapshots],
            ['Duplicate handoff per request', (string) $duplicateHandoffs],
            ['Generated handoff without PO', (string) $generatedWithoutPo],
            ['PO item count mismatch', (string) $poLineMismatch],
            ['Accepted timestamp outside review+', (string) $acceptedWrongStatus],
            ['Request/item status mismatch', (string) $itemStatusMismatch],
            ['Missing Squad Request Stock access', (string) $missingSquadRequestAccess],
            ['Status', $passed ? 'PASSED' : 'FAILED'],
        ]);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function requestedWithoutWarehouse(): int
    {
        if (! Schema::hasTable('stk_requests') || ! Schema::hasColumn('stk_requests', 'destination_warehouse_id')) {
            return -1;
        }
        return DB::table('stk_requests')
            ->where('request_channel', 'warehouse_operations')
            ->whereIn('status', ['requested', 'review', 'prepare', 'ready'])
            ->where(function ($query): void {
                $query->whereNull('destination_warehouse_id')->orWhereNull('chain_supply_id')->orWhereNull('warehouse_locked_at');
            })
            ->count();
    }

    private function invalidSnapshotCount(): int
    {
        if (! Schema::hasTable('stk_request_items') || ! Schema::hasTable('stk_requests')) {
            return -1;
        }
        return DB::table('stk_request_items as item')
            ->join('stk_requests as request', 'request.id', '=', 'item.stock_request_id')
            ->where('request.request_channel', 'warehouse_operations')
            ->where(function ($query): void {
                $query->whereNull('item.request_uom_id')
                    ->orWhereNull('item.base_uom_id_snapshot')
                    ->orWhereNull('item.requested_qty_uom')
                    ->orWhereNull('item.conversion_factor_snapshot')
                    ->orWhereNull('item.requested_qty_base')
                    ->orWhere('item.requested_qty_uom', '<=', 0)
                    ->orWhere('item.conversion_factor_snapshot', '<=', 0)
                    ->orWhereRaw('ABS(item.requested_qty_base - (item.requested_qty_uom * item.conversion_factor_snapshot)) > 0.0001');
            })
            ->count();
    }

    private function duplicateHandoffCount(): int
    {
        if (! Schema::hasTable('wh_stock_request_handoffs')) {
            return -1;
        }
        return DB::table('wh_stock_request_handoffs')
            ->select('stock_request_id')
            ->groupBy('stock_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
    }

    private function generatedWithoutPurchaseOrder(): int
    {
        if (! Schema::hasTable('wh_stock_request_handoffs')) {
            return -1;
        }
        return DB::table('wh_stock_request_handoffs')
            ->where('status', 'generated')
            ->whereNull('purchase_order_id')
            ->count();
    }

    private function purchaseOrderLineMismatch(): int
    {
        if (! Schema::hasTable('wh_stock_request_handoffs') || ! Schema::hasTable('pur_purchase_order_items')) {
            return -1;
        }
        $requestLines = DB::table('stk_request_items')
            ->selectRaw('stock_request_id, COUNT(*) as line_count')
            ->groupBy('stock_request_id');
        $poLines = DB::table('pur_purchase_order_items')
            ->selectRaw('purchase_order_id, COUNT(*) as line_count')
            ->groupBy('purchase_order_id');

        return DB::table('wh_stock_request_handoffs as handoff')
            ->leftJoinSub($requestLines, 'request_lines', 'request_lines.stock_request_id', '=', 'handoff.stock_request_id')
            ->leftJoinSub($poLines, 'po_lines', 'po_lines.purchase_order_id', '=', 'handoff.purchase_order_id')
            ->where('handoff.status', 'generated')
            ->whereRaw('COALESCE(request_lines.line_count, 0) <> COALESCE(po_lines.line_count, 0)')
            ->count();
    }


    private function missingSquadRequestAccess(): int
    {
        foreach (['access_roles', 'access_portals', 'access_menus', 'access_role_portal_permissions', 'access_role_menu_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                return -1;
            }
        }

        $portalId = DB::table('access_portals')->where('code', 'inventory')->value('id');
        $menuId = DB::table('access_menus')->where('code', 'inventory-request-stock')->value('id');
        if (! $portalId || ! $menuId) {
            return 1;
        }

        $roles = DB::table('access_roles')->whereIn('code', ['SQUAD', 'SQUAD_DEFAULT'])->pluck('id');
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
        $missing = 0;

        foreach ($roles as $roleId) {
            foreach (array_merge([null], $levels) as $levelId) {
                $portal = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $roleId)
                    ->where('portal_id', $portalId);
                $menu = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $roleId)
                    ->where('menu_id', $menuId);
                if ($levelId === null) {
                    $portal->whereNull('access_level_id');
                    $menu->whereNull('access_level_id');
                } else {
                    $portal->where('access_level_id', $levelId);
                    $menu->where('access_level_id', $levelId);
                }

                $portalRow = $portal->first();
                $menuRow = $menu->first();
                if (! $portalRow || ! $portalRow->can_view || ! $menuRow || ! $menuRow->can_view || ! $menuRow->can_create || ! $menuRow->can_edit) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    private function itemStatusMismatch(): int
    {
        if (! Schema::hasTable('stk_requests') || ! Schema::hasTable('stk_request_items')) {
            return -1;
        }

        return DB::table('stk_request_items as item')
            ->join('stk_requests as request', 'request.id', '=', 'item.stock_request_id')
            ->where('request.request_channel', 'warehouse_operations')
            ->whereIn('request.status', ['draft', 'submitted', 'requested', 'review'])
            ->whereColumn('item.status', '<>', 'request.status')
            ->count();
    }

    private function acceptedWrongStatus(): int
    {
        if (! Schema::hasTable('stk_requests') || ! Schema::hasColumn('stk_requests', 'accepted_at')) {
            return -1;
        }
        return DB::table('stk_requests')
            ->where('request_channel', 'warehouse_operations')
            ->whereNotNull('accepted_at')
            ->whereNotIn('status', ['review', 'prepare', 'ready', 'on-delivery', 'receiving', 'goods-receipt'])
            ->count();
    }
}
