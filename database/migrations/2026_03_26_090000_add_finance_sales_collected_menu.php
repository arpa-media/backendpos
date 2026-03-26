<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $portalId = DB::table('access_portals')->where('code', 'finance')->value('id');
        if (!$portalId) {
            return;
        }

        $menuCode = 'finance-sales-collected';
        $menuId = DB::table('access_menus')->where('code', $menuCode)->value('id');

        if (!$menuId) {
            $menuId = (string) Str::ulid();
            DB::table('access_menus')->insert([
                'id' => $menuId,
                'portal_id' => $portalId,
                'code' => $menuCode,
                'name' => 'Sales Collected',
                'path' => '/finance/sales-collected',
                'sort_order' => 15,
                'permission_view' => 'sale.view',
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sourceMenuId = DB::table('access_menus')->where('code', 'sales-list')->value('id');
        if (!$sourceMenuId) {
            return;
        }

        $existing = DB::table('access_role_menu_permissions')->where('menu_id', $menuId)->exists();
        if ($existing) {
            return;
        }

        $rows = DB::table('access_role_menu_permissions')
            ->where('menu_id', $sourceMenuId)
            ->get();

        foreach ($rows as $row) {
            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $menuId,
                'can_view' => $row->can_view,
                'can_create' => $row->can_create,
                'can_edit' => $row->can_edit,
                'can_delete' => $row->can_delete,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $menuId = DB::table('access_menus')->where('code', 'finance-sales-collected')->value('id');
        if (!$menuId) {
            return;
        }

        DB::table('access_role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('access_menus')->where('id', $menuId)->delete();
    }
};
