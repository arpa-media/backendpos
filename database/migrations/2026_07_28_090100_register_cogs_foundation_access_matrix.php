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
        $portal = DB::table('access_portals')->where('code', 'warehouse')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => 'warehouse'],
            [
                'id' => $portalId,
                'name' => 'HPP/COGS',
                'description' => 'Portal recipe, ingredient consumption, stock variance, dan kalkulasi HPP/COGS.',
                'sort_order' => (int) ($portal->sort_order ?? 80),
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menus = [
            $this->menu(
                'warehouse-dashboard',
                'Dashboard HPP/COGS',
                '/portal/warehouse/dashboard',
                10,
                'cogs.dashboard'
            ),
            $this->menu(
                'cogs-uom-conversion',
                'UOM Conversion',
                '/cogs/uom-conversions',
                20,
                'cogs.uom_conversion'
            ),
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

        $this->ensurePermissions($menus);
        $this->seedMatrixRows($portalId, $menuIds, $now);
        $this->ensurePortalVisibilityForGrantedMenus($portalId, array_values($menuIds), $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function menu(string $code, string $name, string $path, int $sortOrder, string $prefix): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sortOrder,
            'permission_view' => $prefix.'.view',
            'permission_create' => $prefix.'.create',
            'permission_update' => $prefix.'.update',
            'permission_delete' => $prefix.'.delete',
        ];
    }

    private function ensurePermissions(array $menus): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach ($menus as $menu) {
            foreach (['permission_view', 'permission_create', 'permission_update', 'permission_delete'] as $field) {
                Permission::findOrCreate($menu[$field], $guard);
            }
        }
    }

    private function seedMatrixRows(string $portalId, array $menuIds, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';

            foreach ($scopes as $levelId) {
                $portalPermission = $this->portalPermission($role->id, $levelId, $portalId);
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

                foreach ($menuIds as $code => $menuId) {
                    $existing = $this->menuPermission($role->id, $levelId, $menuId);
                    if ($existing) {
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

    private function ensurePortalVisibilityForGrantedMenus(string $portalId, array $menuIds, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        $granted = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $menuIds)
            ->where('can_view', true)
            ->get();

        foreach ($granted as $menuPermission) {
            $existing = $this->portalPermission(
                (string) $menuPermission->access_role_id,
                $menuPermission->access_level_id === null ? null : (string) $menuPermission->access_level_id,
                $portalId,
            );

            if ($existing) {
                if (! $existing->can_view) {
                    DB::table('access_role_portal_permissions')
                        ->where('id', $existing->id)
                        ->update(['can_view' => true, 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $menuPermission->access_role_id,
                'access_level_id' => $menuPermission->access_level_id,
                'portal_id' => $portalId,
                'can_view' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function portalPermission(string $roleId, ?string $levelId, string $portalId): ?object
    {
        if (! Schema::hasTable('access_role_portal_permissions')) {
            return null;
        }

        $query = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('portal_id', $portalId);
        $levelId === null
            ? $query->whereNull('access_level_id')
            : $query->where('access_level_id', $levelId);

        return $query->first();
    }

    private function menuPermission(string $roleId, ?string $levelId, string $menuId): ?object
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);
        $levelId === null
            ? $query->whereNull('access_level_id')
            : $query->where('access_level_id', $levelId);

        return $query->first();
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix choices may already be customized.
    }
};
