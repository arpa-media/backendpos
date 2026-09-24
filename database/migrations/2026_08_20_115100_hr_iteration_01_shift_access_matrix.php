<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'human-resource';
    private const MENU_CODE = 'hr-data-shift';
    private const MENU_PATH = '/human-resource/data-master/shift';
    private const PREFIX = 'hr.shift';

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL],
            [
                'id' => $portalId,
                'name' => 'Human Resource',
                'description' => 'Portal Human Resource',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Data Shift',
                'path' => self::MENU_PATH,
                'sort_order' => 16,
                'permission_view' => self::PREFIX.'.view',
                'permission_create' => self::PREFIX.'.create',
                'permission_update' => self::PREFIX.'.update',
                'permission_delete' => self::PREFIX.'.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate(self::PREFIX.'.'.$action, $guard);
            }
        }

        $this->seedMatrix($portalId, $menuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrix(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';

            foreach (array_merge([null], $levels) as $levelId) {
                $portalCanView = $isAdmin;

                if (Schema::hasTable('access_role_portal_permissions')) {
                    $basePortalPermission = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId)
                        ->whereNull('access_level_id')
                        ->first();

                    $exactPortalPermission = $levelId === null
                        ? null
                        : DB::table('access_role_portal_permissions')
                            ->where('access_role_id', $role->id)
                            ->where('portal_id', $portalId)
                            ->where('access_level_id', $levelId)
                            ->first();

                    // Match UserManagementService fallback semantics: exact level first,
                    // otherwise inherit the role-level (NULL access_level_id) value.
                    $effectivePortalPermission = $exactPortalPermission ?: $basePortalPermission;
                    if ($effectivePortalPermission) {
                        $portalCanView = $portalCanView || (bool) $effectivePortalPermission->can_view;
                    }
                }

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
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

    public function down(): void
    {
        // Intentionally non-destructive. Access Matrix may be customized after deployment.
    }
};
