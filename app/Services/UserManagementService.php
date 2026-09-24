<?php

namespace App\Services;

use App\Models\AccessLevel;
use App\Models\AccessMenu;
use App\Models\AccessPortal;
use App\Models\AccessRole;
use App\Models\AccessRoleMenuPermission;
use App\Models\AccessRolePortalPermission;
use App\Models\AccessUserType;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserReportOutletAssignment;
use App\Models\UserAccessAssignment;
use App\Support\UserManagementCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class UserManagementService
{
    public function __construct(
        private readonly ReportPortalAccessService $reportPortalAccess,
        private readonly HrSquadUserWiringService $hrSquadWiring,
    ) {
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
            $isPortalLandingMenu = $this->isPortalLandingMenu($menu, $portalCode);
            $canView = (($portalVisible || $standaloneHiddenAccess) && (bool) ($effective['can_view'] ?? false))
                || ($portalVisible && $isPortalLandingMenu);

            return [
                'id' => (string) $menu->id,
                'code' => (string) $menu->code,
                'name' => (string) $menu->name,
                'path' => (string) $menu->path,
                'portal_id' => $menu->portal_id ? (string) $menu->portal_id : null,
                'portal_code' => $portalCode,
                'portal_name' => (string) ($menu->portal?->name ?? ''),
                'can_view' => $canView,
                'can_create' => $portalVisible && (bool) ($effective['can_create'] ?? false),
                'can_edit' => $portalVisible && (bool) ($effective['can_edit'] ?? false),
                'can_delete' => $portalVisible && (bool) ($effective['can_delete'] ?? false),
                // STOCK-HPP-ITERASI-01: keep permission metadata inside the
                // session snapshot so PermissionOrSnapshot and the frontend
                // can honor Access Matrix changes immediately, even before a
                // user's direct Spatie permissions are re-synchronized.
                'permission_view' => $menu->permission_view ? (string) $menu->permission_view : null,
                'permission_create' => $menu->permission_create ? (string) $menu->permission_create : null,
                'permission_update' => $menu->permission_update ? (string) $menu->permission_update : null,
                'permission_delete' => $menu->permission_delete ? (string) $menu->permission_delete : null,
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
        return in_array(strtolower($portalCode), ['finance', 'pos', 'attendance'], true);
    }

    private function isPortalLandingMenu(AccessMenu $menu, string $portalCode): bool
    {
        $path = '/' . ltrim((string) $menu->path, '/');
        $code = strtolower((string) $menu->code);
        $portalCode = strtolower($portalCode);

        if ($portalCode === '') {
            return false;
        }

        return $path === "/portal/{$portalCode}/dashboard"
            || $code === "{$portalCode}-dashboard";
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

    public function syncUserPermissions(User $user, bool $forgetPermissionCache = true): array
    {
        $assignment = $this->ensureAccessAssignment($user);
        $access = $this->buildSessionAccess($user);
        $role = $assignment->role;

        // HOTFIX I11.01: the access snapshot already carries permission metadata.
        // Do not execute AccessMenu::find() once per visible menu (N+1) and do not
        // rebuild the same Access Matrix a second time after pivot synchronization.
        $permissionNames = $this->permissionNamesFromAccessSnapshot($access, $assignment);
        $guard = config('auth.defaults.guard', 'web');
        $availablePermissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $permissionNames->all())
            ->pluck('name')
            ->all();

        DB::transaction(function () use ($user, $role, $availablePermissions) {
            // ERP POS FINAL I07-HF02: role synchronization is replacement based.
            // A target Access Role without Spatie mapping must also clear a
            // legacy role left by the previous assignment.
            $user->syncRoles($role?->spatie_role_name ? [$role->spatie_role_name] : []);
            $user->syncPermissions($availablePermissions);
        });

        if ($forgetPermissionCache) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return [
            'assignment' => $assignment->fresh(['role.userType', 'level']),
            'access' => $access,
            'permissions' => $availablePermissions,
        ];
    }

    private function permissionNamesFromAccessSnapshot(array $access, ?UserAccessAssignment $assignment = null): \Illuminate\Support\Collection
    {
        $permissionNames = collect(['auth.me']);

        foreach (($access['menus'] ?? []) as $menu) {
            if (($menu['can_view'] ?? false) && ! empty($menu['permission_view'])) {
                $permissionNames->push((string) $menu['permission_view']);
            }
            if (($menu['can_create'] ?? false) && ! empty($menu['permission_create'])) {
                $permissionNames->push((string) $menu['permission_create']);
            }
            if (($menu['can_edit'] ?? false) && ! empty($menu['permission_update'])) {
                $permissionNames->push((string) $menu['permission_update']);
            }
            if (($menu['can_delete'] ?? false) && ! empty($menu['permission_delete'])) {
                $permissionNames->push((string) $menu['permission_delete']);
            }
        }

        if ($assignment && $this->shouldImplicitOwnerOverviewDetailAccess($assignment, $access['menus'] ?? [])) {
            $permissionNames->push('owner_overview.sale_detail.view');
        }

        return $permissionNames
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values();
    }

    public function updateUserAssignment(User $actor, User $subject, string $accessRoleId, ?string $accessLevelId): array
    {
        $this->assertActorCanAssignAccess($actor, $accessRoleId, $accessLevelId);

        $assignment = $this->ensureAccessAssignment($subject);
        $assignment->fill([
            'access_role_id' => $accessRoleId,
            'access_level_id' => $accessLevelId,
            'assigned_by_user_id' => $actor->id,
        ])->save();

        $sync = $this->syncUserPermissions($subject);
        $freshSubject = $subject->fresh([
            'employee.assignment.outlet',
            'outlet',
            'accessAssignment.role',
            'accessAssignment.level',
        ]) ?: $subject;
        $sync['hr_squad'] = $this->hrSquadWiring->ensureForUser($freshSubject, true);

        return $sync;
    }

    /**
     * ERP Finance V7 I11: atomic bulk role/level assignment.
     *
     * The access role and access level are authoritative Access Matrix dimensions.
     * A batch either commits completely or is rolled back. Each subject receives an
     * immutable before/after audit row using one shared batch id.
     */
    public function bulkUpdateUserAssignments(
        User $actor,
        array $userIds,
        ?string $accessRoleId,
        string $accessLevelMode,
        ?string $accessLevelId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $ids = collect($userIds)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['user_ids' => ['Pilih minimal satu user.']]);
        }
        if ($ids->count() > 100) {
            throw ValidationException::withMessages(['user_ids' => ['Bulk access maksimal 100 user per proses.']]);
        }

        $mode = strtoupper(trim($accessLevelMode));
        if (! in_array($mode, ['KEEP', 'CLEAR', 'SET'], true)) {
            throw ValidationException::withMessages(['access_level_mode' => ['Mode Access Level tidak valid.']]);
        }
        if ($accessRoleId === null && $mode === 'KEEP') {
            throw ValidationException::withMessages(['bulk' => ['Pilih Access Role atau Access Level yang akan diubah.']]);
        }
        if ($mode === 'SET' && ! $accessLevelId) {
            throw ValidationException::withMessages(['access_level_id' => ['Access Level wajib dipilih saat mode SET.']]);
        }
        if ($ids->contains((string) $actor->id)) {
            throw ValidationException::withMessages(['user_ids' => ['Akses akun yang sedang login tidak boleh diubah melalui bulk edit. Gunakan single edit untuk mencegah lockout tidak sengaja.']]);
        }

        $batchId = (string) Str::ulid();

        $result = DB::transaction(function () use ($actor, $ids, $accessRoleId, $mode, $accessLevelId, $batchId, $ipAddress, $userAgent) {
            $subjects = User::query()
                ->with(['accessAssignment.role.userType', 'accessAssignment.level'])
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user) => (string) $user->id);

            if ($subjects->count() !== $ids->count()) {
                $missing = $ids->reject(fn ($id) => $subjects->has((string) $id))->values()->all();
                throw ValidationException::withMessages(['user_ids' => ['Ada user yang tidak ditemukan: '.implode(', ', $missing)]]);
            }

            $plans = [];
            $targetRoleIds = collect();
            $targetLevelIds = collect();

            foreach ($ids as $id) {
                /** @var User $subject */
                $subject = $subjects->get((string) $id);
                $assignment = $subject->accessAssignment ?: $this->ensureAccessAssignment($subject);
                $beforeRoleId = $assignment->access_role_id ? (string) $assignment->access_role_id : null;
                $beforeLevelId = $assignment->access_level_id ? (string) $assignment->access_level_id : null;
                $targetRoleId = $accessRoleId ?: $beforeRoleId;
                $targetLevelId = match ($mode) {
                    'CLEAR' => null,
                    'SET' => $accessLevelId,
                    default => $beforeLevelId,
                };

                if (! $targetRoleId) {
                    throw ValidationException::withMessages(['access_role_id' => ['User '.$subject->name.' tidak memiliki Access Role yang valid.']]);
                }

                $plans[(string) $id] = [
                    'subject' => $subject,
                    'assignment' => $assignment,
                    'before_role_id' => $beforeRoleId,
                    'before_level_id' => $beforeLevelId,
                    'target_role_id' => (string) $targetRoleId,
                    'target_level_id' => $targetLevelId ? (string) $targetLevelId : null,
                ];
                $targetRoleIds->push((string) $targetRoleId);
                if ($targetLevelId) {
                    $targetLevelIds->push((string) $targetLevelId);
                }
            }

            $roles = AccessRole::query()->with('userType')->whereIn('id', $targetRoleIds->unique()->all())->get()->keyBy(fn ($role) => (string) $role->id);
            $levels = $targetLevelIds->isNotEmpty()
                ? AccessLevel::query()->whereIn('id', $targetLevelIds->unique()->all())->get()->keyBy(fn ($level) => (string) $level->id)
                : collect();

            $comboKeys = collect($plans)->map(fn ($plan) => $plan['target_role_id'].'|'.($plan['target_level_id'] ?: '__NULL__'))->unique()->values();
            $permissionCounts = [];
            foreach ($comboKeys as $comboKey) {
                [$roleId, $levelKey] = explode('|', $comboKey, 2);
                $levelId = $levelKey === '__NULL__' ? null : $levelKey;
                $this->assertActorCanAssignAccess($actor, $roleId, $levelId);
                $permissionCounts[$comboKey] = $this->effectivePermissionNamesForAccess($roleId, $levelId)->count();
            }

            $syncGroups = [];
            $auditRows = [];
            $changed = [];
            $now = now();
            $hasAuditTable = Schema::hasTable('user_access_assignment_audits');

            foreach ($ids as $id) {
                $plan = $plans[(string) $id];
                /** @var User $subject */
                $subject = $plan['subject'];
                /** @var UserAccessAssignment $assignment */
                $assignment = $plan['assignment'];
                $targetRole = $roles->get($plan['target_role_id']);
                $targetLevel = $plan['target_level_id'] ? $levels->get($plan['target_level_id']) : null;

                if (! $targetRole) {
                    throw ValidationException::withMessages(['access_role_id' => ['Access Role target tidak ditemukan.']]);
                }
                if ($plan['target_level_id'] && ! $targetLevel) {
                    throw ValidationException::withMessages(['access_level_id' => ['Access Level target tidak ditemukan.']]);
                }

                $roleCode = strtoupper(trim((string) $targetRole->code));
                if (! in_array($roleCode, ['STAKEHOLDER', 'OBSERVER'], true) && trim((string) ($subject->nisj ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'user_ids' => ['User '.$subject->name.' belum memiliki NISJ dan tidak dapat dipindah ke role operasional.'],
                    ]);
                }

                $beforeSnapshot = [
                    'role_id' => $plan['before_role_id'],
                    'role_code' => (string) ($assignment->role?->code ?? ''),
                    'role_name' => (string) ($assignment->role?->name ?? ''),
                    'level_id' => $plan['before_level_id'],
                    'level_code' => (string) ($assignment->level?->code ?? ''),
                    'level_name' => (string) ($assignment->level?->name ?? ''),
                ];

                $assignment->forceFill([
                    'access_role_id' => $plan['target_role_id'],
                    'access_level_id' => $plan['target_level_id'],
                    'assigned_by_user_id' => $actor->id,
                ])->save();

                $comboKey = $plan['target_role_id'].'|'.($plan['target_level_id'] ?: '__NULL__');
                $syncGroups[$comboKey] ??= [
                    'role_id' => $plan['target_role_id'],
                    'level_id' => $plan['target_level_id'],
                    'user_ids' => [],
                ];
                $syncGroups[$comboKey]['user_ids'][] = (string) $subject->id;

                $afterSnapshot = [
                    'role_id' => $plan['target_role_id'],
                    'role_code' => (string) $targetRole->code,
                    'role_name' => (string) $targetRole->name,
                    'level_id' => $plan['target_level_id'],
                    'level_code' => (string) ($targetLevel?->code ?? ''),
                    'level_name' => (string) ($targetLevel?->name ?? ''),
                    'permission_count' => (int) ($permissionCounts[$comboKey] ?? 0),
                ];

                if ($hasAuditTable) {
                    $auditRows[] = [
                        'id' => (string) Str::ulid(),
                        'batch_id' => $batchId,
                        'actor_user_id' => $actor->id,
                        'subject_user_id' => $subject->id,
                        'event' => 'BULK_ACCESS_UPDATE',
                        'before_access_role_id' => $plan['before_role_id'],
                        'before_access_level_id' => $plan['before_level_id'],
                        'after_access_role_id' => $plan['target_role_id'],
                        'after_access_level_id' => $plan['target_level_id'],
                        'before_snapshot' => json_encode($beforeSnapshot, JSON_UNESCAPED_SLASHES),
                        'after_snapshot' => json_encode($afterSnapshot, JSON_UNESCAPED_SLASHES),
                        'ip_address' => $ipAddress ? mb_substr($ipAddress, 0, 64) : null,
                        'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $changed[] = [
                    'id' => (string) $subject->id,
                    'name' => (string) $subject->name,
                    'access_role_id' => $plan['target_role_id'],
                    'access_level_id' => $plan['target_level_id'],
                    'access_role' => [
                        'id' => (string) $targetRole->id,
                        'code' => (string) $targetRole->code,
                        'name' => (string) $targetRole->name,
                    ],
                    'user_type' => $targetRole->userType ? [
                        'id' => (string) $targetRole->userType->id,
                        'code' => (string) $targetRole->userType->code,
                        'name' => (string) $targetRole->userType->name,
                    ] : null,
                    'access_level' => $targetLevel ? [
                        'id' => (string) $targetLevel->id,
                        'code' => (string) $targetLevel->code,
                        'name' => (string) $targetLevel->name,
                    ] : null,
                ];
            }

            if (! empty($auditRows)) {
                DB::table('user_access_assignment_audits')->insert($auditRows);
            }

            // Synchronize permissions once per unique target combination, not once per user.
            foreach ($syncGroups as $group) {
                $this->syncUserIdsForAccessPlan($group['user_ids'], $group['role_id'], $group['level_id'], false);
            }

            return [
                'batch_id' => $batchId,
                'updated_count' => count($changed),
                'users' => $changed,
            ];
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $freshActor = User::query()->with(['roles', 'permissions'])->find($actor->id) ?: $actor;
        $result['current_actor_session'] = $this->currentSessionSnapshot($freshActor);

        return $result;
    }

    /**
     * Prevent privilege escalation by requiring the actor to already possess every
     * concrete Spatie permission granted by the target Access Matrix combination.
     */
    public function assertActorCanAssignAccess(User $actor, string $accessRoleId, ?string $accessLevelId): void
    {
        $actorAssignment = $this->ensureAccessAssignment($actor);
        $actorRoleCode = strtoupper(trim((string) ($actorAssignment->role?->code ?? '')));
        $targetRole = AccessRole::query()->find($accessRoleId);

        if (! $targetRole) {
            throw ValidationException::withMessages(['access_role_id' => ['Access Role tidak ditemukan.']]);
        }

        $targetRoleCode = strtoupper(trim((string) $targetRole->code));
        if ($targetRoleCode === 'ADMIN' && $actorRoleCode !== 'ADMIN') {
            throw ValidationException::withMessages(['access_role_id' => ['Hanya Administrator yang dapat memberikan Access Role Administrator.']]);
        }

        if ($actorRoleCode === 'ADMIN') {
            return;
        }

        $actor->loadMissing(['permissions', 'roles']);
        $actorPermissionNames = $actor->getAllPermissions()->pluck('name')->map(fn ($name) => (string) $name)->flip();
        $targetPermissionNames = $this->effectivePermissionNamesForAccess($accessRoleId, $accessLevelId);
        $missing = $targetPermissionNames->reject(fn ($name) => $actorPermissionNames->has((string) $name))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'access_role_id' => ['Target Access Role/Level memiliki hak di luar akses actor: '.$missing->take(8)->implode(', ').($missing->count() > 8 ? ', ...' : '')],
            ]);
        }
    }

    private function effectivePermissionNamesForAccess(string $roleId, ?string $levelId): \Illuminate\Support\Collection
    {
        $menus = AccessMenu::query()->where('is_active', true)->get();
        $baseRows = AccessRoleMenuPermission::query()->where('access_role_id', $roleId)->whereNull('access_level_id')->get()->keyBy('menu_id');
        $exactRows = $levelId
            ? AccessRoleMenuPermission::query()->where('access_role_id', $roleId)->where('access_level_id', $levelId)->get()->keyBy('menu_id')
            : collect();

        $names = collect();
        foreach ($menus as $menu) {
            $row = $exactRows->get($menu->id) ?: $baseRows->get($menu->id);
            if (! $row) continue;
            if ($row->can_view && $menu->permission_view) $names->push((string) $menu->permission_view);
            if ($row->can_create && $menu->permission_create) $names->push((string) $menu->permission_create);
            if ($row->can_edit && $menu->permission_update) $names->push((string) $menu->permission_update);
            if ($row->can_delete && $menu->permission_delete) $names->push((string) $menu->permission_delete);
        }

        $accessRole = AccessRole::query()->find($roleId);
        if ($accessRole?->spatie_role_name) {
            $guard = config('auth.defaults.guard', 'web');
            $spatieRole = SpatieRole::query()
                ->where('name', $accessRole->spatie_role_name)
                ->where('guard_name', $guard)
                ->first();
            if ($spatieRole) {
                $spatieRole->permissions->pluck('name')->each(fn ($name) => $names->push((string) $name));
            }
        }

        return $names->filter()->unique()->values();
    }

    public function upsertPortalPermissions(string $roleId, ?string $levelId, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $portalIds = collect($rows)->pluck('portal_id')->filter()->unique()->values();
        $existing = AccessRolePortalPermission::query()
            ->where('access_role_id', $roleId)
            ->when($levelId === null || $levelId === '', fn ($query) => $query->whereNull('access_level_id'), fn ($query) => $query->where('access_level_id', $levelId))
            ->whereIn('portal_id', $portalIds->all())
            ->get()
            ->keyBy('portal_id');
        $now = now();

        $payload = collect($rows)->map(function (array $row) use ($roleId, $levelId, $existing, $now) {
            $current = $existing->get($row['portal_id']);
            return [
                'id' => $current?->id ?: (string) Str::ulid(),
                'access_role_id' => $roleId,
                'access_level_id' => $levelId ?: null,
                'portal_id' => $row['portal_id'],
                'can_view' => (bool) ($row['can_view'] ?? false),
                'created_at' => $current?->created_at ?: $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        DB::table('access_role_portal_permissions')->upsert(
            $payload,
            ['id'],
            ['can_view', 'updated_at']
        );

        return $this->syncUsersForAccessScope($roleId, $levelId);
    }

    public function upsertMenuPermissions(string $roleId, ?string $levelId, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $menuIds = collect($rows)->pluck('menu_id')->filter()->unique()->values();
        $existing = AccessRoleMenuPermission::query()
            ->where('access_role_id', $roleId)
            ->when($levelId === null || $levelId === '', fn ($query) => $query->whereNull('access_level_id'), fn ($query) => $query->where('access_level_id', $levelId))
            ->whereIn('menu_id', $menuIds->all())
            ->get()
            ->keyBy('menu_id');
        $now = now();

        $payload = collect($rows)->map(function (array $row) use ($roleId, $levelId, $existing, $now) {
            $current = $existing->get($row['menu_id']);
            return [
                'id' => $current?->id ?: (string) Str::ulid(),
                'access_role_id' => $roleId,
                'access_level_id' => $levelId ?: null,
                'menu_id' => $row['menu_id'],
                'can_view' => (bool) ($row['can_view'] ?? false),
                'can_create' => (bool) ($row['can_create'] ?? false),
                'can_edit' => (bool) ($row['can_edit'] ?? false),
                'can_delete' => (bool) ($row['can_delete'] ?? false),
                'created_at' => $current?->created_at ?: $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        DB::table('access_role_menu_permissions')->upsert(
            $payload,
            ['id'],
            ['can_view', 'can_create', 'can_edit', 'can_delete', 'updated_at']
        );

        return $this->syncUsersForAccessScope($roleId, $levelId);
    }

    /**
     * HOTFIX I11.01: synchronize Spatie pivots set-based per Access Matrix scope.
     * A base-role edit can affect level users through inheritance, therefore base
     * edits are synchronized per actual level group instead of only NULL-level users.
     */
    public function syncUsersForAccessScope(string $roleId, ?string $levelId): int
    {
        $assignments = UserAccessAssignment::query()
            ->where('access_role_id', $roleId)
            ->when($levelId !== null && $levelId !== '', fn ($query) => $query->where('access_level_id', $levelId))
            ->get(['user_id', 'access_level_id']);

        if ($assignments->isEmpty()) {
            return 0;
        }

        $groups = $assignments->groupBy(fn ($assignment) => $assignment->access_level_id ? (string) $assignment->access_level_id : '__NULL__');
        foreach ($groups as $levelKey => $group) {
            $actualLevelId = $levelKey === '__NULL__' ? null : $levelKey;
            $this->syncUserIdsForAccessPlan(
                $group->pluck('user_id')->map(fn ($id) => (string) $id)->values()->all(),
                $roleId,
                $actualLevelId,
                false,
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $assignments->count();
    }

    private function syncUserIdsForAccessPlan(array $userIds, string $roleId, ?string $levelId, bool $forgetPermissionCache = true): int
    {
        $ids = collect($userIds)->map(fn ($id) => (string) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        // Build the effective Access Matrix once for this role+level combination.
        $probeUserId = $ids->first();
        $probeUser = User::query()->find($probeUserId);
        if (! $probeUser) {
            return 0;
        }
        $assignment = $this->ensureAccessAssignment($probeUser);
        $access = $this->buildSessionAccess($probeUser);
        $permissionNames = $this->permissionNamesFromAccessSnapshot($access, $assignment);
        $guard = config('auth.defaults.guard', 'web');
        $permissionIds = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $permissionNames->all())
            ->pluck('id')
            ->all();

        $accessRole = AccessRole::query()->find($roleId);
        $spatieRoleId = null;
        if ($accessRole?->spatie_role_name) {
            $spatieRoleId = SpatieRole::query()
                ->where('guard_name', $guard)
                ->where('name', $accessRole->spatie_role_name)
                ->value('id');
            if ($spatieRoleId === null) {
                throw ValidationException::withMessages([
                    'access_role_id' => ['Spatie role '.$accessRole->spatie_role_name.' belum tersedia untuk Access Role ini.'],
                ]);
            }
        }

        if ((bool) config('permission.teams', false)) {
            // Preserve compatibility with installations that enable Spatie Teams.
            User::query()->whereIn('id', $ids->all())->chunkById(100, function ($users) use ($accessRole, $permissionNames) {
                foreach ($users as $user) {
                    $user->syncRoles($accessRole?->spatie_role_name ? [$accessRole->spatie_role_name] : []);
                    $user->syncPermissions($permissionNames->all());
                }
            });
        } else {
            $modelType = (new User())->getMorphClass();
            $permissionPivot = config('permission.table_names.model_has_permissions', 'model_has_permissions');
            $rolePivot = config('permission.table_names.model_has_roles', 'model_has_roles');

            DB::transaction(function () use ($ids, $modelType, $permissionPivot, $rolePivot, $permissionIds, $spatieRoleId) {
                foreach ($ids->chunk(250) as $chunk) {
                    $chunkIds = $chunk->values()->all();

                    DB::table($permissionPivot)
                        ->where('model_type', $modelType)
                        ->whereIn('model_id', $chunkIds)
                        ->delete();

                    if (! empty($permissionIds)) {
                        $permissionRows = [];
                        foreach ($chunkIds as $userId) {
                            foreach ($permissionIds as $permissionId) {
                                $permissionRows[] = [
                                    'permission_id' => $permissionId,
                                    'model_type' => $modelType,
                                    'model_id' => $userId,
                                ];
                            }
                        }
                        foreach (array_chunk($permissionRows, 5000) as $insertRows) {
                            DB::table($permissionPivot)->insertOrIgnore($insertRows);
                        }
                    }

                    DB::table($rolePivot)
                        ->where('model_type', $modelType)
                        ->whereIn('model_id', $chunkIds)
                        ->delete();

                    if ($spatieRoleId !== null) {
                        DB::table($rolePivot)->insertOrIgnore(array_map(fn ($userId) => [
                            'role_id' => $spatieRoleId,
                            'model_type' => $modelType,
                            'model_id' => $userId,
                        ], $chunkIds));
                    }
                }
            });
        }

        if ($forgetPermissionCache) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $ids->count();
    }


    public function createUser(User $actor, array $payload): array
    {
        return DB::transaction(function () use ($actor, $payload) {
            $subject = User::query()->create([
                'name' => trim((string) ($payload['name'] ?? '')),
                'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
                'username' => trim((string) ($payload['username'] ?? '')),
                'nisj' => $this->nullableString($payload['nisj'] ?? null),
                'outlet_id' => $this->nullableString($payload['outlet_id'] ?? null),
                'password' => (string) ($payload['password'] ?? ''),
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]);

            $employee = null;
            $assignmentTitle = $this->nullableString($payload['assignment_role_title'] ?? null);
            $targetOutletId = $this->nullableString($payload['outlet_id'] ?? null);
            $targetNisj = $this->nullableString($payload['nisj'] ?? null);
            $needsEmployee = $assignmentTitle !== null || $targetOutletId !== null || $targetNisj !== null;

            if ($needsEmployee) {
                $employee = Employee::query()->create([
                    'user_id' => $subject->id,
                    'assignment_id' => null,
                    'nisj' => $targetNisj,
                    'full_name' => $subject->name,
                    'nickname' => $subject->name,
                    'employment_status' => 'manual',
                ]);
            }

            if ($employee && ($assignmentTitle !== null || $targetOutletId !== null)) {
                $assignment = Assignment::query()->create([
                    'employee_id' => $employee->id,
                    'outlet_id' => $targetOutletId,
                    'role_title' => $assignmentTitle,
                    'is_primary' => true,
                    'status' => 'manual',
                ]);
                $employee->assignment_id = $assignment->id;
                $employee->save();
            }

            $this->ensureAccessAssignment($subject);
            $sync = $this->updateUserAssignment(
                $actor,
                $subject,
                (string) ($payload['access_role_id'] ?? ''),
                $this->nullableString($payload['access_level_id'] ?? null),
            );

            $subject->refresh()->loadMissing([
                'employee.assignment.outlet',
                'outlet',
                'roles',
                'accessAssignment.role.userType',
                'accessAssignment.level',
            ]);

            return [
                'user' => $subject,
                'subject_access' => $sync['access'] ?? null,
                'subject_permissions' => $sync['permissions'] ?? [],
                'hr_squad' => $sync['hr_squad'] ?? null,
                'current_actor_session' => $this->currentSessionSnapshot($actor),
            ];
        });
    }


    public function updateUserProfile(User $actor, User $subject, array $payload): array
    {
        return DB::transaction(function () use ($actor, $subject, $payload) {
            $subject->fill([
                'username' => trim((string) ($payload['username'] ?? $subject->username ?? '')),
                'nisj' => $this->nullableString($payload['nisj'] ?? $subject->nisj),
                'outlet_id' => $this->nullableString($payload['outlet_id'] ?? $subject->outlet_id),
            ]);
            $subject->save();

            $employee = $subject->employee;
            $assignmentTitle = $this->nullableString($payload['assignment_role_title'] ?? null);
            $targetOutletId = $this->nullableString($payload['outlet_id'] ?? $subject->outlet_id);
            $needsEmployee = $employee || $assignmentTitle !== null || $targetOutletId !== null || $this->nullableString($payload['nisj'] ?? null) !== null;

            if (! $employee && $needsEmployee) {
                $employee = Employee::query()->create([
                    'user_id' => $subject->id,
                    'assignment_id' => null,
                    'nisj' => $this->nullableString($payload['nisj'] ?? $subject->nisj),
                    'full_name' => $subject->name,
                    'nickname' => $subject->name,
                    'employment_status' => 'manual',
                ]);
            }

            if ($employee) {
                $employee->fill([
                    'nisj' => $this->nullableString($payload['nisj'] ?? $subject->nisj),
                    'full_name' => $employee->full_name ?: $subject->name,
                    'nickname' => $employee->nickname ?: $subject->name,
                ]);
                $employee->save();

                $assignment = $employee->assignment;
                if (! $assignment && ($assignmentTitle !== null || $targetOutletId !== null)) {
                    $assignment = Assignment::query()->create([
                        'employee_id' => $employee->id,
                        'outlet_id' => $targetOutletId,
                        'role_title' => $assignmentTitle,
                        'is_primary' => true,
                        'status' => 'manual',
                    ]);
                    $employee->assignment_id = $assignment->id;
                    $employee->save();
                } elseif ($assignment) {
                    $assignment->fill([
                        'outlet_id' => $targetOutletId,
                        'role_title' => $assignmentTitle,
                        'is_primary' => true,
                        'status' => $assignment->status ?: 'manual',
                    ]);
                    $assignment->save();
                }
            }

            $subject->refresh()->loadMissing([
                'employee.assignment.outlet',
                'outlet',
                'roles',
                'accessAssignment.role.userType',
                'accessAssignment.level',
            ]);

            $hrSquad = $this->hrSquadWiring->ensureForUser($subject, true);

            return [
                'user' => $subject,
                'hr_squad' => $hrSquad,
                'current_actor_session' => $this->currentSessionSnapshot($actor),
            ];
        });
    }


    public function updateStakeholderObserverScope(User $actor, User $subject, string $roleCode, array $outletIds): array
    {
        $roleCode = strtoupper(trim($roleCode));
        if (! in_array($roleCode, ['STAKEHOLDER', 'OBSERVER'], true)) {
            throw new \InvalidArgumentException('Role scope hanya mendukung STAKEHOLDER atau OBSERVER.');
        }

        return DB::transaction(function () use ($actor, $subject, $roleCode, $outletIds) {
            $role = AccessRole::query()->where('code', $roleCode)->firstOrFail();
            $level = AccessLevel::query()->where('code', 'DEFAULT')->first() ?: AccessLevel::query()->first();

            $cleanOutletIds = Outlet::query()
                ->whereIn('id', array_values(array_filter(array_map(fn ($id) => trim((string) $id), $outletIds))))
                ->orderBy('name')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();

            if (count($cleanOutletIds) === 0) {
                throw new \InvalidArgumentException('Minimal pilih satu outlet scope.');
            }


            $sync = $this->updateUserAssignment(
                $actor,
                $subject,
                (string) $role->id,
                $level?->id ? (string) $level->id : null,
            );

            $portalCode = $this->reportPortalCodeForStakeholderObserverRole($roleCode);

            UserReportOutletAssignment::query()
                ->where('user_id', (string) $subject->id)
                ->whereIn('portal_code', ['omzet-report', 'sales-report'])
                ->delete();

            foreach ($cleanOutletIds as $outletId) {
                UserReportOutletAssignment::query()->updateOrCreate(
                    [
                        'user_id' => (string) $subject->id,
                        'portal_code' => $portalCode,
                        'outlet_id' => (string) $outletId,
                    ],
                    []
                );
            }

            $fresh = $subject->fresh([
                'employee.assignment.outlet',
                'outlet',
                'roles',
                'accessAssignment.role.userType',
                'accessAssignment.level',
                'reportOutletAssignments.outlet',
            ]);

            return [
                'user' => $fresh,
                'subject_access' => $sync['access'] ?? null,
                'subject_permissions' => $sync['permissions'] ?? [],
                'current_actor_session' => $this->currentSessionSnapshot($actor),
            ];
        });
    }

    public function reportPortalCodeForStakeholderObserverRole(string $roleCode): string
    {
        return strtoupper(trim($roleCode)) === 'OBSERVER' ? 'sales-report' : 'omzet-report';
    }
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    public function listUsers(): Collection
    {
        return User::query()->with(['accessAssignment.role.userType', 'accessAssignment.level', 'roles', 'outlet'])->orderBy('name')->get();
    }

    public function currentSessionSnapshot(User $user): array
    {
        return $this->getSessionSnapshot($user);
    }

    public function currentPosSessionSnapshot(User $user): array
    {
        $snapshot = $this->getSessionSnapshot($user);

        return [
            'permissions' => collect($snapshot['permissions'] ?? [])->filter()->map(fn ($value) => (string) $value)->unique()->values()->all(),
            'access' => $this->compactPosRuntimeAccess($snapshot['access'] ?? ['portals' => [], 'menus' => []]),
            'visible_backoffice_portals' => [],
            'can_edit_user_management' => false,
            'report_access' => ['portals' => []],
        ];
    }

    protected function compactPosRuntimeAccess(array $access): array
    {
        $menus = collect($access['menus'] ?? [])
            ->filter(function ($menu) {
                $path = trim((string) ($menu['path'] ?? ''));
                if ($path === '') {
                    return false;
                }

                $hasAnyPermission = !empty($menu['can_view'])
                    || !empty($menu['can_create'])
                    || !empty($menu['can_edit'])
                    || !empty($menu['can_delete']);

                if (! $hasAnyPermission) {
                    return false;
                }

                return str_starts_with($path, '/c/') || $path === '/sales';
            })
            ->map(fn ($menu) => [
                'id' => isset($menu['id']) ? (string) $menu['id'] : null,
                'code' => (string) ($menu['code'] ?? ''),
                'name' => (string) ($menu['name'] ?? ($menu['code'] ?? '')),
                'path' => (string) ($menu['path'] ?? ''),
                'portal_id' => isset($menu['portal_id']) && $menu['portal_id'] !== null ? (string) $menu['portal_id'] : null,
                'portal_code' => (string) ($menu['portal_code'] ?? ''),
                'portal_name' => (string) ($menu['portal_name'] ?? ''),
                'can_view' => (bool) ($menu['can_view'] ?? false),
                'can_create' => (bool) ($menu['can_create'] ?? false),
                'can_edit' => (bool) ($menu['can_edit'] ?? false),
                'can_delete' => (bool) ($menu['can_delete'] ?? false),
                'permission_view' => isset($menu['permission_view']) ? (string) $menu['permission_view'] : null,
            ])
            ->unique(fn ($menu) => strtolower((string) ($menu['path'] ?? '')) . '|' . strtolower((string) ($menu['code'] ?? '')))
            ->values()
            ->all();

        return [
            'portals' => [],
            'menus' => $menus,
        ];
    }

    public function buildPosUserPayload(User $user, array $authContext = []): array
    {
        $user->loadMissing(['roles', 'employee.assignment.outlet', 'outlet']);

        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $resolvedOutlet = $assignment?->outlet ?: $user->outlet;
        $resolvedTimezone = (string) ($authContext['resolved_outlet_timezone'] ?? $resolvedOutlet?->timezone ?? 'Asia/Jakarta');

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'nisj' => $user->nisj ? (string) $user->nisj : ($employee?->nisj ? (string) $employee->nisj : null),
            'username' => $user->username ? (string) $user->username : null,
            'email' => (string) $user->email,
            'is_active' => (bool) ($user->is_active ?? true),
            'pos_delete_bill_pin' => preg_replace('/\D+/', '', (string) ($resolvedOutlet?->pos_delete_bill_pin ?: '0341')) ?: '0341',
            'delete_open_bill_pin' => preg_replace('/\D+/', '', (string) ($resolvedOutlet?->pos_delete_bill_pin ?: '0341')) ?: '0341',
            'outlet_id' => $resolvedOutlet?->id ? (string) $resolvedOutlet->id : null,
            'outlet_code' => $resolvedOutlet?->code ? (string) $resolvedOutlet->code : null,
            'outlet_type' => $resolvedOutlet?->type ? (string) $resolvedOutlet->type : null,
            'timezone' => $resolvedTimezone,
            'resolved_outlet_timezone' => $resolvedTimezone,
            'scope_locked' => (bool) ($authContext['scope_locked'] ?? false),
            'can_adjust_scope' => (bool) ($authContext['can_adjust_scope'] ?? false),
            'outlet' => $resolvedOutlet ? [
                'id' => (string) $resolvedOutlet->id,
                'code' => (string) $resolvedOutlet->code,
                'name' => (string) $resolvedOutlet->name,
                'type' => (string) ($resolvedOutlet->type ?? 'outlet'),
                'timezone' => (string) ($resolvedOutlet->timezone ?? 'Asia/Jakarta'),
                'pos_delete_bill_pin' => preg_replace('/\D+/', '', (string) ($resolvedOutlet?->pos_delete_bill_pin ?: '0341')) ?: '0341',
                'delete_open_bill_pin' => preg_replace('/\D+/', '', (string) ($resolvedOutlet?->pos_delete_bill_pin ?: '0341')) ?: '0341',
            ] : null,
            'assignment' => $assignment ? [
                'id' => (string) $assignment->id,
                'role_title' => $assignment->role_title,
                'status' => $assignment->status,
                'is_primary' => (bool) $assignment->is_primary,
                'outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
                'outlet_code' => $assignment->outlet?->code ? (string) $assignment->outlet->code : null,
                'outlet_name' => $assignment->outlet?->name ? (string) $assignment->outlet->name : null,
            ] : null,
            'auth_context' => $authContext,
            'roles' => $user->roles?->pluck('name')->values()->all() ?? [],
        ];
    }

    protected function compactSessionAccess(array $access): array
    {
        $portals = collect($access['portals'] ?? [])
            ->filter(fn ($portal) => !empty($portal['can_view']))
            ->map(fn ($portal) => [
                'id' => isset($portal['id']) ? (string) $portal['id'] : null,
                'code' => (string) ($portal['code'] ?? ''),
                'name' => (string) ($portal['name'] ?? ($portal['code'] ?? '')),
                'can_view' => true,
            ])
            ->values()
            ->all();

        $menus = collect($access['menus'] ?? [])
            ->filter(function ($menu) {
                return !empty($menu['can_view'])
                    || !empty($menu['can_create'])
                    || !empty($menu['can_edit'])
                    || !empty($menu['can_delete']);
            })
            ->map(fn ($menu) => [
                'id' => isset($menu['id']) ? (string) $menu['id'] : null,
                'code' => (string) ($menu['code'] ?? ''),
                'name' => (string) ($menu['name'] ?? ($menu['code'] ?? '')),
                'path' => (string) ($menu['path'] ?? ''),
                'portal_id' => isset($menu['portal_id']) && $menu['portal_id'] !== null ? (string) $menu['portal_id'] : null,
                'portal_code' => (string) ($menu['portal_code'] ?? ''),
                'can_view' => (bool) ($menu['can_view'] ?? false),
                'can_create' => (bool) ($menu['can_create'] ?? false),
                'can_edit' => (bool) ($menu['can_edit'] ?? false),
                'can_delete' => (bool) ($menu['can_delete'] ?? false),
            ])
            ->values()
            ->all();

        return [
            'role' => $access['role'] ?? null,
            'user_type' => $access['user_type'] ?? null,
            'level' => $access['level'] ?? null,
            'portals' => $portals,
            'menus' => $menus,
        ];
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

    public function ensureMastersReady(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        $mappedSpatieRoles = AccessRole::query()
            ->whereNotNull('spatie_role_name')
            ->where('spatie_role_name', '!=', '')
            ->pluck('spatie_role_name')
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values();
        $availableSpatieRoleCount = $mappedSpatieRoles->isEmpty()
            ? 0
            : SpatieRole::query()->where('guard_name', $guard)->whereIn('name', $mappedSpatieRoles->all())->count();
        $spatieRolesReady = $mappedSpatieRoles->isEmpty() || $availableSpatieRoleCount === $mappedSpatieRoles->count();

        $ready = AccessRole::query()->where('code', 'ADMIN')->exists()
            && AccessLevel::query()->exists()
            && AccessPortal::query()->exists()
            && AccessMenu::query()->exists()
            && $spatieRolesReady;

        if (! $ready) {
            $this->ensureMasters();
            $ready = true;
        }
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

        // ERP POS FINAL I07-HF02: Access Matrix can only synchronize an
        // Access Role when its referenced Spatie role exists. Production
        // databases that were migrated without re-running AuthSeeder could
        // otherwise fail with "Spatie role cashier belum tersedia". Keep the
        // role identity self-healing; concrete permissions remain governed by
        // the Access Matrix and direct permission synchronization below.
        $guard = (string) config('auth.defaults.guard', 'web');
        AccessRole::query()
            ->whereNotNull('spatie_role_name')
            ->where('spatie_role_name', '!=', '')
            ->pluck('spatie_role_name')
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->each(fn ($name) => SpatieRole::findOrCreate((string) $name, $guard));

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

        foreach (array_merge(UserManagementCatalog::portals(), [
            ['code' => 'pos', 'name' => 'POS', 'description' => 'Portal POS', 'sort_order' => 15],
        ]) as $portalSeed) {
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

        $menuSeeds = array_map(fn (array $seed) => $seed + ['legacy_codes' => []], UserManagementCatalog::menus());
        $menuSeeds = array_merge($menuSeeds, [
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
                'portal_code' => 'pos',
                'code' => 'pos-dashboard',
                'legacy_codes' => ['cashier-dashboard'],
                'name' => 'Dashboard',
                'path' => '/c/dashboard',
                'sort_order' => 10,
                'permission_view' => 'pos.checkout',
            ],
            [
                'portal_code' => 'pos',
                'code' => 'pos-provision',
                'legacy_codes' => ['sales-provision', 'cashier-provision'],
                'name' => 'Provision',
                'path' => '/c/provision',
                'sort_order' => 36,
                'permission_view' => 'pos.provision.view',
            ],
        ]);

        foreach ($menuSeeds as $seed) {
            $portalId = $portalMap->get($seed['portal_code']);
            if (! $portalId) {
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
