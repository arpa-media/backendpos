<?php

use App\Models\AccessLevel;
use App\Models\AccessRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $guard = config('auth.defaults.guard', 'web');

        if (class_exists(Permission::class)) {
            Permission::findOrCreate('owner_overview.sale_detail.view', $guard);
        }

        $portalId = DB::table('access_portals')->where('code', 'omzet-report')->value('id');
        if (!$portalId) {
            return;
        }

        $menu = DB::table('access_menus')->where('code', 'owner-overview-detail-sales')->first();
        if ($menu) {
            DB::table('access_menus')->where('id', $menu->id)->update([
                'portal_id' => $portalId,
                'name' => 'Detail Sales',
                'path' => '/owner-overview/detail-sales',
                'sort_order' => 31,
                'permission_view' => 'owner_overview.sale_detail.view',
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => 1,
                'updated_at' => $now,
            ]);
            $menuId = (string) $menu->id;
        } else {
            $menuId = (string) Str::ulid();
            DB::table('access_menus')->insert([
                'id' => $menuId,
                'portal_id' => $portalId,
                'code' => 'owner-overview-detail-sales',
                'name' => 'Detail Sales',
                'path' => '/owner-overview/detail-sales',
                'sort_order' => 31,
                'permission_view' => 'owner_overview.sale_detail.view',
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $adminRoleId = AccessRole::query()->where('code', 'ADMIN')->value('id');
        $defaultLevelId = AccessLevel::query()->where('code', 'DEFAULT')->value('id')
            ?: AccessLevel::query()->where('code', 'HQ')->value('id');

        if (!$adminRoleId) {
            return;
        }

        $existing = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $adminRoleId)
            ->where(function ($query) use ($defaultLevelId) {
                if ($defaultLevelId) {
                    $query->where('access_level_id', $defaultLevelId);
                } else {
                    $query->whereNull('access_level_id');
                }
            })
            ->where('menu_id', $menuId)
            ->first();

        $payload = [
            'can_view' => 1,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $adminRoleId,
            'access_level_id' => $defaultLevelId,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // no-op hotfix
    }
};
