<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'general-affair';
    private const DASHBOARD_MENU_CODE = 'ga-dashboard';
    private const DASHBOARD_PERMISSION = 'ga.dashboard.view';

    public function up(): void
    {
        $this->ensureGaAccessRole();
        $this->ensureGaAccessLevel();
        $this->ensurePermission();
        $this->registerPortalAndDashboard();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Access Matrix can be customized by an
        // administrator after deployment and later GA iterations depend on the
        // same portal catalog.
    }

    private function ensureGaAccessRole(): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $exists = DB::table('access_roles')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(TRIM(COALESCE(code,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')")
                    ->orWhereRaw("UPPER(TRIM(COALESCE(name,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')");
            })
            ->exists();

        if ($exists) {
            return;
        }

        $backofficeTypeId = Schema::hasTable('access_user_types')
            ? DB::table('access_user_types')->whereRaw("UPPER(TRIM(COALESCE(code,''))) = 'BACKOFFICE'")->value('id')
            : null;

        DB::table('access_roles')->insert([
            'id' => (string) Str::ulid(),
            'user_type_id' => $backofficeTypeId ?: null,
            'code' => 'GA',
            'name' => 'GA',
            'description' => 'General Affair',
            'spatie_role_name' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureGaAccessLevel(): void
    {
        if (! Schema::hasTable('access_levels')) {
            return;
        }

        $exists = DB::table('access_levels')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(TRIM(COALESCE(code,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')")
                    ->orWhereRaw("UPPER(TRIM(COALESCE(name,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')");
            })
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('access_levels')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'GA',
            'name' => 'GA',
            'description' => 'General Affair',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePermission(): void
    {
        $permissionTable = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($permissionTable)) {
            return;
        }

        $now = now();
        DB::table($permissionTable)->insertOrIgnore([
            'name' => self::DASHBOARD_PERMISSION,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function registerPortalAndDashboard(): void
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
                'name' => 'General Affair',
                'description' => 'Portal General Affair untuk ticketing, CCTV, costing, bill, asset, inventory, audit, dan drive inventory.',
                'sort_order' => 85,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menu = DB::table('access_menus')->where('code', self::DASHBOARD_MENU_CODE)->first();
        $menuId = (string) ($menu->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => self::DASHBOARD_MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Dashboard General Affair',
                'path' => '/portal/general-affair/dashboard',
                'sort_order' => 10,
                'permission_view' => self::DASHBOARD_PERMISSION,
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $this->seedMatrixRows($portalId, $menuId, $now);
    }

    private function seedMatrixRows(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code', 'name')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $roleName = strtoupper(trim((string) $role->name));
            $enabledByDefault = $roleCode === 'ADMIN'
                || in_array($roleCode, ['GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true)
                || in_array($roleName, ['GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true);

            foreach ($scopes as $levelId) {
                $this->seedPortalPermission((string) $role->id, $levelId, $portalId, $enabledByDefault, $now);
                $this->seedMenuPermission((string) $role->id, $levelId, $menuId, $enabledByDefault, $now);
            }
        }
    }

    private function seedPortalPermission(string $roleId, ?string $levelId, string $portalId, bool $enabled, $now): void
    {
        if (! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        $query = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('portal_id', $portalId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

        if ($query->exists()) {
            return;
        }

        DB::table('access_role_portal_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'portal_id' => $portalId,
            'can_view' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedMenuPermission(string $roleId, ?string $levelId, string $menuId, bool $enabled, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

        if ($query->exists()) {
            return;
        }

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $enabled,
            'can_create' => false,
            'can_edit' => false,
            'can_delete' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
