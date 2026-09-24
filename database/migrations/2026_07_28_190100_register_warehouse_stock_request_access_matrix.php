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
            'code' => 'warehouse-stock-request-inbox',
            'name' => 'Warehouse Request Inbox',
            'path' => '/warehouse/stock-requests/inbox',
            'sort_order' => 310,
            'permission' => 'warehouse.stock_request.inbox',
        ],
        [
            'code' => 'warehouse-stock-request-purchasing',
            'name' => 'Purchasing Handoff',
            'path' => '/warehouse/stock-requests/purchasing',
            'sort_order' => 320,
            'permission' => 'warehouse.stock_request.handoff',
        ],
    ];

    private const WAREHOUSE_ROLE_CODES = ['ADMIN', 'WAREHOUSE'];

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

        if (Schema::hasTable('permissions')) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate("warehouse.stock_request.outlet.{$action}", $guard);
            }
        }

        $this->grantSquadOutletRequestAccess($now);

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

            $this->seedMatrixRows((string) $portal->id, $menuId, $menu['code'], $now);
        }

        if (Schema::hasTable('permissions')) {
            foreach ([
                'warehouse.stock_request.submit',
                'warehouse.stock_request.accept',
                'warehouse.stock_request.handoff.generate',
            ] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }


    private function grantSquadOutletRequestAccess($now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $inventoryPortal = DB::table('access_portals')->where('code', 'inventory')->first();
        $requestMenu = DB::table('access_menus')->where('code', 'inventory-request-stock')->first();
        if (! $inventoryPortal || ! $requestMenu) {
            return;
        }

        $roles = DB::table('access_roles')
            ->whereIn('code', ['SQUAD', 'SQUAD_DEFAULT'])
            ->get(['id']);
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            foreach (array_merge([null], $levels) as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $query = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $inventoryPortal->id);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    $existing = $query->first();
                    if ($existing) {
                        DB::table('access_role_portal_permissions')->where('id', $existing->id)->update([
                            'can_view' => true,
                            'updated_at' => $now,
                        ]);
                    } else {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $inventoryPortal->id,
                            'can_view' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $requestMenu->id);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                $existing = $query->first();
                if ($existing) {
                    DB::table('access_role_menu_permissions')->where('id', $existing->id)->update([
                        'can_view' => true,
                        'can_create' => true,
                        'can_edit' => true,
                        'can_delete' => false,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $requestMenu->id,
                        'can_view' => true,
                        'can_create' => true,
                        'can_edit' => true,
                        'can_delete' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function seedMatrixRows(string $portalId, string $menuId, string $menuCode, $now): void
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
            $enabled = in_array($roleCode, self::WAREHOUSE_ROLE_CODES, true);

            foreach (array_merge([null], $levels) as $levelId) {
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
        // Non-destructive: Access Matrix may already have administrator customizations.
    }
};
