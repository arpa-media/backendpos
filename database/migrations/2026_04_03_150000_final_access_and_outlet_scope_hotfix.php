<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $posPortalId = $this->upsertPosPortal($now);
        $dashboardMenuId = $this->upsertPosDashboardMenu($posPortalId, $now);
        $this->copyPosDashboardPermissions($dashboardMenuId, $now);
        $this->syncPortalPermissionFromVisibleMenus('finance', $now);
        $this->syncPortalPermissionFromVisibleMenus('pos', $now);
    }

    public function down(): void
    {
        // hotfix only
    }

    private function upsertPosPortal($now): string
    {
        $portal = DB::table('access_portals')->where('code', 'pos')->first();
        $payload = [
            'code' => 'pos',
            'name' => 'POS',
            'description' => 'Portal POS Android dan web cashier.',
            'sort_order' => 9,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($portal) {
            DB::table('access_portals')->where('id', $portal->id)->update($payload);
            return (string) $portal->id;
        }

        $id = (string) Str::ulid();
        DB::table('access_portals')->insert($payload + [
            'id' => $id,
            'created_at' => $now,
        ]);

        return $id;
    }

    private function upsertPosDashboardMenu(string $posPortalId, $now): string
    {
        $menu = DB::table('access_menus')
            ->where('code', 'pos-dashboard')
            ->orWhere('path', '/c/dashboard')
            ->first();

        $payload = [
            'portal_id' => $posPortalId,
            'code' => 'pos-dashboard',
            'name' => 'Dashboard',
            'path' => '/c/dashboard',
            'sort_order' => 10,
            'permission_view' => 'dashboard.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($menu) {
            DB::table('access_menus')->where('id', $menu->id)->update($payload);
            return (string) $menu->id;
        }

        $id = (string) Str::ulid();
        DB::table('access_menus')->insert($payload + [
            'id' => $id,
            'created_at' => $now,
        ]);

        return $id;
    }

    private function copyPosDashboardPermissions(string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $sourceMenuIds = DB::table('access_menus')
            ->whereIn('code', ['sales-dashboard', 'pos-dashboard'])
            ->orWhereIn('path', ['/dashboard'])
            ->pluck('id')
            ->filter(fn ($id) => (string) $id !== (string) $targetMenuId)
            ->unique()
            ->values();

        if ($sourceMenuIds->isEmpty()) {
            return;
        }

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
            $existing = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId)
                ->when($row->access_level_id, fn ($query) => $query->where('access_level_id', $row->access_level_id), fn ($query) => $query->whereNull('access_level_id'))
                ->value('id');

            if ($existing) {
                continue;
            }

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $targetMenuId,
                'can_view' => (bool) $row->can_view,
                'can_create' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function syncPortalPermissionFromVisibleMenus(string $portalCode, $now): void
    {
        if (! Schema::hasTable('access_role_portal_permissions') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $portalId = DB::table('access_portals')->where('code', $portalCode)->value('id');
        if (! $portalId) {
            return;
        }

        $menuIds = DB::table('access_menus')->where('portal_id', $portalId)->pluck('id')->filter()->values();
        if ($menuIds->isEmpty()) {
            return;
        }

        $sourceRows = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $menuIds->all())
            ->where(function ($query) {
                $query->where('can_view', 1)
                    ->orWhere('can_create', 1)
                    ->orWhere('can_edit', 1)
                    ->orWhere('can_delete', 1);
            })
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
            $existing = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('portal_id', $portalId)
                ->when($row->access_level_id, fn ($query) => $query->where('access_level_id', $row->access_level_id), fn ($query) => $query->whereNull('access_level_id'))
                ->first();

            if ($existing) {
                if (! $existing->can_view) {
                    DB::table('access_role_portal_permissions')->where('id', $existing->id)->update([
                        'can_view' => 1,
                        'updated_at' => $now,
                    ]);
                }
                continue;
            }

            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'portal_id' => $portalId,
                'can_view' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
