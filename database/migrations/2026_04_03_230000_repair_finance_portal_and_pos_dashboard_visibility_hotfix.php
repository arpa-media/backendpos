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

        $now = now();

        $posPortalId = DB::table('access_portals')->where('code', 'pos')->value('id');
        if (!$posPortalId) {
            $posPortalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $posPortalId,
                'code' => 'pos',
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 10,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $dashboardMenu = DB::table('access_menus')
            ->where('code', 'pos-dashboard')
            ->orWhere('path', '/c/dashboard')
            ->first();

        $dashboardPayload = [
            'portal_id' => $posPortalId,
            'code' => 'pos-dashboard',
            'name' => 'Dashboard',
            'path' => '/c/dashboard',
            'sort_order' => 12,
            'permission_view' => 'dashboard.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($dashboardMenu) {
            DB::table('access_menus')->where('id', $dashboardMenu->id)->update($dashboardPayload);
            $targetDashboardMenuId = (string) $dashboardMenu->id;
        } else {
            $targetDashboardMenuId = (string) Str::ulid();
            DB::table('access_menus')->insert($dashboardPayload + [
                'id' => $targetDashboardMenuId,
                'created_at' => $now,
            ]);
        }

        if (Schema::hasTable('access_role_menu_permissions')) {
            $sourceMenuIds = DB::table('access_menus')
                ->whereIn('code', ['sales-dashboard', 'pos-dashboard', 'sales-pos-terminal', 'sales-pos'])
                ->orWhereIn('path', ['/dashboard', '/c/pos'])
                ->pluck('id')
                ->filter()
                ->unique()
                ->values();

            if ($sourceMenuIds->isNotEmpty()) {
                $sourceRows = DB::table('access_role_menu_permissions')
                    ->whereIn('menu_id', $sourceMenuIds->all())
                    ->orderByDesc('can_view')
                    ->orderByDesc('updated_at')
                    ->get()
                    ->groupBy(function ($row) {
                        return implode('|', [
                            (string) $row->access_role_id,
                            $row->access_level_id ? (string) $row->access_level_id : 'null',
                        ]);
                    })
                    ->map(fn ($rows) => $rows->first())
                    ->values();

                foreach ($sourceRows as $row) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $row->access_role_id)
                        ->where('menu_id', $targetDashboardMenuId);

                    if ($row->access_level_id) {
                        $query->where('access_level_id', $row->access_level_id);
                    } else {
                        $query->whereNull('access_level_id');
                    }

                    $existingId = $query->value('id');
                    $payload = [
                        'can_view' => (bool) $row->can_view,
                        'can_create' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                        'updated_at' => $now,
                    ];

                    if ($existingId) {
                        DB::table('access_role_menu_permissions')->where('id', $existingId)->update($payload);
                        continue;
                    }

                    DB::table('access_role_menu_permissions')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $row->access_role_id,
                        'access_level_id' => $row->access_level_id,
                        'menu_id' => $targetDashboardMenuId,
                        'created_at' => $now,
                    ]);
                }
            }
        }

        if (!Schema::hasTable('access_role_portal_permissions') || !Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $financePortalId = DB::table('access_portals')->where('code', 'finance')->value('id');
        if (!$financePortalId) {
            return;
        }

        $financeMenuIds = DB::table('access_menus')
            ->where('portal_id', $financePortalId)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($financeMenuIds->isEmpty()) {
            return;
        }

        $financeRoleRows = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $financeMenuIds->all())
            ->where('can_view', 1)
            ->get()
            ->groupBy(function ($row) {
                return implode('|', [
                    (string) $row->access_role_id,
                    $row->access_level_id ? (string) $row->access_level_id : 'null',
                ]);
            })
            ->map(fn ($rows) => $rows->first())
            ->values();

        foreach ($financeRoleRows as $row) {
            $query = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('portal_id', $financePortalId);

            if ($row->access_level_id) {
                $query->where('access_level_id', $row->access_level_id);
            } else {
                $query->whereNull('access_level_id');
            }

            $existingId = $query->value('id');
            $payload = [
                'can_view' => true,
                'updated_at' => $now,
            ];

            if ($existingId) {
                DB::table('access_role_portal_permissions')->where('id', $existingId)->update($payload);
                continue;
            }

            DB::table('access_role_portal_permissions')->insert($payload + [
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'portal_id' => $financePortalId,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // hotfix data repair only
    }
};
