<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $financePortalId = DB::table('access_portals')->whereRaw('lower(code) = ?', ['finance'])->value('id');
        if (! $financePortalId) {
            DB::table('access_portals')->insert([
                'id' => (string) Str::ulid(),
                'code' => 'finance',
                'name' => 'Finance',
                'description' => 'Portal Finance',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $financePortalId = DB::table('access_portals')->whereRaw('lower(code) = ?', ['finance'])->value('id');
        }

        $menus = [
            ['code' => 'sales-list', 'name' => 'Sales', 'path' => '/sales', 'sort_order' => 20, 'permission_view' => 'sale.view', 'permission_create' => null, 'permission_update' => null, 'permission_delete' => null],
            ['code' => 'sales-report', 'name' => 'Report', 'path' => '/reports', 'sort_order' => 30, 'permission_view' => 'report.view', 'permission_create' => null, 'permission_update' => null, 'permission_delete' => null],
            ['code' => 'finance-cashier-report', 'name' => 'Cashier Report', 'path' => '/finance/cashier-report', 'sort_order' => 35, 'permission_view' => 'report.view', 'permission_create' => null, 'permission_update' => null, 'permission_delete' => null],
            ['code' => 'sales-cancel', 'name' => 'Cancel Bill', 'path' => '/cancel-requests', 'sort_order' => 40, 'permission_view' => 'sale.cancel.approve', 'permission_create' => 'sale.cancel.request', 'permission_update' => 'sale.cancel.approve', 'permission_delete' => 'sale.cancel.approve'],
        ];

        foreach ($menus as $menu) {
            $existingId = DB::table('access_menus')->where('code', $menu['code'])->value('id');
            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $existingId ?: (string) Str::ulid(),
                    'portal_id' => $financePortalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission_view'],
                    'permission_create' => $menu['permission_create'],
                    'permission_update' => $menu['permission_update'],
                    'permission_delete' => $menu['permission_delete'],
                    'is_active' => true,
                    'created_at' => DB::table('access_menus')->where('code', $menu['code'])->value('created_at') ?: $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        $menuRows = DB::table('access_menus')
            ->whereIn('code', array_column($menus, 'code'))
            ->get()
            ->keyBy('code');

        $financePortalAccess = DB::table('access_role_portal_permissions')
            ->join('access_portals', 'access_portals.id', '=', 'access_role_portal_permissions.portal_id')
            ->leftJoin('access_roles', 'access_roles.id', '=', 'access_role_portal_permissions.access_role_id')
            ->whereRaw('lower(access_portals.code) = ?', ['finance'])
            ->where('access_role_portal_permissions.can_view', true)
            ->select(
                'access_role_portal_permissions.access_role_id',
                'access_role_portal_permissions.access_level_id',
                DB::raw('lower(access_roles.code) as role_code')
            )
            ->get();

        foreach ($financePortalAccess as $access) {
            foreach ($menuRows as $menuCode => $menu) {
                $existingPermissionId = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $access->access_role_id)
                    ->where(function ($query) use ($access) {
                        if ($access->access_level_id) {
                            $query->where('access_level_id', $access->access_level_id);
                        } else {
                            $query->whereNull('access_level_id');
                        }
                    })
                    ->where('menu_id', $menu->id)
                    ->value('id');

                $isAdmin = $access->role_code === 'admin';
                $isCancel = $menuCode === 'sales-cancel';

                DB::table('access_role_menu_permissions')->updateOrInsert(
                    [
                        'access_role_id' => $access->access_role_id,
                        'access_level_id' => $access->access_level_id,
                        'menu_id' => $menu->id,
                    ],
                    [
                        'id' => $existingPermissionId ?: (string) Str::ulid(),
                        'can_view' => true,
                        'can_create' => $isAdmin && $isCancel,
                        'can_edit' => $isAdmin && $isCancel,
                        'can_delete' => $isAdmin && $isCancel,
                        'created_at' => DB::table('access_role_menu_permissions')->where('id', $existingPermissionId)->value('created_at') ?: $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Safety rollback: do not remove restored core menus automatically.
    }
};
