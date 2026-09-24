<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENUS = [
        [
            'code' => 'operational-sales-analytic-daily',
            'name' => 'Daily Analytic',
            'path' => '/operational/sales-analytic/daily',
            'sort_order' => 40,
            'permission' => 'operational.sales_analytic.daily.view',
        ],
        [
            'code' => 'operational-sales-analytic-hourly',
            'name' => 'Omzet Per Hour',
            'path' => '/operational/sales-analytic/hourly',
            'sort_order' => 50,
            'permission' => 'operational.sales_analytic.hourly.view',
        ],
        [
            'code' => 'operational-sales-analytic-hourly-summary',
            'name' => 'Summary Per Hour',
            'path' => '/operational/sales-analytic/hourly-summary',
            'sort_order' => 60,
            'permission' => 'operational.sales_analytic.hourly_summary.view',
        ],
    ];

    public function up(): void
    {
        foreach (self::MENUS as $menu) {
            Permission::findOrCreate($menu['permission'], 'web');
        }

        $this->registerMenusAndMatrix();
        $this->grantAdminFallback();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: keep Access Matrix rows/permissions so saved role profiles are not lost.
    }

    private function registerMenusAndMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portalId = (string) DB::table('access_portals')->where('code', 'operational')->value('id');
        if ($portalId === '') return;

        $now = now();
        $menuIds = [];
        foreach (self::MENUS as $menu) {
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
                    'permission_view' => $menu['permission'],
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('access_role_menu_permissions')) return;

        $dashboardId = (string) DB::table('access_menus')->where('code', 'operational-dashboard')->value('id');
        $sourceRows = $dashboardId !== ''
            ? DB::table('access_role_menu_permissions')->where('menu_id', $dashboardId)->get()
            : collect();

        foreach ($sourceRows as $source) {
            foreach ($menuIds as $menuId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $source->access_role_id)
                    ->where('menu_id', $menuId);
                $source->access_level_id === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $source->access_level_id);

                if ($query->exists()) continue;

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $source->access_role_id,
                    'access_level_id' => $source->access_level_id,
                    'menu_id' => $menuId,
                    'can_view' => (bool) ($source->can_view ?? false),
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // If an installation has no matrix row for Operational Dashboard yet,
        // keep the new rows discoverable for administrator roles only. Other roles
        // remain configurable from User Management -> Access Matrix.
        if (Schema::hasTable('access_roles')) {
            $adminRoleIds = DB::table('access_roles')->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("UPPER(COALESCE(name, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')");
            })->pluck('id');

            foreach ($adminRoleIds as $roleId) {
                foreach ($menuIds as $menuId) {
                    $exists = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $roleId)
                        ->whereNull('access_level_id')
                        ->where('menu_id', $menuId)
                        ->exists();
                    if ($exists) continue;

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $roleId,
                        'access_level_id' => null,
                        'menu_id' => $menuId,
                        'can_view' => true,
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

    private function grantAdminFallback(): void
    {
        $permissions = array_column(self::MENUS, 'permission');
        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role) use ($permissions): void {
            $name = strtolower(trim((string) $role->name));
            if (in_array($name, ['admin', 'administrator', 'superadmin', 'super-admin'], true)) {
                $role->givePermissionTo($permissions);
            }
        });
    }
};
