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

    private const MENUS = [
        [
            'code' => 'warehouse-master-warehouses',
            'name' => 'Data Warehouse',
            'path' => '/warehouse/master/warehouses',
            'sort_order' => 100,
            'permission' => 'warehouse.master.warehouse',
        ],
        [
            'code' => 'warehouse-master-suppliers',
            'name' => 'Supplier',
            'path' => '/warehouse/master/suppliers',
            'sort_order' => 110,
            'permission' => 'warehouse.master.supplier',
        ],
        [
            'code' => 'warehouse-master-brands',
            'name' => 'Brand',
            'path' => '/warehouse/master/brands',
            'sort_order' => 120,
            'permission' => 'warehouse.master.brand',
        ],
        [
            'code' => 'warehouse-master-categories',
            'name' => 'Category Item',
            'path' => '/warehouse/master/categories',
            'sort_order' => 130,
            'permission' => 'warehouse.master.category',
        ],
        [
            'code' => 'warehouse-master-uoms',
            'name' => 'Unit of Measurement',
            'path' => '/warehouse/master/uoms',
            'sort_order' => 140,
            'permission' => 'warehouse.master.uom',
        ],
        [
            'code' => 'warehouse-master-chain-supplies',
            'name' => 'Chain Supply',
            'path' => '/warehouse/master/chain-supplies',
            'sort_order' => 150,
            'permission' => 'warehouse.master.chain_supply',
        ],
        [
            'code' => 'warehouse-master-storages',
            'name' => 'Storage',
            'path' => '/warehouse/master/storages',
            'sort_order' => 160,
            'permission' => 'warehouse.master.storage',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $base = $menu['permission'];

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portal->id,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => "{$base}.view",
                    'permission_create' => "{$base}.create",
                    'permission_update' => "{$base}.update",
                    'permission_delete' => "{$base}.delete",
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            if (Schema::hasTable('permissions')) {
                foreach (['view', 'create', 'update', 'delete'] as $action) {
                    Permission::findOrCreate("{$base}.{$action}", $guard);
                }
            }

            $this->seedMatrixRows((string) $menuId, $menu['code'], $now);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrixRows(string $menuId, string $menuCode, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $isAdmin = $roleCode === 'ADMIN';
            $isWarehouse = $roleCode === 'WAREHOUSE';

            foreach ($scopes as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
                    continue;
                }

                $warehouseCatalog = $menuCode === 'warehouse-master-warehouses';

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $isAdmin || $isWarehouse,
                    'can_create' => $isAdmin || ($isWarehouse && ! $warehouseCatalog),
                    'can_edit' => $isAdmin || $isWarehouse,
                    'can_delete' => $isAdmin || ($isWarehouse && ! $warehouseCatalog),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: admin dapat sudah mengubah Access Matrix.
    }
};
