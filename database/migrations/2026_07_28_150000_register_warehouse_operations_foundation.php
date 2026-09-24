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
        $portalCode = 'warehouse-operations';
        $existingPortal = DB::table('access_portals')->where('code', $portalCode)->first();
        $portalId = (string) ($existingPortal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => $portalCode],
            [
                'id' => $portalId,
                'name' => 'Warehouse',
                'description' => 'Portal operasional Warehouse, terpisah dari HPP/COGS.',
                'sort_order' => 55,
                'is_active' => true,
                'created_at' => $existingPortal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $existingMenu = DB::table('access_menus')->where('code', 'warehouse-operations-dashboard')->first();
        $menuId = (string) ($existingMenu->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'warehouse-operations-dashboard'],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Dashboard Warehouse',
                'path' => '/warehouse/dashboard',
                'sort_order' => 10,
                'permission_view' => 'warehouse.dashboard.view',
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $existingMenu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate('warehouse.dashboard.view', config('auth.defaults.guard', 'web'));
        }

        $this->seedMatrixRows($portalId, $menuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
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
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $enabledByDefault = in_array($roleCode, ['ADMIN', 'WAREHOUSE'], true);

            foreach ($scopes as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $query = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId);
                    $levelId === null
                        ? $query->whereNull('access_level_id')
                        : $query->where('access_level_id', $levelId);

                    if (! $query->exists()) {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $portalId,
                            'can_view' => $enabledByDefault,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (Schema::hasTable('access_role_menu_permissions')) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null
                        ? $query->whereNull('access_level_id')
                        : $query->where('access_level_id', $levelId);

                    if (! $query->exists()) {
                        DB::table('access_role_menu_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'menu_id' => $menuId,
                            'can_view' => $enabledByDefault,
                            'can_create' => false,
                            'can_edit' => false,
                            'can_delete' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: konfigurasi Access Matrix dapat sudah disesuaikan admin.
    }
};
