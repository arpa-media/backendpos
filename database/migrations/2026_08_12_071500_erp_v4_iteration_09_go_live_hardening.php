<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const CANONICAL_MENUS = [
        // Stock Inventory Iterasi 01/02.
        ['portal'=>'inventory','code'=>'inventory-request-stock','name'=>'Request Stock','path'=>'/stock-inventory/request-stock','sort'=>70,'permission'=>null],
        ['portal'=>'inventory','code'=>'inventory-warehouse-receiving','name'=>'Receiving Stock','path'=>'/stock-inventory/warehouse-receiving','sort'=>71,'permission'=>'warehouse.receiving.outlet'],
        ['portal'=>'inventory','code'=>'inventory-actual-stock','name'=>'Aktual Stock','path'=>'/stock-inventory/actual-stock','sort'=>72,'permission'=>'stock_inventory.actual_stock'],

        // Warehouse Iterasi 03 active v3 entry points.
        ['portal'=>'warehouse-operations','code'=>'warehouse-v3-purchase-requests','name'=>'Purchase Request','path'=>'/warehouse/purchasing/purchase-requests','sort'=>200,'permission'=>'warehouse.procurement.request'],
        ['portal'=>'warehouse-operations','code'=>'warehouse-v3-sales-stock-request','name'=>'Stock Request','path'=>'/warehouse/stock-requests/inbox','sort'=>400,'permission'=>'warehouse.stock_request.inbox'],
        ['portal'=>'warehouse-operations','code'=>'warehouse-v3-sales-order','name'=>'Sales Order','path'=>'/warehouse/sales/orders','sort'=>420,'permission'=>'warehouse.sales.order'],
        ['portal'=>'warehouse-operations','code'=>'warehouse-v3-production-orders','name'=>'Production Order','path'=>'/warehouse/production/orders','sort'=>500,'permission'=>'warehouse.production'],

        // Purchasing Iterasi 04-08 consolidated navigation.
        ['portal'=>'purchasing','code'=>'purchasing-dashboard','name'=>'Dashboard','path'=>'/portal/purchasing/dashboard','sort'=>10,'permission'=>'purchasing.dashboard'],
        ['portal'=>'purchasing','code'=>'purchasing-fund-requests','name'=>'Fund Requests','path'=>'/purchasing/fund-requests','sort'=>20,'permission'=>'purchasing.fund_request'],
        ['portal'=>'purchasing','code'=>'purchasing-order-management','name'=>'Order Management','path'=>'/purchasing/order-management','sort'=>30,'permission'=>'purchasing.order_management'],
        ['portal'=>'purchasing','code'=>'purchasing-realization-orders','name'=>'Realization Order','path'=>'/purchasing/realization-orders','sort'=>40,'permission'=>'purchasing.realization_order'],
        ['portal'=>'purchasing','code'=>'purchasing-account-payables','name'=>'Account Payable','path'=>'/purchasing/account-payables','sort'=>50,'permission'=>'purchasing.account_payable'],
        ['portal'=>'purchasing','code'=>'purchasing-account-receivables','name'=>'Account Receivable','path'=>'/purchasing/account-receivables','sort'=>60,'permission'=>'purchasing.account_receivable'],
    ];

    private const LEGACY_TO_CANONICAL = [
        'purchasing-order-management' => [
            'purchasing-purchase-orders',
            'purchasing-service-orders',
            'purchasing-reimburse-orders',
        ],
        'purchasing-realization-orders' => [
            'purchasing-goods-receipts',
            'purchasing-service-acceptances',
            'purchasing-reimburse-payments',
        ],
        'purchasing-account-payables' => ['purchasing-incoming-invoices'],
        'purchasing-account-receivables' => ['purchasing-outgoing-invoices'],
    ];

    private const LEGACY_INACTIVE = [
        'inventory-receive-stock',
        'inventory-receiving-stock',
        'purchasing-stock-request-approval',
        'purchasing-purchase-orders',
        'purchasing-service-orders',
        'purchasing-reimburse-orders',
        'purchasing-goods-receipts',
        'purchasing-service-acceptances',
        'purchasing-reimburse-payments',
        'purchasing-incoming-invoices',
        'purchasing-outgoing-invoices',
    ];

    private const PERMISSIONS = [
        'stock_inventory.actual_stock' => ['view','create','update','delete'],
        'warehouse.receiving.outlet' => ['view','create','update','delete'],
        'warehouse.procurement.request' => ['view','create','update','delete','submit','approve'],
        'warehouse.stock_request.inbox' => ['view','create','update','delete','approve'],
        'warehouse.sales.order' => ['view','create','update','delete','submit','approve'],
        'warehouse.production' => ['view','create','update','delete','approve','done'],

        'purchasing.dashboard' => ['view'],
        'purchasing.fund_request' => ['view','create','update','delete','submit','approve','reject','print','upload'],
        'purchasing.order_management' => ['view','create','update','delete','submit','approve_finance_1','approve_finance_2','print'],
        'purchasing.realization_order' => ['view','create','update','delete','submit','approve','upload','print'],
        'purchasing.account_payable' => ['view','create','update','delete','issue','payment','print'],
        'purchasing.account_receivable' => ['view','create','update','delete','issue','payment','print'],
    ];

    public function up(): void
    {
        $this->ensurePermissions();
        $this->reconcileMenus();
        $this->mergeLegacyGrants();
        $this->deduplicateCanonicalMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensurePermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $guard = (string) config('auth.defaults.guard', 'web');
        foreach (self::PERMISSIONS as $base => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate($base.'.'.$action, $guard);
            }
        }
    }

    private function reconcileMenus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portals = DB::table('access_portals')
            ->whereIn('code', collect(self::CANONICAL_MENUS)->pluck('portal')->unique()->all())
            ->get()
            ->keyBy('code');

        $now = now();

        foreach (self::CANONICAL_MENUS as $definition) {
            $portal = $portals->get($definition['portal']);
            if (! $portal) continue;

            $existing = DB::table('access_menus')->where('code', $definition['code'])->first();
            $id = (string) ($existing->id ?? Str::ulid());

            $payload = [
                'id' => $id,
                'portal_id' => $portal->id,
                'name' => $definition['name'],
                'path' => $definition['path'],
                'sort_order' => $definition['sort'],
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ];

            if ($definition['permission']) {
                $payload['permission_view'] = $definition['permission'].'.view';
                $payload['permission_create'] = $definition['permission'].'.create';
                $payload['permission_update'] = $definition['permission'].'.update';
                $payload['permission_delete'] = $definition['permission'].'.delete';
            }

            DB::table('access_menus')->updateOrInsert(['code' => $definition['code']], $payload);

            // A canonical path must have only one active menu inside the same portal.
            DB::table('access_menus')
                ->where('portal_id', $portal->id)
                ->where('path', $definition['path'])
                ->where('code', '<>', $definition['code'])
                ->update(['is_active' => false, 'updated_at' => $now]);
        }

        DB::table('access_menus')
            ->whereIn('code', self::LEGACY_INACTIVE)
            ->update(['is_active' => false, 'updated_at' => $now]);
    }

    private function mergeLegacyGrants(): void
    {
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_role_menu_permissions')) return;

        $now = now();

        foreach (self::LEGACY_TO_CANONICAL as $canonicalCode => $legacyCodes) {
            $canonicalId = DB::table('access_menus')->where('code', $canonicalCode)->value('id');
            if (! $canonicalId) continue;

            $sourceIds = DB::table('access_menus')
                ->whereIn('code', array_merge([$canonicalCode], $legacyCodes))
                ->pluck('id');

            if ($sourceIds->isEmpty()) continue;

            $groups = DB::table('access_role_menu_permissions')
                ->whereIn('menu_id', $sourceIds)
                ->get()
                ->groupBy(fn ($row) => (string) $row->access_role_id.'|'.(string) ($row->access_level_id ?? ''));

            foreach ($groups as $rows) {
                $first = $rows->first();
                $payload = [
                    'can_view' => $rows->contains(fn ($row) => (bool) $row->can_view),
                    'can_create' => $rows->contains(fn ($row) => (bool) $row->can_create),
                    'can_edit' => $rows->contains(fn ($row) => (bool) $row->can_edit),
                    'can_delete' => $rows->contains(fn ($row) => (bool) $row->can_delete),
                    'updated_at' => $now,
                ];

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $first->access_role_id)
                    ->where('menu_id', $canonicalId);

                is_null($first->access_level_id)
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $first->access_level_id);

                $target = $query->orderBy('id')->first();
                if ($target) {
                    DB::table('access_role_menu_permissions')->where('id', $target->id)->update($payload);
                } else {
                    DB::table('access_role_menu_permissions')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $first->access_role_id,
                        'access_level_id' => $first->access_level_id,
                        'menu_id' => $canonicalId,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    private function deduplicateCanonicalMatrix(): void
    {
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_role_menu_permissions')) return;

        $canonicalIds = DB::table('access_menus')
            ->whereIn('code', collect(self::CANONICAL_MENUS)->pluck('code')->all())
            ->pluck('id');

        if ($canonicalIds->isEmpty()) return;

        $rows = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $canonicalIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => (string) $row->access_role_id.'|'.(string) ($row->access_level_id ?? '').'|'.(string) $row->menu_id);

        foreach ($rows as $group) {
            if ($group->count() <= 1) continue;

            $keep = $group->first();
            DB::table('access_role_menu_permissions')->where('id', $keep->id)->update([
                'can_view' => $group->contains(fn ($row) => (bool) $row->can_view),
                'can_create' => $group->contains(fn ($row) => (bool) $row->can_create),
                'can_edit' => $group->contains(fn ($row) => (bool) $row->can_edit),
                'can_delete' => $group->contains(fn ($row) => (bool) $row->can_delete),
                'updated_at' => now(),
            ]);

            DB::table('access_role_menu_permissions')
                ->whereIn('id', $group->slice(1)->pluck('id')->all())
                ->delete();
        }
    }

    public function down(): void
    {
        // Non-destructive. Go-live reconciliation must not roll back role grants,
        // accounting history, or re-activate legacy navigation automatically.
    }
};
