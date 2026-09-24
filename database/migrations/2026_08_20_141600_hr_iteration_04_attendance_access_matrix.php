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
            'id' => $portalId,
            'name' => 'Human Resource',
            'description' => 'Portal Human Resource',
            'sort_order' => 20,
            'is_active' => true,
            'created_at' => $portal->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $menus = [
            [
                'code' => 'hr-data-attendance', 'name' => 'Data Absen',
                'path' => '/human-resource/data-absensi/data-absen', 'sort' => 32,
                'view' => 'hr.attendance.data.view', 'create' => null, 'update' => null, 'delete' => null,
            ],
            [
                'code' => 'hr-approval-attendance', 'name' => 'Approval Absen',
                'path' => '/human-resource/approval-absen', 'sort' => 41,
                'view' => 'hr.attendance.approval.view', 'create' => 'hr.attendance.approval.spv',
                'update' => 'hr.attendance.approval.hrd', 'delete' => 'hr.attendance.approval.override_spv',
            ],
            [
                'code' => 'hr-approval-duty', 'name' => 'Approval Dinas',
                'path' => '/human-resource/approval-dinas', 'sort' => 42,
                'view' => 'hr.attendance.duty.view', 'create' => 'hr.attendance.duty.spv',
                'update' => 'hr.attendance.duty.hrd', 'delete' => 'hr.attendance.duty.override_spv',
            ],
        ];

        $guard = config('auth.defaults.guard', 'web');
        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $menu['code']], [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort'],
                'permission_view' => $menu['view'],
                'permission_create' => $menu['create'],
                'permission_update' => $menu['update'],
                'permission_delete' => $menu['delete'],
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]);

            if (Schema::hasTable('permissions')) {
                foreach (array_filter([$menu['view'], $menu['create'], $menu['update'], $menu['delete']]) as $permission) {
                    Permission::findOrCreate($permission, $guard);
                }
            }
            $this->seedMatrix($menuId, $menu['code'], $now);
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMatrix(string $menuId, string $menuCode, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $isAdmin = $roleCode === 'ADMIN';
            $isManager = $roleCode === 'MANAGER';
            $view = $isAdmin || $isManager;

            foreach (array_merge([null], $levels) as $levelId) {
                $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
                if ($q->exists()) continue;

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $view,
                    // Stage approval rights are intentionally ADMIN-only by default.
                    // HR can delegate SPV/HRD separately from Access Matrix after migration.
                    'can_create' => $isAdmin && $menuCode !== 'hr-data-attendance',
                    'can_edit' => $isAdmin && $menuCode !== 'hr-data-attendance',
                    'can_delete' => $isAdmin && $menuCode !== 'hr-data-attendance',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive because Access Matrix may be customized after deployment.
    }
};
