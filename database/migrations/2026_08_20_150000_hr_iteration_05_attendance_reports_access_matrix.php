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
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code' => 'human-resource'], [
            'id' => $portalId, 'name' => 'Human Resource', 'description' => 'Portal Human Resource',
            'sort_order' => 20, 'is_active' => true, 'created_at' => $portal->created_at ?? $now, 'updated_at' => $now,
        ]);

        $menus = [
            ['code' => 'hr-attendance-daily-report', 'name' => 'Daily Report', 'path' => '/human-resource/data-absensi-daily-report', 'sort' => 33, 'permission' => 'hr.attendance.daily-report.view'],
            ['code' => 'hr-attendance-late-report', 'name' => 'Data Terlambat', 'path' => '/human-resource/data-absensi-data-terlambat', 'sort' => 34, 'permission' => 'hr.attendance.late.view'],
            ['code' => 'hr-attendance-recap-report', 'name' => 'Rekap Absensi', 'path' => '/human-resource/data-absensi-rekap-absensi', 'sort' => 35, 'permission' => 'hr.attendance.recap.view'],
        ];
        $guard = config('auth.defaults.guard', 'web');
        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $menu['code']], [
                'id' => $menuId, 'portal_id' => $portalId, 'name' => $menu['name'], 'path' => $menu['path'],
                'sort_order' => $menu['sort'], 'permission_view' => $menu['permission'],
                'permission_create' => null, 'permission_update' => null, 'permission_delete' => null,
                'is_active' => true, 'created_at' => $existing->created_at ?? $now, 'updated_at' => $now,
            ]);
            if (Schema::hasTable('permissions')) Permission::findOrCreate($menu['permission'], $guard);
            $this->seedMatrix($menuId, $portalId, $now);
        }
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMatrix(string $menuId, string $portalId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($v) => (string) $v)->all() : [];
        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $isAdmin = $roleCode === 'ADMIN';
            $isManager = $roleCode === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $portalCanView = $isAdmin || $isManager;
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $base = DB::table('access_role_portal_permissions')->where('access_role_id', $role->id)->where('portal_id', $portalId)->whereNull('access_level_id')->first();
                    $exact = $levelId === null ? null : DB::table('access_role_portal_permissions')->where('access_role_id', $role->id)->where('portal_id', $portalId)->where('access_level_id', $levelId)->first();
                    $effective = $exact ?: $base;
                    if ($effective) $portalCanView = $portalCanView || (bool) $effective->can_view;
                }
                $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(), 'access_role_id' => $role->id, 'access_level_id' => $levelId, 'menu_id' => $menuId,
                    'can_view' => $portalCanView, 'can_create' => false, 'can_edit' => false, 'can_delete' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: report access can be customized through Access Matrix.
    }
};
