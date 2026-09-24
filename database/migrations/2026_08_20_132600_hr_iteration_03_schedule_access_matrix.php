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
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $hrPortalId = $this->ensurePortal('human-resource', 'Human Resource', 'Portal Human Resource', 20, $now);
        $attendancePortalId = $this->ensurePortal('attendance', 'Absensi Squad', 'Self service absensi dan jadwal user/squad', 21, $now);

        $mappingMenuId = $this->ensureMenu(
            $hrPortalId,
            'hr-mapping-schedule',
            'Mapping Schedule',
            '/human-resource/data-absensi/mapping-schedule',
            31,
            'hr.schedule.view',
            'hr.schedule.create',
            'hr.schedule.update',
            'hr.schedule.delete',
            $now
        );

        $selfMenuId = $this->ensureMenu(
            $attendancePortalId,
            'hr-self-shift-schedule',
            'Jadwal Shift',
            '/user-dashboard',
            20,
            'hr.schedule.self.view',
            null,
            null,
            null,
            $now
        );

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ([
                'hr.schedule.view', 'hr.schedule.create', 'hr.schedule.update', 'hr.schedule.delete',
                'hr.schedule.self.view',
            ] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        $this->seedAdminMappingMatrix($hrPortalId, $mappingMenuId, $now);
        $this->seedSelfMatrix($selfMenuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensurePortal(string $code, string $name, string $description, int $sort, $now): string
    {
        $existing = DB::table('access_portals')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'sort_order' => $sort,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function ensureMenu(
        string $portalId,
        string $code,
        string $name,
        string $path,
        int $sort,
        ?string $view,
        ?string $create,
        ?string $update,
        ?string $delete,
        $now
    ): string {
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'portal_id' => $portalId,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sort,
            'permission_view' => $view,
            'permission_create' => $create,
            'permission_update' => $update,
            'permission_delete' => $delete,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function seedAdminMappingMatrix(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
            foreach (array_merge([null], $levels) as $levelId) {
                $portalCanView = $isAdmin;
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $base = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)->where('portal_id', $portalId)
                        ->whereNull('access_level_id')->first();
                    $exact = $levelId === null ? null : DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)->where('portal_id', $portalId)
                        ->where('access_level_id', $levelId)->first();
                    $effective = $exact ?: $base;
                    if ($effective) $portalCanView = $portalCanView || (bool) $effective->can_view;
                }
                $this->insertMatrixIfMissing($role->id, $levelId, $menuId, $portalCanView, $isAdmin, $isAdmin, $isAdmin, $now);
            }
        }
    }

    private function seedSelfMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $allowed = ! in_array(strtoupper(trim((string) $role->code)), ['STAKEHOLDER', 'OBSERVER'], true);
            foreach (array_merge([null], $levels) as $levelId) {
                $this->insertMatrixIfMissing($role->id, $levelId, $menuId, $allowed, false, false, false, $now);
            }
        }
    }

    private function insertMatrixIfMissing($roleId, $levelId, string $menuId, bool $view, bool $create, bool $edit, bool $delete, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $view,
            'can_create' => $create,
            'can_edit' => $edit,
            'can_delete' => $delete,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix dapat dikustomisasi setelah deployment.
    }
};
