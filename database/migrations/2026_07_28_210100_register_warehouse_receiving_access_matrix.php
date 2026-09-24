<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENUS = [
        [
            'portal' => 'inventory',
            'code' => 'inventory-warehouse-receiving',
            'name' => 'Receiving Stock Request',
            'path' => '/stock-inventory/warehouse-receiving',
            'sort_order' => 175,
            'permission' => 'warehouse.receiving.outlet',
            'roles' => ['ADMIN', 'SQUAD', 'SQUAD_DEFAULT'],
        ],
        [
            'portal' => 'warehouse-operations',
            'code' => 'warehouse-goods-receipts',
            'name' => 'Receiving & Goods Receipt',
            'path' => '/warehouse/goods-receipts',
            'sort_order' => 360,
            'permission' => 'warehouse.receiving.monitor',
            'roles' => ['ADMIN', 'WAREHOUSE'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        $now = now();
        foreach (self::MENUS as $menu) {
            $portal = DB::table('access_portals')->where('code', $menu['portal'])->first();
            if (! $portal) {
                continue;
            }
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $menu['code']], [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => $menu['permission'].'.view',
                'permission_create' => $menu['permission'].'.create',
                'permission_update' => $menu['permission'].'.update',
                'permission_delete' => $menu['permission'].'.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]);

            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($menu['permission'].'.'.$action, $guard);
            }
            $this->seedPortalAndMenu((string) $portal->id, $menuId, $menu['roles'], $now);
        }

        foreach ([
            'warehouse.receiving.outlet.scan',
            'warehouse.receiving.outlet.resolve',
            'warehouse.receiving.goods_receipt.generate',
            'warehouse.receiving.goods_receipt.print',
            'warehouse.receiving.discrepancy.return',
            'warehouse.receiving.discrepancy.close',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedPortalAndMenu(string $portalId, string $menuId, array $enabledRoleCodes, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }
        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $enabled = in_array($roleCode, $enabledRoleCodes, true);
            foreach (array_merge([null], $levels) as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions') && $enabled) {
                    $portalQuery = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId);
                    $levelId === null ? $portalQuery->whereNull('access_level_id') : $portalQuery->where('access_level_id', $levelId);
                    $existingPortal = $portalQuery->first();
                    if ($existingPortal) {
                        DB::table('access_role_portal_permissions')->where('id', $existingPortal->id)->update(['can_view' => true, 'updated_at' => $now]);
                    } else {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $portalId,
                            'can_view' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }
                $menuQuery = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $menuQuery->whereNull('access_level_id') : $menuQuery->where('access_level_id', $levelId);
                if ($menuQuery->exists()) {
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
                    'can_delete' => $enabled && $roleCode === 'ADMIN',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: administrator Access Matrix customizations are preserved.
    }
};
