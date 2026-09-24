<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const EXCLUDED_ROLES = ['STAKEHOLDER', 'OBSERVER'];

    public function up(): void
    {
        $this->repairDashboardSelfServiceMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Data repair only. Do not rollback Access Matrix rows because they may
        // already have been adjusted by an administrator after this migration.
    }

    private function repairDashboardSelfServiceMatrix(): void
    {
        foreach (['access_roles', 'access_menus', 'access_role_menu_permissions'] as $table) {
            if (! Schema::hasTable($table)) return;
        }

        $guard = config('auth.defaults.guard', 'web');
        $menus = [
            'hr-self-shift-schedule' => [
                'permissions' => [
                    'view' => 'hr.schedule.self.view',
                    'create' => null,
                    'edit' => null,
                    'delete' => null,
                ],
                'rights' => ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
            ],
            'hr-self-leave-request' => [
                'permissions' => [
                    'view' => 'hr.leave.self.view',
                    'create' => 'hr.leave.self.create',
                    'edit' => null,
                    'delete' => 'hr.leave.self.cancel',
                ],
                'rights' => ['can_view' => true, 'can_create' => true, 'can_edit' => false, 'can_delete' => true],
            ],
            'hr-self-payroll-slip' => [
                'permissions' => [
                    'view' => 'hr.payroll.self.view',
                    'create' => null,
                    'edit' => null,
                    'delete' => null,
                ],
                'rights' => ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
            ],
        ];

        if (Schema::hasTable('permissions')) {
            foreach ($menus as $config) {
                foreach (array_filter($config['permissions']) as $permission) {
                    Permission::findOrCreate($permission, $guard);
                }
            }
        }

        $levelIds = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $now = now();

        foreach ($menus as $code => $config) {
            $menu = DB::table('access_menus')->where('code', $code)->first();
            if (! $menu) continue;

            DB::table('access_menus')->where('id', $menu->id)->update([
                'permission_view' => $config['permissions']['view'],
                'permission_create' => $config['permissions']['create'],
                'permission_update' => $config['permissions']['edit'],
                'permission_delete' => $config['permissions']['delete'],
                'is_active' => true,
                'updated_at' => $now,
            ]);

            foreach (DB::table('access_roles')->where('is_active', true)->get(['id', 'code']) as $role) {
                $allowed = ! in_array(strtoupper(trim((string) $role->code)), self::EXCLUDED_ROLES, true);
                foreach (array_merge([null], $levelIds) as $levelId) {
                    $rights = array_map(fn ($value) => $allowed ? (bool) $value : false, $config['rights']);
                    $this->upsertMenuMatrix((string) $role->id, $levelId, (string) $menu->id, $rights, $now);
                }
            }
        }
    }

    private function upsertMenuMatrix(string $roleId, ?string $levelId, string $menuId, array $rights, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

        $payload = [
            'can_view' => (bool) ($rights['can_view'] ?? false),
            'can_create' => (bool) ($rights['can_create'] ?? false),
            'can_edit' => (bool) ($rights['can_edit'] ?? false),
            'can_delete' => (bool) ($rights['can_delete'] ?? false),
            'updated_at' => $now,
        ];

        $existing = $query->first();
        if ($existing) {
            DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('access_role_menu_permissions')->insert($payload + [
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'created_at' => $now,
        ]);
    }
};
