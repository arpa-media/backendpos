<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $portalId = DB::table('access_portals')->where('code', 'finance')->value('id');
        if (!$portalId) {
            return;
        }

        $salesSummaryMenuId = $this->upsertMenu(
            $portalId,
            'finance-sales-summary',
            'Sales Summary',
            '/finance/sales-summary',
            16,
            'sale.view'
        );

        $categorySummaryMenuId = $this->upsertMenu(
            $portalId,
            'finance-category-summary',
            'Category Summary',
            '/finance/category-summary',
            17,
            'report.view'
        );

        if (Schema::hasTable('access_role_menu_permissions')) {
            $this->clonePermissionsByMenuCode('sales-list', $salesSummaryMenuId);
            $this->clonePermissionsByMenuCode('sales-report', $categorySummaryMenuId);
        }
    }

    private function upsertMenu(string $portalId, string $code, string $name, string $path, int $sortOrder, string $permissionView): ?string
    {
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $payload = [
            'portal_id' => $portalId,
            'code' => $code,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sortOrder,
            'permission_view' => $permissionView,
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('access_menus')->where('id', $existing->id)->update($payload);
            return (string) $existing->id;
        }

        $id = (string) Str::ulid();
        DB::table('access_menus')->insert($payload + [
            'id' => $id,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function clonePermissionsByMenuCode(string $sourceCode, ?string $targetMenuId): void
    {
        if (!$targetMenuId) {
            return;
        }

        $sourceMenuId = DB::table('access_menus')->where('code', $sourceCode)->value('id');
        if (!$sourceMenuId) {
            return;
        }

        $rows = DB::table('access_role_menu_permissions')
            ->where('menu_id', $sourceMenuId)
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('access_role_menu_permissions')
                ->where('menu_id', $targetMenuId)
                ->where('access_role_id', $row->access_role_id)
                ->where(function ($query) use ($row) {
                    if ($row->access_level_id === null) {
                        $query->whereNull('access_level_id');
                    } else {
                        $query->where('access_level_id', $row->access_level_id);
                    }
                })
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $targetMenuId,
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
        if (!Schema::hasTable('access_menus')) {
            return;
        }

        $menuIds = DB::table('access_menus')
            ->whereIn('code', ['finance-sales-summary', 'finance-category-summary'])
            ->pluck('id')
            ->filter()
            ->values();

        if ($menuIds->isNotEmpty() && Schema::hasTable('access_role_menu_permissions')) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        }

        DB::table('access_menus')->whereIn('code', ['finance-sales-summary', 'finance-category-summary'])->delete();
    }
};
