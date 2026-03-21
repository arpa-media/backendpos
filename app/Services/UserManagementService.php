<?php

namespace App\Services;

use App\Models\AccessLevel;
use App\Models\AccessMenu;
use App\Models\AccessPortal;
use App\Models\AccessRole;
use App\Models\AccessRoleMenuPermission;
use App\Models\AccessRolePortalPermission;
use App\Models\AccessUserType;
use App\Models\User;
use App\Models\UserAccessAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UserManagementService
{
    public function ensureAccessAssignment(User $user): UserAccessAssignment
    {
        $existing = UserAccessAssignment::query()
            ->with(['role.userType', 'level'])
            ->firstWhere('user_id', $user->id);

        if ($existing) {
            return $existing;
        }

        $user->loadMissing('roles');
        $roleNames = $user->roles->pluck('name')->map(fn ($name) => strtolower((string) $name))->all();

        $defaultRole = AccessRole::query()->with('userType')
            ->where('code', 'SQUAD_DEFAULT')
            ->orWhere('code', 'CASHIER')
            ->first();

        $targetCode = 'SQUAD_DEFAULT';
        foreach (['admin' => 'ADMIN', 'manager' => 'MANAGER', 'warehouse' => 'WAREHOUSE', 'cashier' => 'CASHIER'] as $spatie => $code) {
            if (in_array($spatie, $roleNames, true)) {
                $targetCode = $code;
                break;
            }
        }

        $accessRole = AccessRole::query()->with('userType')->firstWhere('code', $targetCode)
            ?? $defaultRole
            ?? AccessRole::query()->with('userType')->first();

        $defaultLevel = AccessLevel::query()->where('code', 'DEFAULT')->first()
            ?? AccessLevel::query()->first();

        $assignment = UserAccessAssignment::query()->create([
            'user_id' => $user->id,
            'access_role_id' => $accessRole?->id,
            'access_level_id' => $defaultLevel?->id,
            'assigned_by_user_id' => null,
        ]);

        return $assignment->load(['role.userType', 'level']);
    }

    public function buildSessionAccess(User $user): array
    {
        $assignment = $this->ensureAccessAssignment($user);
        $roleId = $assignment->access_role_id;
        $levelId = $assignment->access_level_id;

        $portals = AccessPortal::query()->where('is_active', true)->orderBy('sort_order')->get();
        $menus = AccessMenu::query()->with('portal')->where('is_active', true)->orderBy('sort_order')->get();

        $basePortalRows = AccessRolePortalPermission::query()
            ->where('access_role_id', $roleId)
            ->whereNull('access_level_id')
            ->get()
            ->keyBy('portal_id');

        $exactPortalRows = $levelId
            ? AccessRolePortalPermission::query()->where('access_role_id', $roleId)->where('access_level_id', $levelId)->get()->keyBy('portal_id')
            : collect();

        $baseMenuRows = AccessRoleMenuPermission::query()
            ->where('access_role_id', $roleId)
            ->whereNull('access_level_id')
            ->get()
            ->keyBy('menu_id');

        $exactMenuRows = $levelId
            ? AccessRoleMenuPermission::query()->where('access_role_id', $roleId)->where('access_level_id', $levelId)->get()->keyBy('menu_id')
            : collect();

        $portalSnapshots = $portals->map(function (AccessPortal $portal) use ($basePortalRows, $exactPortalRows) {
            $effective = $exactPortalRows->get($portal->id) ?: $basePortalRows->get($portal->id);
            return [
                'id' => (string) $portal->id,
                'code' => (string) $portal->code,
                'name' => (string) $portal->name,
                'can_view' => (bool) ($effective->can_view ?? false),
            ];
        })->values()->all();

        $visiblePortalCodes = collect($portalSnapshots)
            ->filter(fn ($row) => !empty($row['can_view']))
            ->map(fn ($row) => strtolower((string) $row['code']))
            ->values()
            ->all();

        $menuSnapshots = $menus->map(function (AccessMenu $menu) use ($baseMenuRows, $exactMenuRows, $visiblePortalCodes) {
            $effective = $exactMenuRows->get($menu->id) ?: $baseMenuRows->get($menu->id);
            $portalCode = strtolower((string) ($menu->portal?->code ?? ''));
            $portalVisible = in_array($portalCode, $visiblePortalCodes, true);

            return [
                'id' => (string) $menu->id,
                'code' => (string) $menu->code,
                'name' => (string) $menu->name,
                'path' => (string) $menu->path,
                'portal_id' => $menu->portal_id ? (string) $menu->portal_id : null,
                'portal_code' => $portalCode,
                'portal_name' => (string) ($menu->portal?->name ?? ''),
                'can_view' => $portalVisible && (bool) ($effective->can_view ?? false),
                'can_create' => $portalVisible && (bool) ($effective->can_create ?? false),
                'can_edit' => $portalVisible && (bool) ($effective->can_edit ?? false),
                'can_delete' => $portalVisible && (bool) ($effective->can_delete ?? false),
            ];
        })->values()->all();

        return [
            'role' => $assignment->role ? [
                'id' => (string) $assignment->role->id,
                'code' => (string) $assignment->role->code,
                'name' => (string) $assignment->role->name,
                'spatie_role_name' => $assignment->role->spatie_role_name,
            ] : null,
            'user_type' => $assignment->role?->userType ? [
                'id' => (string) $assignment->role->userType->id,
                'code' => (string) $assignment->role->userType->code,
                'name' => (string) $assignment->role->userType->name,
            ] : null,
            'level' => $assignment->level ? [
                'id' => (string) $assignment->level->id,
                'code' => (string) $assignment->level->code,
                'name' => (string) $assignment->level->name,
            ] : null,
            'portals' => $portalSnapshots,
            'menus' => $menuSnapshots,
        ];
    }

    public function syncUserPermissions(User $user): array
    {
        $user->loadMissing(['roles', 'permissions']);
        $assignment = $this->ensureAccessAssignment($user);
        $access = $this->buildSessionAccess($user);
        $role = $assignment->role;

        $permissionNames = collect(['auth.me']);
        foreach ($access['menus'] as $menu) {
            if (!($menu['can_view'] ?? false)) {
                continue;
            }

            $accessMenu = AccessMenu::query()->find($menu['id']);
            if (!$accessMenu) {
                continue;
            }

            if ($accessMenu->permission_view) {
                $permissionNames->push($accessMenu->permission_view);
            }
            if (($menu['can_create'] ?? false) && $accessMenu->permission_create) {
                $permissionNames->push($accessMenu->permission_create);
            }
            if (($menu['can_edit'] ?? false) && $accessMenu->permission_update) {
                $permissionNames->push($accessMenu->permission_update);
            }
            if (($menu['can_delete'] ?? false) && $accessMenu->permission_delete) {
                $permissionNames->push($accessMenu->permission_delete);
            }
        }

        $permissionNames = $permissionNames
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values();

        $guard = config('auth.defaults.guard', 'web');
        $availablePermissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $permissionNames->all())
            ->pluck('name')
            ->all();

        DB::transaction(function () use ($user, $role, $availablePermissions) {
            if ($role?->spatie_role_name) {
                $user->syncRoles([$role->spatie_role_name]);
            }
            $user->syncPermissions($availablePermissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'assignment' => $assignment->fresh(['role.userType', 'level']),
            'access' => $this->buildSessionAccess($user->fresh(['roles', 'permissions'])),
            'permissions' => $availablePermissions,
        ];
    }

    public function updateUserAssignment(User $actor, User $subject, string $accessRoleId, ?string $accessLevelId): array
    {
        $assignment = $this->ensureAccessAssignment($subject);
        $assignment->fill([
            'access_role_id' => $accessRoleId,
            'access_level_id' => $accessLevelId,
            'assigned_by_user_id' => $actor->id,
        ])->save();

        return $this->syncUserPermissions($subject);
    }

    public function upsertPortalPermissions(string $roleId, ?string $levelId, array $rows): int
    {
        foreach ($rows as $row) {
            AccessRolePortalPermission::query()->updateOrCreate(
                [
                    'access_role_id' => $roleId,
                    'access_level_id' => $levelId,
                    'portal_id' => Arr::get($row, 'portal_id'),
                ],
                [
                    'can_view' => (bool) Arr::get($row, 'can_view', false),
                ]
            );
        }

        return $this->syncUsersForScope($roleId, $levelId);
    }

    public function upsertMenuPermissions(string $roleId, ?string $levelId, array $rows): int
    {
        foreach ($rows as $row) {
            AccessRoleMenuPermission::query()->updateOrCreate(
                [
                    'access_role_id' => $roleId,
                    'access_level_id' => $levelId,
                    'menu_id' => Arr::get($row, 'menu_id'),
                ],
                [
                    'can_view' => (bool) Arr::get($row, 'can_view', false),
                    'can_create' => (bool) Arr::get($row, 'can_create', false),
                    'can_edit' => (bool) Arr::get($row, 'can_edit', false),
                    'can_delete' => (bool) Arr::get($row, 'can_delete', false),
                ]
            );
        }

        return $this->syncUsersForScope($roleId, $levelId);
    }

    public function syncUsersForScope(string $roleId, ?string $levelId): int
    {
        $query = UserAccessAssignment::query()->where('access_role_id', $roleId);
        if ($levelId) {
            $query->where('access_level_id', $levelId);
        } else {
            $query->whereNull('access_level_id');
        }

        $count = 0;
        /** @var Collection<int, UserAccessAssignment> $assignments */
        $assignments = $query->with('user.roles')->get();
        foreach ($assignments as $assignment) {
            if (!$assignment->user) {
                continue;
            }
            $this->syncUserPermissions($assignment->user);
            $count++;
        }

        return $count;
    }

    public function currentSessionSnapshot(User $user): array
    {
        $user = $user->fresh(['roles', 'permissions', 'employee.assignment.outlet', 'outlet']);

        return [
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'access' => $this->buildSessionAccess($user),
        ];
    }

    public function ensureMasters(): void
    {
        AccessUserType::query()->firstOrCreate(['code' => 'BACKOFFICE'], ['name' => 'Backoffice', 'description' => 'Portal backoffice', 'is_active' => true]);
        AccessUserType::query()->firstOrCreate(['code' => 'POS'], ['name' => 'POS', 'description' => 'Login POS', 'is_active' => true]);

        $userTypes = AccessUserType::query()->get()->keyBy('code');
        $roleSeeds = [
            ['code' => 'ADMIN', 'user_type' => 'BACKOFFICE', 'name' => 'Administrator', 'spatie_role_name' => 'admin'],
            ['code' => 'MANAGER', 'user_type' => 'BACKOFFICE', 'name' => 'Manager', 'spatie_role_name' => 'manager'],
            ['code' => 'WAREHOUSE', 'user_type' => 'BACKOFFICE', 'name' => 'Warehouse', 'spatie_role_name' => 'warehouse'],
            ['code' => 'CASHIER', 'user_type' => 'POS', 'name' => 'Cashier', 'spatie_role_name' => 'cashier'],
            ['code' => 'SQUAD_DEFAULT', 'user_type' => 'BACKOFFICE', 'name' => 'Squad Default', 'spatie_role_name' => 'cashier'],
        ];
        foreach ($roleSeeds as $seed) {
            AccessRole::query()->updateOrCreate(
                ['code' => $seed['code']],
                [
                    'user_type_id' => $userTypes[$seed['user_type']]->id ?? null,
                    'name' => $seed['name'],
                    'description' => $seed['name'],
                    'spatie_role_name' => $seed['spatie_role_name'],
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['code' => 'HQ', 'name' => 'Head Office'],
            ['code' => 'OUTLET', 'name' => 'Outlet'],
            ['code' => 'DEFAULT', 'name' => 'Default'],
        ] as $seed) {
            AccessLevel::query()->updateOrCreate(
                ['code' => $seed['code']],
                ['name' => $seed['name'], 'description' => $seed['name'], 'is_active' => true]
            );
        }

        $this->syncCanonicalAccessMenus();
    }

    private function syncCanonicalAccessMenus(): void
    {
        $portalMap = AccessPortal::query()->pluck('id', 'code');
        if ($portalMap->isEmpty()) {
            return;
        }

        $menuSeeds = [
            [
                'portal_code' => 'operational',
                'code' => 'operational-outlet',
                'legacy_codes' => ['sales-outlet', 'outlet'],
                'name' => 'Outlet',
                'path' => '/settings/outlet',
                'sort_order' => 100,
                'permission_view' => 'outlet.view',
                'permission_update' => 'outlet.update',
            ],
        ];

        foreach ($menuSeeds as $seed) {
            $portalId = $portalMap->get($seed['portal_code']);
            if (!$portalId) {
                continue;
            }

            $this->upsertCanonicalMenu($seed, (string) $portalId);
        }
    }

    private function upsertCanonicalMenu(array $seed, string $portalId): void
    {
        $canonicalCode = (string) $seed['code'];
        $codes = array_values(array_unique(array_filter(array_merge([$canonicalCode], $seed['legacy_codes'] ?? []))));

        DB::transaction(function () use ($seed, $portalId, $canonicalCode, $codes) {
            $menus = AccessMenu::query()->whereIn('code', $codes)->orderByRaw("case when code = ? then 0 else 1 end", [$canonicalCode])->get();
            $target = $menus->firstWhere('code', $canonicalCode) ?: $menus->first();

            $payload = [
                'portal_id' => $portalId,
                'code' => $canonicalCode,
                'name' => (string) $seed['name'],
                'path' => (string) $seed['path'],
                'sort_order' => (int) ($seed['sort_order'] ?? 0),
                'permission_view' => $seed['permission_view'] ?? null,
                'permission_create' => $seed['permission_create'] ?? null,
                'permission_update' => $seed['permission_update'] ?? null,
                'permission_delete' => $seed['permission_delete'] ?? null,
                'is_active' => true,
            ];

            if (!$target) {
                AccessMenu::query()->create($payload + ['id' => (string) Str::ulid()]);
                return;
            }

            $target->fill($payload);
            $target->save();

            foreach ($menus as $menu) {
                if ((string) $menu->id === (string) $target->id) {
                    continue;
                }
                $this->repointMenuReferences((string) $menu->id, (string) $target->id);
                $menu->delete();
            }
        });
    }

    private function repointMenuReferences(string $fromMenuId, string $toMenuId): void
    {
        foreach (['access_role_menu_permissions'] as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table) || !DB::getSchemaBuilder()->hasColumn($table, 'menu_id')) {
                continue;
            }
            DB::table($table)
                ->where('menu_id', $fromMenuId)
                ->update(['menu_id' => $toMenuId]);
        }
    }
}
