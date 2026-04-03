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
        $salesPortalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        $posPortalId = DB::table('access_portals')->where('code', 'pos')->value('id');

        if (!$posPortalId) {
            $posPortalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $posPortalId,
                'code' => 'pos',
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 9,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('access_portals')->where('id', $posPortalId)->update([
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 9,
                'is_active' => 1,
                'updated_at' => $now,
            ]);
        }

        if ($salesPortalId && Schema::hasTable('access_role_portal_permissions')) {
            $salesRows = DB::table('access_role_portal_permissions')->where('portal_id', $salesPortalId)->get();

            foreach ($salesRows as $row) {
                $query = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $row->access_role_id)
                    ->where('portal_id', $posPortalId);

                if ($row->access_level_id) {
                    $query->where('access_level_id', $row->access_level_id);
                } else {
                    $query->whereNull('access_level_id');
                }

                $existing = $query->first();
                $canView = (bool) ($row->can_view || ($existing->can_view ?? false));

                if ($existing) {
                    DB::table('access_role_portal_permissions')->where('id', $existing->id)->update([
                        'can_view' => $canView,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('access_role_portal_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $row->access_role_id,
                        'access_level_id' => $row->access_level_id,
                        'portal_id' => $posPortalId,
                        'can_view' => $canView,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
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
            $dashboardMenuId = (string) $dashboardMenu->id;
        } else {
            $dashboardMenuId = (string) Str::ulid();
            DB::table('access_menus')->insert($dashboardPayload + [
                'id' => $dashboardMenuId,
                'created_at' => $now,
            ]);
        }

        if (!Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $sourceMenuIds = DB::table('access_menus')
            ->whereIn('code', ['sales-dashboard', 'pos-dashboard', 'sales-pos-terminal', 'sales-pos'])
            ->orWhereIn('path', ['/dashboard', '/c/pos', '/c/dashboard'])
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($sourceMenuIds->isEmpty()) {
            return;
        }

        $sourceRows = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $sourceMenuIds->all())
            ->get()
            ->groupBy(function ($row) {
                return implode('|', [
                    (string) $row->access_role_id,
                    $row->access_level_id ? (string) $row->access_level_id : 'null',
                ]);
            })
            ->map(function ($rows) {
                return $rows->sortByDesc(function ($row) {
                    return (int) (($row->can_view ?? false) ? 1 : 0);
                })->first();
            })
            ->values();

        foreach ($sourceRows as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $dashboardMenuId);

            if ($row->access_level_id) {
                $query->where('access_level_id', $row->access_level_id);
            } else {
                $query->whereNull('access_level_id');
            }

            $existing = $query->first();
            $canView = (bool) ($row->can_view || ($existing->can_view ?? false));

            $payload = [
                'can_view' => $canView,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
                continue;
            }

            DB::table('access_role_menu_permissions')->insert($payload + [
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $dashboardMenuId,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // additive repair migration; no destructive rollback
    }
};
