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
    private const MENU_CODE = 'cogs-reset';
    private const MENU_PATH = '/cogs/reset';
    private const PERMISSION_PREFIX = 'cogs.reset';

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) return;

        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Reset COGS',
                'path' => self::MENU_PATH,
                'sort_order' => 80,
                'permission_view' => self::PERMISSION_PREFIX.'.view',
                'permission_create' => self::PERMISSION_PREFIX.'.create',
                'permission_update' => self::PERMISSION_PREFIX.'.update',
                'permission_delete' => self::PERMISSION_PREFIX.'.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate(self::PERMISSION_PREFIX.'.'.$action, $guard);
            }
        }

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $roles = DB::table('access_roles')->select('id', 'code')->get();
            $levels = Schema::hasTable('access_levels')
                ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : [];

            foreach ($roles as $role) {
                $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
                foreach (array_merge([null], $levels) as $levelId) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    $row = $query->first();
                    if ($row) continue;

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $isAdmin,
                        'can_create' => $isAdmin,
                        'can_edit' => $isAdmin,
                        'can_delete' => $isAdmin,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix may have been customized after deployment.
    }
};
