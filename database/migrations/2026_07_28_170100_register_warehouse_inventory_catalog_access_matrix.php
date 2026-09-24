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
        ['code' => 'warehouse-inventory-items', 'name' => 'All Item', 'path' => '/warehouse/inventory/items', 'sort_order' => 200, 'permission' => 'warehouse.inventory.item'],
        ['code' => 'warehouse-inventory-batches', 'name' => 'Batch & Storage', 'path' => '/warehouse/inventory/batches', 'sort_order' => 210, 'permission' => 'warehouse.inventory.batch'],
        ['code' => 'warehouse-inventory-barcodes', 'name' => 'Barcode', 'path' => '/warehouse/inventory/barcodes', 'sort_order' => 220, 'permission' => 'warehouse.inventory.barcode'],
        ['code' => 'warehouse-inventory-outlet-prices', 'name' => 'Harga Outlet', 'path' => '/warehouse/inventory/outlet-prices', 'sort_order' => 230, 'permission' => 'warehouse.inventory.price'],
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

            $this->seedMatrixRows($menuId, $now);
        }

        if (Schema::hasTable('permissions')) {
            foreach (['warehouse.inventory.barcode.print', 'warehouse.inventory.barcode.scan', 'warehouse.inventory.item.uom.manage'] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrixRows(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $enabled = in_array($roleCode, ['ADMIN', 'WAREHOUSE'], true);

            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $enabled,
                    'can_create' => $enabled,
                    'can_edit' => $enabled,
                    'can_delete' => $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix mungkin sudah disesuaikan administrator.
    }
};
