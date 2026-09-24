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
        $portalCode = 'report';
        $existingPortal = DB::table('access_portals')->where('code', $portalCode)->first();
        $portalId = (string) ($existingPortal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => $portalCode],
            [
                'id' => $portalId,
                'name' => 'Report',
                'description' => 'Portal report operasional.',
                'sort_order' => 130,
                'is_active' => true,
                'created_at' => $existingPortal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menus = [
            [
                'code' => 'report-overhandle',
                'name' => 'Overhandle',
                'path' => '/report/overhandle',
                'sort_order' => 10,
                'permission_view' => 'report.overhandle.view',
                'permission_create' => 'report.overhandle.create',
                'permission_update' => 'report.overhandle.update',
                'permission_delete' => 'report.overhandle.delete',
            ],
            [
                'code' => 'report-expense-report',
                'name' => 'Expense Report',
                'path' => '/report/expense-report',
                'sort_order' => 20,
                'permission_view' => 'report.expense.view',
                'permission_create' => 'report.expense.create',
                'permission_update' => 'report.expense.update',
                'permission_delete' => 'report.expense.delete',
            ],
            [
                'code' => 'report-kpi-squad',
                'name' => 'KPI Squad',
                'path' => '/report/kpi-squad',
                'sort_order' => 30,
                'permission_view' => 'report.kpi_squad.view',
                'permission_create' => 'report.kpi_squad.create',
                'permission_update' => 'report.kpi_squad.update',
                'permission_delete' => 'report.kpi_squad.delete',
            ],
            [
                'code' => 'report-expense-request',
                'name' => 'Expense Request',
                'path' => '/report/expense-request',
                'sort_order' => 40,
                'permission_view' => 'report.expense_request.view',
                'permission_create' => 'report.expense_request.create',
                'permission_update' => 'report.expense_request.update',
                'permission_delete' => 'report.expense_request.delete',
            ],
        ];

        $menuIds = [];
        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $menuIds[] = $menuId;

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

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($menus as $menu) {
                foreach (['permission_view', 'permission_create', 'permission_update', 'permission_delete'] as $key) {
                    Permission::findOrCreate($menu[$key], $guard);
                }
            }
        }

        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopeLevels = array_merge([null], $levels);

        foreach ($roles as $role) {
            $isAdmin = strtoupper((string) $role->code) === 'ADMIN';

            foreach ($scopeLevels as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $portalPermissionQuery = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId);
                    $levelId === null
                        ? $portalPermissionQuery->whereNull('access_level_id')
                        : $portalPermissionQuery->where('access_level_id', $levelId);

                    if (! $portalPermissionQuery->exists()) {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $portalId,
                            'can_view' => $isAdmin,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }

                foreach ($menuIds as $menuId) {
                    $menuPermissionQuery = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null
                        ? $menuPermissionQuery->whereNull('access_level_id')
                        : $menuPermissionQuery->where('access_level_id', $levelId);

                    if ($menuPermissionQuery->exists()) {
                        continue;
                    }

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
        // Non-destruktif: konfigurasi Access Matrix yang sudah diubah user tidak dihapus otomatis.
    }
};
