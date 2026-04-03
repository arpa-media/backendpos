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
    public function __construct(private readonly ReportPortalAccessService $reportPortalAccess)
    {
    }

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
        foreach (['admin' => 'ADMIN', 'manager' => 'MANAGER', 'warehouse' => 'WAREHOUSE', 'cashier' => 'CASHIER', 'stakeholder' => 'STAKEHOLDER', 'observer' => 'OBSERVER'] as $spatie => $code) {
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

        $menuPermissionMap = $menus->mapWithKeys(function (AccessMenu $menu) use ($baseMenuRows, $exactMenuRows) {
            $effective = $exactMenuRows->get($menu->id) ?: $baseMenuRows->get($menu->id);

            return [
                (string) $menu->id => [
                    'can_view' => (bool) ($effective->can_view ?? false),
                    'can_create' => (bool) ($effective->can_create ?? false),
                    'can_edit' => (bool) ($effective->can_edit ?? false),
                    'can_delete' => (bool) ($effective->can_delete ?? false),
                ],
            ];
        });

        $portalSnapshots = $portals->map(function (AccessPortal $portal) use ($basePortalRows, $exactPortalRows, $menus, $menuPermissionMap) {
            $effective = $exactPortalRows->get($portal->id) ?: $basePortalRows->get($portal->id);
            $portalCode = strtolower((string) $portal->code);
            $hasVisibleChildMenu = $this->portalAllowsImplicitVisibility($portalCode)
                && $menus->where('portal_id', $portal->id)->contains(function (AccessMenu $menu) use ($menuPermissionMap) {
                    return (bool) ($menuPermissionMap->get((string) $menu->id)['can_view'] ?? false);
                });

            return [
                'id' => (string) $portal->id,
                'code' => (string) $portal->code,
                'name' => (string) $portal->name,
                'can_view' => (bool) ($effective->can_view ?? false) || $hasVisibleChildMenu,
            ];
        })->values()->all();

        $visiblePortalCodes = collect($portalSnapshots)
            ->filter(fn ($row) => !empty($row['can_view']))
            ->map(fn ($row) => strtolower((string) $row['code']))
            ->values()
            ->all();

        $menuSnapshots = $menus->map(function (AccessMenu $menu) use ($menuPermissionMap, $visiblePortalCodes) {
            $effective = $menuPermissionMap->get((string) $menu->id, [
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ]);
            $portalCode = strtolower((string) ($menu->portal?->code ?? ''));
            $portalVisible = in_array($portalCode, $visiblePortalCodes, true);
            $standaloneHiddenAccess = $this->menuAllowsHiddenPortalAccess($menu);

            return [
                'id' => (string) $menu->id,
                'code' => (string) $menu->code,
                'name' => (string) $menu->name,
                'path' => (string) $menu->path,
                'portal_id' => $menu->portal_id ? (string) $menu->portal_id : null,
                'portal_code' => $portalCode,
                'portal_name' => (string) ($menu->portal?->name ?? ''),
                'can_view' => ($portalVisible || $standaloneHiddenAccess) && (bool) ($effective['can_view'] ?? false),
                'can_create' => $portalVisible && (bool) ($effective['can_create'] ?? false),
                'can_edit' => $portalVisible && (bool) ($effective['can_edit'] ?? false),
                'can_delete' => $portalVisible && (bool) ($effective['can_delete'] ?? false),
            ];
        })->values()->all();

        $menuSnapshots = $this->augmentOwnerOverviewDetailMenu($menuSnapshots, $assignment);
        $menuSnapshots = $this->prioritizePosDashboardMenu($menuSnapshots, $assignment);

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

    protected function prioritizePosDashboardMenu(array $menuSnapshots, UserAccessAssignment $assignment): array
    {
        $roleCode = strtoupper((string) ($assignment->role?->code ?? ''));
        $userTypeCode = strtoupper((string) ($assignment->role?->userType?->code ?? ''));

        if ($roleCode !== 'CASHIER' && $userTypeCode !== 'POS') {
            return $menuSnapshots;
        }

        $dashboardIndex = null;
        foreach ($menuSnapshots as $index => $menu) {
            if ((string) ($menu['path'] ?? '') === '/c/dashboard') {
                $dashboardIndex = $index;
                break;
            }
        }

        if ($dashboardIndex === null || $dashboardIndex === 0) {
            return $menuSnapshots;
        }

        $dashboardMenu = $menuSnapshots[$dashboardIndex];
        unset($menuSnapshots[$dashboardIndex]);

        return array_values(array_merge([$dashboardMenu], $menuSnapshots));
    }

    private function portalAllowsImplicitVisibility(string $portalCode): bool
    {
        return in_array(strtolower($portalCode), ['finance', 'pos'], true);
    }

    private function menuAllowsHiddenPortalAccess(AccessMenu $menu): bool
    {
        $code = strtolower((string) $menu->code);
        $path = (string) $menu->path;

        return in_array($code, ['owner-overview-detail-sales'], true)
            || $path === '/owner-overview/detail-sales';
    }

    private function augmentOwnerOverviewDetailMenu(array $menuSnapshots, UserAccessAssignment $assignment): array
    {
        if (! $this->shouldImplicitOwnerOverviewDetailAccess($assignment, $menuSnapshots)) {
            return $menuSnapshots;
        }

        foreach ($menuSnapshots as $index => $menu) {
            if ((string) ($menu['path'] ?? '') !== '/owner-overview/detail-sales') {
                continue;
            }

            $menuSnapshots[$index]['can_view'] = true;
            $menuSnapshots[$index]['can_create'] = false;
            $menuSnapshots[$index]['can_edit'] = false;
            $menuSnapshots[$index]['can_delete'] = false;
            $menuSnapshots[$index]['permission_view'] = (string) ($menuSnapshots[$index]['permission_view'] ?? 'owner_overview.sale_detail.view');

            return $menuSnapshots;
        }

        $menuSnapshots[] = [
            'id' => 'synthetic-owner-overview-detail-sales',
            'code' => 'owner-overview-detail-sales',
            'name' => 'Detail Sales',
            'path' => '/owner-overview/detail-sales',
            'portal_id' => null,
            'portal_code' => 'owner-overview',
            'portal_name' => 'Owner Overview',
            'can_view' => true,
            'can_create' => false,
            'can_edit' => false,
            'can_delete' => false,
            'permission_view' => 'owner_overview.sale_detail.view',
        ];

        return $menuSnapshots;
    }

    private function shouldImplicitOwnerOverviewDetailAccess(UserAccessAssignment $assignment, array $menuSnapshots): bool
    {
        if (! $this->isAdministratorAssignment($assignment)) {
            return false;
        }

        foreach ($menuSnapshots as $menu) {
            if (strtolower((string) ($menu['portal_code'] ?? '')) !== 'omzet-report') {
                continue;
            }

            if (! empty($menu['can_view'])) {
                return true;
            }
        }

        return false;
    }

    private function isAdministratorAssignment(UserAccessAssignment $assignment): bool
    {
        return strtoupper((string) ($assignment->role?->code ?? '')) === 'ADMIN';
    }

    public function syncUserPermissions(User $user): array
    {
        $user->loadMissing(['roles', 'permissions']);
        $assignment = $this->ensureAccessAssignment($user);
        $access = $this->buildSessionAccess($user);
        $role = $assignment->role;

        $permissionNames = collect(['auth.me']);
        foreach ($access['menus'] as $menu) {
            $accessMenu = AccessMenu::query()->find($menu['id']);
            if (!$accessMenu) {
                continue;
            }

            if (($menu['can_view'] ?? false) && $accessMenu->permission_view) {
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

        if ($this->shouldImplicitOwnerOverviewDetailAccess($assignment, $access['menus'] ?? [])) {
            $permissionNames->push('owner_overview.sale_detail.view');
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
                    'portal_id' => $row['portal_id'],
                ],
                [
                    'can_view' => (bool) ($row['can_view'] ?? false),
                ]
            );
        }

        return count($rows);
    }

    public function upsertMenuPermissions(string $roleId, ?string $levelId, array $rows): int
    {
        foreach ($rows as $row) {
            AccessRoleMenuPermission::query()->updateOrCreate(
                [
                    'access_role_id' => $roleId,
                    'access_level_id' => $levelId,
                    'menu_id' => $row['menu_id'],
                ],
                [
                    'can_view' => (bool) ($row['can_view'] ?? false),
                    'can_create' => (bool) ($row['can_create'] ?? false),
                    'can_edit' => (bool) ($row['can_edit'] ?? false),
                    'can_delete' => (bool) ($row['can_delete'] ?? false),
                ]
            );
        }

        return count($rows);
    }

    public function listUsers(): Collection
    {
        return User::query()->with(['accessAssignment.role.userType', 'accessAssignment.level', 'roles', 'outlet'])->orderBy('name')->get();
    }

    public function currentSessionSnapshot(User $user): array
    {
        return $this->getSessionSnapshot($user);
    }

    public function getSessionSnapshot(User $user): array
    {
        $access = $this->buildSessionAccess($user);
        $permissions = $user->getAllPermissions()->pluck('name')->values()->all();
        $visiblePortals = collect($access['portals'] ?? [])
            ->filter(fn ($portal) => !empty($portal['can_view']))
            ->map(fn ($portal) => Arr::only($portal, ['id', 'code', 'name']))
            ->values()
            ->all();

        return [
            'permissions' => $permissions,
            'access' => $access,
            'visible_backoffice_portals' => $visiblePortals,
            'can_edit_user_management' => in_array('user_management.edit', $permissions, true),
            'report_access' => $this->reportPortalAccess->snapshot($user),
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
            ['code' => 'STAKEHOLDER', 'user_type' => 'BACKOFFICE', 'name' => 'Stakeholder', 'spatie_role_name' => 'stakeholder'],
            ['code' => 'OBSERVER', 'user_type' => 'BACKOFFICE', 'name' => 'Observer', 'spatie_role_name' => 'observer'],
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

        foreach ([
            ['code' => 'omzet-report', 'name' => 'Omzet Report', 'description' => 'Portal report omzet seluruh transaksi POS.', 'sort_order' => 16],
            ['code' => 'sales-report', 'name' => 'Sales Report', 'description' => 'Portal report transaksi dengan marking 1.', 'sort_order' => 17],
        ] as $portalSeed) {
            AccessPortal::query()->updateOrCreate(
                ['code' => $portalSeed['code']],
                [
                    'name' => $portalSeed['name'],
                    'description' => $portalSeed['description'],
                    'sort_order' => $portalSeed['sort_order'],
                    'is_active' => true,
                ]
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
            [
                'portal_code' => 'pos',
                'code' => 'pos-offline-transactions',
                'legacy_codes' => ['sales-offline-sync', 'offline-transactions'],
                'name' => 'Unsync Transactions',
                'path' => '/c/offline-transactions',
                'sort_order' => 35,
                'permission_view' => 'pos.offline_sync.view',
            ],
            [
                'portal_code' => 'omzet-report',
                'code' => 'omzet-report-dashboard',
                'name' => 'Dashboard',
                'path' => '/omzet-report/dashboard',
                'sort_order' => 10,
                'permission_view' => 'dashboard.view',
            ],
            [
                'portal_code' => 'omzet-report',
                'code' => 'omzet-report-ledger',
                'name' => 'Ledger',
                'path' => '/omzet-report/ledger',
                'sort_order' => 20,
                'permission_view' => 'report.view',
            ],
            [
                'portal_code' => 'omzet-report',
                'code' => 'omzet-report-report',
                'name' => 'Report',
                'path' => '/omzet-report/report',
                'sort_order' => 30,
                'permission_view' => 'report.view',
            ],
            [
                'portal_code' => 'sales-report',
                'code' => 'sales-report-dashboard',
                'name' => 'Dashboard',
                'path' => '/sales-report/dashboard',
                'sort_order' => 10,
                'permission_view' => 'dashboard.view',
            ],
            [
                'portal_code' => 'sales-report',
                'code' => 'sales-report-sales',
                'name' => 'Sales',
                'path' => '/sales-report/sales',
                'sort_order' => 20,
                'permission_view' => 'sale.view',
            ],
            [
                'portal_code' => 'sales-report',
                'code' => 'sales-report-report',
                'name' => 'Report',
                'path' => '/sales-report/report',
                'sort_order' => 30,
                'permission_view' => 'report.view',
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
        });
    }
}
