<?php

namespace App\Services\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ConsoleCanonicalAccessRecoveryService
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

    public function ensureReady(): array
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return $this->repair();
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal || ! (bool) ($portal->is_active ?? false)) {
            return $this->repair();
        }

        $maxSort = (int) (DB::table('access_portals')->max('sort_order') ?? 0);
        if ((int) ($portal->sort_order ?? 0) !== $maxSort) {
            return $this->repair();
        }

        $menuCount = DB::table('access_menus')
            ->where('portal_id', $portal->id)
            ->whereIn('code', collect(self::MENUS)->pluck('code')->all())
            ->where('is_active', true)
            ->count();
        if ($menuCount !== count(self::MENUS)) {
            return $this->repair();
        }

        if (! Schema::hasTable('access_roles')
            || ! Schema::hasTable('access_role_portal_permissions')
            || ! Schema::hasTable('access_role_menu_permissions')) {
            return $this->repair();
        }

        $adminRoles = DB::table('access_roles')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("UPPER(COALESCE(spatie_role_name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')");
            })
            ->pluck('id');

        if ($adminRoles->isEmpty()) {
            return $this->repair();
        }

        foreach ($adminRoles as $roleId) {
            $basePortal = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $roleId)
                ->whereNull('access_level_id')
                ->where('portal_id', $portal->id)
                ->where('can_view', true)
                ->exists();
            if (! $basePortal) {
                return $this->repair();
            }

            $menuIds = DB::table('access_menus')
                ->where('portal_id', $portal->id)
                ->whereIn('code', collect(self::MENUS)->pluck('code')->all())
                ->pluck('id');
            $baseMenuCount = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $roleId)
                ->whereNull('access_level_id')
                ->whereIn('menu_id', $menuIds->all())
                ->where('can_view', true)
                ->count();
            if ($baseMenuCount !== $menuIds->count()) {
                return $this->repair();
            }

            if (Schema::hasTable('access_levels')) {
                foreach (DB::table('access_levels')->pluck('id') as $levelId) {
                    $exactPortal = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $roleId)
                        ->where('access_level_id', $levelId)
                        ->where('portal_id', $portal->id)
                        ->where('can_view', true)
                        ->exists();
                    if (! $exactPortal) {
                        return $this->repair();
                    }
                }
            }
        }

        return [
            'portal_id' => (string) $portal->id,
            'portal_sort_order' => (int) $portal->sort_order,
            'status' => 'ready',
        ];
    }

    public function repair(): array
    {
        $result = [
            'portal_id' => null,
            'portal_sort_order' => null,
            'menu_ids' => [],
            'backoffice_roles' => 0,
            'admin_roles' => 0,
            'portal_rows_created' => 0,
            'menu_rows_created' => 0,
        ];

        $this->ensureSpatiePermissions();

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return $result;
        }

        $now = now();
        $existingPortal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        $portalId = (string) ($existingPortal->id ?? Str::ulid());
        $maxOtherSort = (int) (DB::table('access_portals')->where('code', '!=', self::PORTAL_CODE)->max('sort_order') ?? 0);
        $consoleSort = max(999999, $maxOtherSort + 1000);

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL_CODE],
            [
                'id' => $portalId,
                'name' => 'Console',
                'description' => 'System console untuk observability, reporting control, dan storage file management.',
                'sort_order' => $consoleSort,
                'is_active' => true,
                'created_at' => $existingPortal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $result['portal_id'] = $portalId;
        $result['portal_sort_order'] = $consoleSort;

        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $result['menu_ids'][$menu['code']] = $menuId;

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission_view'],
                    'permission_create' => $menu['permission_create'],
                    'permission_update' => $menu['permission_update'],
                    'permission_delete' => $menu['permission_delete'],
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->ensureAccessMatrixRows($portalId, $result['menu_ids'], $now, $result);
        $this->ensureAdminSpatiePermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    private function ensureAccessMatrixRows(string $portalId, array $menuIds, $now, array &$result): void
    {
        if (! Schema::hasTable('access_roles')
            || ! Schema::hasTable('access_role_portal_permissions')
            || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $backofficeTypeIds = collect();
        if (Schema::hasTable('access_user_types')) {
            $backofficeTypeIds = DB::table('access_user_types')
                ->whereRaw("UPPER(COALESCE(code,'')) = 'BACKOFFICE'")
                ->pluck('id');
        }

        $rolesQuery = DB::table('access_roles');
        if ($backofficeTypeIds->isNotEmpty()) {
            $rolesQuery->whereIn('user_type_id', $backofficeTypeIds->all());
        } else {
            $rolesQuery->where(function ($query): void {
                $query->whereNull('user_type_id')
                    ->orWhereRaw("UPPER(COALESCE(code,'')) <> 'CASHIER'");
            });
        }

        $roles = $rolesQuery->get(['id', 'code', 'name', 'spatie_role_name']);
        $result['backoffice_roles'] = $roles->count();

        foreach ($roles as $role) {
            $isAdmin = $this->isAdminAccessRole($role);
            if ($isAdmin) {
                $result['admin_roles']++;
            }

            $portalRow = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $role->id)
                ->whereNull('access_level_id')
                ->where('portal_id', $portalId)
                ->first();

            if (! $portalRow) {
                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => null,
                    'portal_id' => $portalId,
                    'can_view' => $isAdmin,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $result['portal_rows_created']++;
            } elseif ($isAdmin && ! (bool) $portalRow->can_view) {
                // HF02 is an explicit Console visibility recovery. Administrator
                // must retain Console visibility; non-admin choices are preserved.
                DB::table('access_role_portal_permissions')->where('id', $portalRow->id)->update([
                    'can_view' => true,
                    'updated_at' => $now,
                ]);
            }

            foreach (self::MENUS as $menu) {
                $menuId = $menuIds[$menu['code']] ?? null;
                if (! $menuId) {
                    continue;
                }

                $menuRow = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->whereNull('access_level_id')
                    ->where('menu_id', $menuId)
                    ->first();

                if (! $menuRow) {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => null,
                        'menu_id' => $menuId,
                        'can_view' => $isAdmin,
                        'can_create' => $isAdmin && ! empty($menu['permission_create']),
                        'can_edit' => $isAdmin && ! empty($menu['permission_update']),
                        'can_delete' => $isAdmin && ! empty($menu['permission_delete']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $result['menu_rows_created']++;
                } elseif ($isAdmin) {
                    DB::table('access_role_menu_permissions')->where('id', $menuRow->id)->update([
                        'can_view' => true,
                        'can_create' => ! empty($menu['permission_create']),
                        'can_edit' => ! empty($menu['permission_update']),
                        'can_delete' => ! empty($menu['permission_delete']),
                        'updated_at' => $now,
                    ]);
                }
            }

            // I11-HF03: exact Access Level rows override base rows at runtime.
            // Ensure Administrator cannot lose Console because of an old exact
            // role+level override left from previous Access Matrix revisions.
            if ($isAdmin && Schema::hasTable('access_levels')) {
                $levelIds = DB::table('access_levels')->pluck('id');
                foreach ($levelIds as $levelId) {
                    $this->upsertPortalMatrixRow((string) $role->id, (string) $levelId, $portalId, true, $now, $result);

                    foreach (self::MENUS as $menu) {
                        $menuId = $menuIds[$menu['code']] ?? null;
                        if (! $menuId) {
                            continue;
                        }
                        $this->upsertMenuMatrixRow(
                            (string) $role->id,
                            (string) $levelId,
                            (string) $menuId,
                            true,
                            ! empty($menu['permission_create']),
                            ! empty($menu['permission_update']),
                            ! empty($menu['permission_delete']),
                            $now,
                            $result
                        );
                    }
                }
            }
        }
    }

    private function upsertPortalMatrixRow(string $roleId, string $levelId, string $portalId, bool $canView, $now, array &$result): void
    {
        $row = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('access_level_id', $levelId)
            ->where('portal_id', $portalId)
            ->first();

        if (! $row) {
            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $roleId,
                'access_level_id' => $levelId,
                'portal_id' => $portalId,
                'can_view' => $canView,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result['portal_rows_created']++;
            return;
        }

        if ((bool) $row->can_view !== $canView) {
            DB::table('access_role_portal_permissions')->where('id', $row->id)->update([
                'can_view' => $canView,
                'updated_at' => $now,
            ]);
        }
    }

    private function upsertMenuMatrixRow(
        string $roleId,
        string $levelId,
        string $menuId,
        bool $canView,
        bool $canCreate,
        bool $canEdit,
        bool $canDelete,
        $now,
        array &$result
    ): void {
        $row = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('access_level_id', $levelId)
            ->where('menu_id', $menuId)
            ->first();

        $payload = [
            'can_view' => $canView,
            'can_create' => $canCreate,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'updated_at' => $now,
        ];

        if (! $row) {
            DB::table('access_role_menu_permissions')->insert($payload + [
                'id' => (string) Str::ulid(),
                'access_role_id' => $roleId,
                'access_level_id' => $levelId,
                'menu_id' => $menuId,
                'created_at' => $now,
            ]);
            $result['menu_rows_created']++;
            return;
        }

        DB::table('access_role_menu_permissions')->where('id', $row->id)->update($payload);
    }

    private function isAdminAccessRole(object $role): bool
    {
        $candidates = [
            strtoupper(trim((string) ($role->code ?? ''))),
            strtoupper(trim((string) ($role->name ?? ''))),
            strtoupper(trim((string) ($role->spatie_role_name ?? ''))),
        ];

        foreach ($candidates as $value) {
            if (in_array($value, ['ADMIN', 'ADMINISTRATOR', 'SUPERADMIN', 'SUPER-ADMIN'], true)) {
                return true;
            }
        }

        return false;
    }

    private function ensureSpatiePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

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

    private function ensureAdminSpatiePermissions(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

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
}
