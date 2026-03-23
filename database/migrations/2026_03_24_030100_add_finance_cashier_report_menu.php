<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $portalId = DB::table('access_portals')->where('code', 'finance')->value('id');
        if (!$portalId) {
            return;
        }

        $now = now();
        $code = 'finance-cashier-report';
        $path = '/finance/cashier-report';

        $menu = DB::table('access_menus')
            ->where('code', $code)
            ->orWhere('path', $path)
            ->first();

        $payload = [
            'portal_id' => $portalId,
            'name' => 'Cashier Report',
            'path' => $path,
            'sort_order' => 35,
            'permission_view' => 'report.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($menu) {
            DB::table('access_menus')->where('id', $menu->id)->update($payload + ['code' => $code]);
        } else {
            DB::table('access_menus')->insert($payload + [
                'id' => (string) Str::ulid(),
                'code' => $code,
                'created_at' => $now,
            ]);
        }

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $menuId = DB::table('access_menus')->where('code', $code)->value('id');
            $roleIds = DB::table('access_roles')->pluck('id', 'code');
            $allowed = ['ADMIN', 'MANAGER', 'WAREHOUSE', 'CASHIER'];

            foreach ($allowed as $roleCode) {
                $roleId = $roleIds[$roleCode] ?? null;
                if (!$roleId || !$menuId) continue;

                $existing = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $roleId)
                    ->whereNull('access_level_id')
                    ->where('menu_id', $menuId)
                    ->first();

                $row = [
                    'can_view' => true,
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('access_role_menu_permissions')->insert($row + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $roleId,
                        'access_level_id' => null,
                        'menu_id' => $menuId,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('access_menus')) {
            return;
        }

        $menuId = DB::table('access_menus')->where('code', 'finance-cashier-report')->value('id');
        if ($menuId && Schema::hasTable('access_role_menu_permissions')) {
            DB::table('access_role_menu_permissions')->where('menu_id', $menuId)->delete();
        }
        DB::table('access_menus')->where('code', 'finance-cashier-report')->delete();
    }
};
