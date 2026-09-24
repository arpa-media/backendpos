<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'warehouse';
    private const MENU_CODE = 'cogs-calculation';
    private const MENU_PATH = '/cogs/calculation';
    private const PERMISSION_PREFIX = 'cogs.calculation';

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL_CODE],
            [
                'id' => $portalId,
                'name' => 'HPP/COGS',
                'description' => 'Portal recipe, purchasing cost traceability, ingredient consumption, stock variance, dan kalkulasi HPP/COGS.',
                'sort_order' => (int) ($portal->sort_order ?? 80),
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $existingMenu = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existingMenu->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'COGS Calculation & Reconciliation',
                'path' => self::MENU_PATH,
                'sort_order' => 70,
                'permission_view' => self::PERMISSION_PREFIX.'.view',
                'permission_create' => self::PERMISSION_PREFIX.'.create',
                'permission_update' => self::PERMISSION_PREFIX.'.update',
                'permission_delete' => self::PERMISSION_PREFIX.'.delete',
                'is_active' => true,
                'created_at' => $existingMenu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $this->ensurePermissions();
        $this->seedMatrixRows($portalId, $menuId, $now);
        $this->ensurePortalVisibility($portalId, $menuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensurePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $guard = config('auth.defaults.guard', 'web');
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate(self::PERMISSION_PREFIX.'.'.$action, $guard);
        }
    }

    private function seedMatrixRows(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
            foreach (array_merge([null], $levels) as $levelId) {
                $portalPermission = $this->portalPermission((string) $role->id, $levelId, $portalId);
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
                if ($this->menuPermission((string) $role->id, $levelId, $menuId)) {
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

    private function ensurePortalVisibility(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        foreach (DB::table('access_role_menu_permissions')->where('menu_id', $menuId)->where('can_view', true)->get() as $row) {
            $levelId = $row->access_level_id === null ? null : (string) $row->access_level_id;
            $existing = $this->portalPermission((string) $row->access_role_id, $levelId, $portalId);
            if ($existing) {
                if (! $existing->can_view) {
                    DB::table('access_role_portal_permissions')->where('id', $existing->id)->update(['can_view' => true, 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
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
        $query = DB::table('access_role_portal_permissions')->where('access_role_id', $roleId)->where('portal_id', $portalId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        return $query->first();
    }

    private function menuPermission(string $roleId, ?string $levelId, string $menuId): ?object
    {
        $query = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        return $query->first();
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix may already be customized by administrators.
    }
};
