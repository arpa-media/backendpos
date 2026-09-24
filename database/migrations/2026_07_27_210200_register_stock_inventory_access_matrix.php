<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'inventory')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => 'inventory'],
            [
                'id' => $portalId,
                'name' => 'Stock Inventory',
                'description' => 'Portal Stock Inventory outlet.',
                'sort_order' => 50,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $this->migrateLegacyStockOpnameMenu($portalId, $now);

        $menus = [
            $this->menu('inventory-dashboard', 'Dashboard Stock Inventory', '/portal/inventory/dashboard', 10, 'stock_inventory.dashboard'),
            $this->menu('inventory-uom', 'Unit of Measure', '/stock-inventory/uoms', 20, 'stock_inventory.uom'),
            $this->menu('inventory-stock-category', 'Stock Category', '/stock-inventory/categories', 30, 'stock_inventory.category'),
            $this->menu('inventory-sku', 'Data SKU', '/stock-inventory/skus', 40, 'stock_inventory.sku'),
            $this->menu('inventory-par-stock', 'Par Stock', '/stock-inventory/par-stocks', 50, 'stock_inventory.par_stock'),
            $this->menu('inventory-stock-opname', 'Stock Opname', '/stock-inventory/stock-opname', 60, 'stock_inventory.opname'),
            $this->menu('inventory-request-stock', 'Request Stock', '/stock-inventory/request-stock', 70, 'stock_inventory.request_stock'),
            $this->menu('inventory-receive-stock', 'Receive Stock', '/stock-inventory/receive-stock', 80, 'stock_inventory.receive_stock'),
            $this->menu('inventory-manual-stock', 'Manual Stock', '/stock-inventory/manual-stock', 90, 'stock_inventory.manual_stock'),
        ];

        $menuIds = [];
        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $menuIds[$menu['code']] = $menuId;

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission_view'],
                    'permission_create' => $menu['permission_create'],
                    'permission_update' => $menu['permission_update'],
                    'permission_delete' => $menu['permission_delete'],
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($menus as $menu) {
                foreach (['permission_view', 'permission_create', 'permission_update', 'permission_delete'] as $field) {
                    Permission::findOrCreate($menu[$field], $guard);
                }
            }
        }

        $this->seedMatrixRows($portalId, $menuIds, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function menu(string $code, string $name, string $path, int $sortOrder, string $permissionPrefix): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sortOrder,
            'permission_view' => $permissionPrefix.'.view',
            'permission_create' => $permissionPrefix.'.create',
            'permission_update' => $permissionPrefix.'.update',
            'permission_delete' => $permissionPrefix.'.delete',
        ];
    }

    private function migrateLegacyStockOpnameMenu(string $portalId, $now): void
    {
        $legacy = DB::table('access_menus')->where('code', 'inventory-check-stock')->first();
        $current = DB::table('access_menus')->where('code', 'inventory-stock-opname')->first();

        if ($legacy && ! $current) {
            DB::table('access_menus')->where('id', $legacy->id)->update([
                'code' => 'inventory-stock-opname',
                'portal_id' => $portalId,
                'name' => 'Stock Opname',
                'path' => '/stock-inventory/stock-opname',
                'sort_order' => 60,
                'permission_view' => 'stock_inventory.opname.view',
                'permission_create' => 'stock_inventory.opname.create',
                'permission_update' => 'stock_inventory.opname.update',
                'permission_delete' => 'stock_inventory.opname.delete',
                'is_active' => true,
                'updated_at' => $now,
            ]);
            return;
        }

        if ($legacy && $current) {
            DB::table('access_menus')->where('id', $legacy->id)->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedMatrixRows(string $portalId, array $menuIds, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levelIds = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levelIds);

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';

            foreach ($scopes as $levelId) {
                $portalPermissionQuery = Schema::hasTable('access_role_portal_permissions')
                    ? DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId)
                    : null;

                if ($portalPermissionQuery) {
                    $levelId === null
                        ? $portalPermissionQuery->whereNull('access_level_id')
                        : $portalPermissionQuery->where('access_level_id', $levelId);
                }

                $portalPermission = $portalPermissionQuery?->first();
                $portalCanView = $isAdmin || (bool) ($portalPermission->can_view ?? false);

                if (! $portalPermission && Schema::hasTable('access_role_portal_permissions')) {
                    DB::table('access_role_portal_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'portal_id' => $portalId,
                        'can_view' => $portalCanView,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }

                foreach ($menuIds as $menuId) {
                    $permissionQuery = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null
                        ? $permissionQuery->whereNull('access_level_id')
                        : $permissionQuery->where('access_level_id', $levelId);

                    if ($permissionQuery->exists()) {
                        continue;
                    }

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $portalCanView,
                        'can_create' => $isAdmin,
                        'can_edit' => $isAdmin,
                        'can_delete' => $isAdmin,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: access matrix configuration may already be customized by users.
    }
};
