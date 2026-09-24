<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'console';

    private const MENUS = [
        [
            'code' => 'console-control-center',
            'name' => 'Control Center',
            'path' => '/console/control-center',
            'sort_order' => 10,
            'permission_view' => 'console.control_center.view',
            'permission_create' => 'console.control_center.run',
            'permission_update' => 'console.control_center.configure',
            'permission_delete' => 'console.control_center.force_rebuild',
        ],
        [
            'code' => 'console-system-health',
            'name' => 'System Health',
            'path' => '/console/system-health',
            'sort_order' => 20,
            'permission_view' => 'console.system_health.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
        ],
        [
            'code' => 'console-file-management',
            'name' => 'File Management',
            'path' => '/console/file-management',
            'sort_order' => 30,
            'permission_view' => 'console.file_management.view',
            'permission_create' => 'console.file_management.create_folder',
            'permission_update' => 'console.file_management.move',
            'permission_delete' => 'console.file_management.delete',
        ],
    ];

    public function up(): void
    {
        $this->ensureSpatiePermissions();

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return;
        }

        $now = now();
        $existingPortal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        $portalId = (string) ($existingPortal->id ?? Str::ulid());
        $maxOtherSort = (int) (DB::table('access_portals')->where('code', '!=', self::PORTAL_CODE)->max('sort_order') ?? 0);

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL_CODE],
            [
                'id' => $portalId,
                'name' => 'Console',
                'description' => 'System console untuk observability, reporting control, dan storage file management.',
                'sort_order' => max(9999, $maxOtherSort + 100),
                'is_active' => true,
                'created_at' => $existingPortal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menuIds = [];
        foreach (self::MENUS as $menuSeed) {
            $existingMenu = DB::table('access_menus')->where('code', $menuSeed['code'])->first();
            $menuId = (string) ($existingMenu->id ?? Str::ulid());
            $menuIds[$menuSeed['code']] = $menuId;

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menuSeed['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portalId,
                    'name' => $menuSeed['name'],
                    'path' => $menuSeed['path'],
                    'sort_order' => $menuSeed['sort_order'],
                    'permission_view' => $menuSeed['permission_view'],
                    'permission_create' => $menuSeed['permission_create'],
                    'permission_update' => $menuSeed['permission_update'],
                    'permission_delete' => $menuSeed['permission_delete'],
                    'is_active' => true,
                    'created_at' => $existingMenu->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->ensureAdministratorMatrix($portalId, $menuIds, $now);
        $this->ensureAdministratorSpatiePermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Recovery hotfix is intentionally non-destructive. Removing canonical
        // Console access on rollback could hide operational controls again.
    }

    private function ensureSpatiePermissions(): void
    {
        foreach (self::MENUS as $menu) {
            foreach (['permission_view', 'permission_create', 'permission_update', 'permission_delete'] as $column) {
                $permission = trim((string) ($menu[$column] ?? ''));
                if ($permission !== '') {
                    Permission::findOrCreate($permission, 'web');
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureAdministratorMatrix(string $portalId, array $menuIds, $now): void
    {
        if (! Schema::hasTable('access_roles')
            || ! Schema::hasTable('access_role_portal_permissions')
            || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $adminRoleIds = DB::table('access_roles')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("UPPER(COALESCE(name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("LOWER(COALESCE(spatie_role_name,'')) IN ('admin','administrator','superadmin','super-admin')");
            })
            ->pluck('id');

        foreach ($adminRoleIds as $roleId) {
            $portalExists = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $roleId)
                ->whereNull('access_level_id')
                ->where('portal_id', $portalId)
                ->exists();

            if (! $portalExists) {
                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $roleId,
                    'access_level_id' => null,
                    'portal_id' => $portalId,
                    'can_view' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (self::MENUS as $menu) {
                $menuId = $menuIds[$menu['code']] ?? null;
                if (! $menuId) {
                    continue;
                }

                $menuExists = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $roleId)
                    ->whereNull('access_level_id')
                    ->where('menu_id', $menuId)
                    ->exists();

                if ($menuExists) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $roleId,
                    'access_level_id' => null,
                    'menu_id' => $menuId,
                    'can_view' => true,
                    'can_create' => ! empty($menu['permission_create']),
                    'can_edit' => ! empty($menu['permission_update']),
                    'can_delete' => ! empty($menu['permission_delete']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function ensureAdministratorSpatiePermissions(): void
    {
        $permissions = collect(self::MENUS)
            ->flatMap(fn (array $menu) => [
                $menu['permission_view'] ?? null,
                $menu['permission_create'] ?? null,
                $menu['permission_update'] ?? null,
                $menu['permission_delete'] ?? null,
            ])
            ->filter()
            ->values()
            ->all();

        Role::query()
            ->where('guard_name', 'web')
            ->where(function ($query): void {
                $query->whereRaw("LOWER(name) IN ('admin','administrator','superadmin','super-admin')");
            })
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
};
