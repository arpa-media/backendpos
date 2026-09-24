<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'warehouse-operations';
    private const ENABLED_ROLES = ['ADMIN', 'WAREHOUSE'];

    private const MENUS = [
        ['warehouse-v3-dashboard','Dashboard Warehouse','/warehouse/dashboard',100,'warehouse.dashboard'],
        ['warehouse-v3-master-warehouses','Data Warehouse','/warehouse/master/warehouses',110,'warehouse.master.warehouse'],
        ['warehouse-v3-master-suppliers','Supplier','/warehouse/master/suppliers',120,'warehouse.master.supplier'],
        ['warehouse-v3-master-customers','Customer','/warehouse/master/customers',130,'warehouse.master.customer'],
        ['warehouse-v3-master-brands','Brand','/warehouse/master/brands',140,'warehouse.master.brand'],
        ['warehouse-v3-master-categories','Category Item','/warehouse/master/categories',150,'warehouse.master.category'],
        ['warehouse-v3-master-uoms','UoM','/warehouse/master/uoms',160,'warehouse.master.uom'],
        ['warehouse-v3-master-chain-supply','Chain Supply','/warehouse/master/chain-supplies',170,'warehouse.master.chain_supply'],

        ['warehouse-v3-purchase-requests','Purchase Request','/warehouse/purchasing/purchase-requests',200,'warehouse.procurement.request'],
        ['warehouse-v3-purchase-orders','Purchase Order','/warehouse/purchasing/purchase-orders',210,'warehouse.procurement.order'],
        ['warehouse-v3-return-requests','Return Request','/warehouse/purchasing/return-requests',220,'warehouse.procurement.return_request'],

        ['warehouse-v3-stock-items','All Item','/warehouse/inventory/items',300,'warehouse.inventory.item'],
        ['warehouse-v3-stock-batches','Batch','/warehouse/inventory/batches',310,'warehouse.inventory.batch'],
        ['warehouse-v3-stock-storage','Storage','/warehouse/stock/storage',320,'warehouse.master.storage'],
        ['warehouse-v3-stock-prices','Harga','/warehouse/stock/prices',330,'warehouse.inventory.price'],
        ['warehouse-v3-stock-history','Stock History','/warehouse/ledger/history',340,'warehouse.ledger.history'],

        ['warehouse-v3-sales-stock-request','Stock Request','/warehouse/stock-requests/inbox',400,'warehouse.stock_request.inbox'],
        ['warehouse-v3-sales-production-request','Production Request','/warehouse/sales/production-requests',410,'warehouse.production_request'],
        ['warehouse-v3-sales-order','Sales Order','/warehouse/sales/orders',420,'warehouse.sales.order'],
        ['warehouse-v3-sales-transfer','Transfer Stock','/warehouse/transfers',430,'warehouse.transfer'],

        ['warehouse-v3-production-orders','Production Order','/warehouse/production/orders',500,'warehouse.production'],
        ['warehouse-v3-production-ongoing','Ongoing Production','/warehouse/production/ongoing',510,'warehouse.production.ongoing'],
        ['warehouse-v3-production-cogs','COGS Warehouse','/warehouse/production/cogs',520,'warehouse.production.cost'],
        ['warehouse-v3-production-review','Production Review','/warehouse/production/review',530,'warehouse.production.control'],
        ['warehouse-v3-production-opname','Production Opname','/warehouse/production/opname',540,'warehouse.production.opname'],

        ['warehouse-v3-logistics-checker','Checker Prepare','/warehouse/tasks/checker-prepare',600,'warehouse.fulfillment.task'],
        ['warehouse-v3-logistics-do','Delivery Order','/warehouse/delivery-orders',610,'warehouse.delivery_order'],
        ['warehouse-v3-logistics-gr','Goods Receipt','/warehouse/goods-receipts',620,'warehouse.receiving.monitor'],
        ['warehouse-v3-logistics-receiving','Receiving Stock','/warehouse/logistics/receiving-stock',630,'warehouse.logistics.receiving'],

        ['warehouse-v3-finance-incoming','Incoming Invoice','/warehouse/finance/incoming-invoices',700,'warehouse.purchasing.invoice'],
        ['warehouse-v3-finance-outgoing','Outgoing Invoice','/warehouse/finance/outgoing-invoices',710,'warehouse.finance.invoice'],
        ['warehouse-v3-finance-coa','CoA Warehouse','/warehouse/finance/coa',720,'warehouse.finance.coa'],
        ['warehouse-v3-finance-gl','General Ledger','/warehouse/finance/general-ledger',730,'warehouse.finance.general_ledger'],
        ['warehouse-v3-finance-balance-sheet','Balance Sheet','/warehouse/finance/balance-sheet',740,'warehouse.finance.balance_sheet'],
        ['warehouse-v3-finance-cash-flow','Cash Flow','/warehouse/finance/cash-flow',750,'warehouse.finance.cash_flow'],
        ['warehouse-v3-finance-profit-loss','Profit & Loss','/warehouse/finance/profit-loss',760,'warehouse.finance.profit_loss'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) return;

        $now = now();
        DB::table('access_portals')->where('id', $portal->id)->update([
            'name' => 'Warehouse',
            'description' => 'Portal Warehouse v3: Master, Purchasing, Production, Sales, Stock, Logistics, Invoice, dan Finance.',
            'updated_at' => $now,
        ]);

        $guard = config('auth.defaults.guard', 'web');
        foreach (self::MENUS as [$code, $name, $path, $sortOrder, $basePermission]) {
            $existing = DB::table('access_menus')->where('code', $code)->first();
            if (! $existing) {
                $existing = DB::table('access_menus')
                    ->where('portal_id', $portal->id)
                    ->where('path', $path)
                    ->orderBy('sort_order')
                    ->first();
            }

            $menuId = (string) ($existing->id ?? Str::ulid());
            $menuCode = (string) ($existing->code ?? $code);
            $payload = [
                'portal_id' => $portal->id,
                'code' => $menuCode,
                'name' => $name,
                'path' => $path,
                'sort_order' => $sortOrder,
                'permission_view' => $basePermission.'.view',
                'permission_create' => $basePermission.'.create',
                'permission_update' => $basePermission.'.update',
                'permission_delete' => $basePermission.'.delete',
                'is_active' => true,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('access_menus')->where('id', $menuId)->update($payload);
            } else {
                DB::table('access_menus')->insert($payload + ['id' => $menuId, 'created_at' => $now]);
            }

            if (Schema::hasTable('permissions')) {
                foreach (['view', 'create', 'update', 'delete'] as $action) {
                    Permission::findOrCreate($basePermission.'.'.$action, $guard);
                }
            }
            $this->seedMenuMatrix($menuId, $now);
        }

        $this->seedPortalMatrix((string) $portal->id, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedPortalMatrix(string $portalId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_portal_permissions')) return;
        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all() : [];

        foreach ($roles as $role) {
            $enabled = in_array(strtoupper(trim((string) $role->code)), self::ENABLED_ROLES, true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_portal_permissions')->where('access_role_id', $role->id)->where('portal_id', $portalId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                if ($query->exists()) continue;
                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(), 'access_role_id' => $role->id, 'access_level_id' => $levelId,
                    'portal_id' => $portalId, 'can_view' => $enabled, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedMenuMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all() : [];

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $enabled = in_array($roleCode, self::ENABLED_ROLES, true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                if ($query->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(), 'access_role_id' => $role->id, 'access_level_id' => $levelId,
                    'menu_id' => $menuId, 'can_view' => $enabled, 'can_create' => $enabled,
                    'can_edit' => $enabled, 'can_delete' => $enabled && $roleCode === 'ADMIN',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive. Access Matrix dapat sudah disesuaikan administrator.
    }
};
