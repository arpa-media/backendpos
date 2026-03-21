<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Assignment;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    private const STORAGE_PATH = 'app/user-management/state.json';

    public function overview(Request $request)
    {
        $state = $this->readState();
        $masters = $state['masters'];
        $userAccess = $state['user_access'] ?? [];
        $q = trim((string) $request->string('q', ''));
        $perPage = max(1, min(100, (int) $request->integer('per_page', 10)));
        $page = max(1, (int) $request->integer('page', 1));

        $usersQuery = User::query()->with(['employee.assignment.outlet', 'outlet', 'roles']);
        if ($q !== '') {
            $usersQuery->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('nisj', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $paginator = $usersQuery->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        $roleMap = collect($masters['roles'])->keyBy('id');
        $levelMap = collect($masters['levels'])->keyBy('id');
        $userTypeMap = collect($masters['user_types'])->keyBy('id');

        $users = collect($paginator->items())->map(function (User $user) use ($userAccess, $roleMap, $levelMap, $userTypeMap) {
            $employee = $user->employee;
            $assignment = $employee?->assignment;
            $outlet = $assignment?->outlet ?: $user->outlet;
            $accessRow = Arr::get($userAccess, (string) $user->id, []);
            $role = $roleMap->get($accessRow['access_role_id'] ?? '');
            $level = $levelMap->get($accessRow['access_level_id'] ?? '');
            $userType = $role ? $userTypeMap->get($role['user_type_id'] ?? '') : null;

            return [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'nisj' => $user->nisj,
                'email' => $user->email,
                'is_active' => (bool) ($user->is_active ?? true),
                'employee' => $employee ? [
                    'id' => (string) $employee->id,
                    'name' => $employee->full_name ?: $employee->nickname,
                    'nisj' => $employee->nisj,
                    'employee_no' => $employee->hr_employee_id,
                    'status' => $employee->employment_status,
                ] : null,
                'assignment' => $assignment ? [
                    'id' => (string) $assignment->id,
                    'role_title' => $assignment->role_title,
                    'status' => $assignment->status,
                    'is_primary' => (bool) $assignment->is_primary,
                ] : null,
                'outlet' => $outlet ? [
                    'id' => (string) $outlet->id,
                    'name' => $outlet->name,
                    'code' => $outlet->code,
                    'type' => $outlet->type,
                    'timezone' => $outlet->timezone,
                ] : null,
                'access' => [
                    'role' => $role,
                    'level' => $level,
                    'user_type' => $userType,
                ],
                'legacy' => [
                    'roles' => $user->roles->pluck('name')->values()->all(),
                ],
            ];
        })->values()->all();

        return ApiResponse::ok([
            'summary' => [
                'users' => User::count(),
                'roles' => count($masters['roles']),
                'levels' => count($masters['levels']),
                'portals' => count($masters['portals']),
                'menus' => count($masters['menus']),
                'portal_permissions' => count($state['matrix']['portal_permissions'] ?? []),
                'menu_permissions' => count($state['matrix']['menu_permissions'] ?? []),
                'spatie_permissions' => 0,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'masters' => $masters,
            'matrix' => $state['matrix'],
            'users' => $users,
            'audit_logs' => [],
        ], 'OK');
    }

    public function storeRole(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'user_type_id' => ['nullable', 'string'],
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $role = [
            'id' => (string) Str::ulid(),
            'user_type_id' => $data['user_type_id'] ?? null,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $role['user_type'] = $this->findUserType($state, $role['user_type_id']);
        $state['masters']['roles'][] = $role;
        $this->writeState($state);

        return ApiResponse::ok(['role' => $role, 'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state)], 'Role created');
    }

    public function updateRole(Request $request, string $id)
    {
        $state = $this->readState();
        $data = $request->validate([
            'user_type_id' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        foreach ($state['masters']['roles'] as &$role) {
            if (($role['id'] ?? null) !== $id) continue;
            $role['user_type_id'] = $data['user_type_id'] ?? null;
            $role['name'] = trim((string) $data['name']);
            $role['description'] = $data['description'] ?? null;
            $role['is_active'] = (bool) ($data['is_active'] ?? true);
            $role['user_type'] = $this->findUserType($state, $role['user_type_id']);
            $this->writeState($state);
            return ApiResponse::ok(['role' => $role, 'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state)], 'Role updated');
        }

        return ApiResponse::error('Role tidak ditemukan.', 'ROLE_NOT_FOUND', 404);
    }

    public function storeLevel(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $level = [
            'id' => (string) Str::ulid(),
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $state['masters']['levels'][] = $level;
        $this->writeState($state);

        return ApiResponse::ok(['level' => $level, 'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state)], 'Level created');
    }

    public function updateLevel(Request $request, string $id)
    {
        $state = $this->readState();
        $data = $request->validate([
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        foreach ($state['masters']['levels'] as &$level) {
            if (($level['id'] ?? null) !== $id) continue;
            $level['name'] = trim((string) $data['name']);
            $level['description'] = $data['description'] ?? null;
            $level['is_active'] = (bool) ($data['is_active'] ?? true);
            $this->writeState($state);
            return ApiResponse::ok(['level' => $level, 'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state)], 'Level updated');
        }

        return ApiResponse::error('Level tidak ditemukan.', 'LEVEL_NOT_FOUND', 404);
    }

    public function updateUserAccess(Request $request, string $userId)
    {
        $state = $this->readState();
        $data = $request->validate([
            'access_role_id' => ['required', 'string'],
            'access_level_id' => ['nullable', 'string'],
        ]);

        $state['user_access'][$userId] = [
            'access_role_id' => $data['access_role_id'],
            'access_level_id' => $data['access_level_id'] ?? null,
        ];

        $this->writeState($state);

        return ApiResponse::ok([
            'user_access' => $state['user_access'][$userId],
            'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state),
        ], 'User access updated');
    }

    public function updatePortalPermission(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'access_role_id' => ['required', 'string'],
            'access_level_id' => ['nullable', 'string'],
            'portal_id' => ['required', 'string'],
            'can_view' => ['required', 'boolean'],
        ]);

        $row = [
            'id' => (string) Str::ulid(),
            'access_role_id' => $data['access_role_id'],
            'access_level_id' => $data['access_level_id'] ?? null,
            'portal_id' => $data['portal_id'],
            'can_view' => (bool) $data['can_view'],
        ];

        $state['matrix']['portal_permissions'] = $this->upsertScopedRow($state['matrix']['portal_permissions'], $row, ['access_role_id', 'access_level_id', 'portal_id']);
        $this->decorateState($state);
        $this->writeState($state);

        return ApiResponse::ok([
            'row' => $row,
            'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state),
            'sync_summary' => ['synced_users' => $this->countUsersByRoleLevel($state, $data['access_role_id'], $data['access_level_id'] ?? null)],
        ], 'Portal permission updated');
    }

    public function bulkPortalPermissions(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'access_role_id' => ['required', 'string'],
            'access_level_id' => ['nullable', 'string'],
            'rows' => ['required', 'array'],
            'rows.*.portal_id' => ['required', 'string'],
            'rows.*.can_view' => ['required', 'boolean'],
        ]);

        $levelId = $data['access_level_id'] ?? null;
        $state['matrix']['portal_permissions'] = array_values(array_filter($state['matrix']['portal_permissions'], function ($row) use ($data, $levelId) {
            return !(($row['access_role_id'] ?? null) === $data['access_role_id'] && (($row['access_level_id'] ?? null) === $levelId));
        }));
        foreach ($data['rows'] as $item) {
            $state['matrix']['portal_permissions'][] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => $data['access_role_id'],
                'access_level_id' => $levelId,
                'portal_id' => $item['portal_id'],
                'can_view' => (bool) $item['can_view'],
            ];
        }
        $this->decorateState($state);
        $this->writeState($state);

        return ApiResponse::ok([
            'rows' => $data['rows'],
            'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state),
            'sync_summary' => ['synced_users' => $this->countUsersByRoleLevel($state, $data['access_role_id'], $levelId)],
        ], 'Bulk portal permissions updated');
    }

    public function updateMenuPermission(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'access_role_id' => ['required', 'string'],
            'access_level_id' => ['nullable', 'string'],
            'menu_id' => ['required', 'string'],
            'can_view' => ['required', 'boolean'],
            'can_create' => ['required', 'boolean'],
            'can_edit' => ['required', 'boolean'],
            'can_delete' => ['required', 'boolean'],
        ]);

        $row = [
            'id' => (string) Str::ulid(),
            'access_role_id' => $data['access_role_id'],
            'access_level_id' => $data['access_level_id'] ?? null,
            'menu_id' => $data['menu_id'],
            'can_view' => (bool) $data['can_view'],
            'can_create' => (bool) $data['can_create'],
            'can_edit' => (bool) $data['can_edit'],
            'can_delete' => (bool) $data['can_delete'],
        ];

        $state['matrix']['menu_permissions'] = $this->upsertScopedRow($state['matrix']['menu_permissions'], $row, ['access_role_id', 'access_level_id', 'menu_id']);
        $this->decorateState($state);
        $this->writeState($state);

        return ApiResponse::ok([
            'row' => $row,
            'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state),
            'sync_summary' => ['synced_users' => $this->countUsersByRoleLevel($state, $data['access_role_id'], $data['access_level_id'] ?? null)],
        ], 'Menu permission updated');
    }

    public function bulkMenuPermissions(Request $request)
    {
        $state = $this->readState();
        $data = $request->validate([
            'access_role_id' => ['required', 'string'],
            'access_level_id' => ['nullable', 'string'],
            'rows' => ['required', 'array'],
            'rows.*.menu_id' => ['required', 'string'],
            'rows.*.can_view' => ['required', 'boolean'],
            'rows.*.can_create' => ['required', 'boolean'],
            'rows.*.can_edit' => ['required', 'boolean'],
            'rows.*.can_delete' => ['required', 'boolean'],
        ]);

        $levelId = $data['access_level_id'] ?? null;
        $state['matrix']['menu_permissions'] = array_values(array_filter($state['matrix']['menu_permissions'], function ($row) use ($data, $levelId) {
            return !(($row['access_role_id'] ?? null) === $data['access_role_id'] && (($row['access_level_id'] ?? null) === $levelId));
        }));
        foreach ($data['rows'] as $item) {
            $state['matrix']['menu_permissions'][] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => $data['access_role_id'],
                'access_level_id' => $levelId,
                'menu_id' => $item['menu_id'],
                'can_view' => (bool) $item['can_view'],
                'can_create' => (bool) $item['can_create'],
                'can_edit' => (bool) $item['can_edit'],
                'can_delete' => (bool) $item['can_delete'],
            ];
        }
        $this->decorateState($state);
        $this->writeState($state);

        return ApiResponse::ok([
            'rows' => $data['rows'],
            'current_actor_session' => $this->buildCurrentActorSession($request->user(), $state),
            'sync_summary' => ['synced_users' => $this->countUsersByRoleLevel($state, $data['access_role_id'], $levelId)],
        ], 'Bulk menu permissions updated');
    }

    private function readState(): array
    {
        $absolute = storage_path(self::STORAGE_PATH);
        if (!File::exists($absolute)) {
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, json_encode($this->defaultState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $decoded = json_decode((string) File::get($absolute), true);
        $state = is_array($decoded) ? $decoded : $this->defaultState();
        $this->decorateState($state);
        return $state;
    }

    private function writeState(array $state): void
    {
        $this->decorateState($state);
        $absolute = storage_path(self::STORAGE_PATH);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function decorateState(array &$state): void
    {
        $portalMap = collect($state['masters']['portals'])->keyBy('id');
        $userTypeMap = collect($state['masters']['user_types'])->keyBy('id');

        foreach ($state['masters']['roles'] as &$role) {
            $role['user_type'] = $this->findUserType($state, $role['user_type_id'] ?? null);
        }
        unset($role);

        foreach ($state['masters']['menus'] as &$menu) {
            $portal = $portalMap->get($menu['portal_id'] ?? '');
            if ($portal) {
                $menu['portal'] = $portal;
                $menu['portal_code'] = $portal['code'];
                $menu['portal_name'] = $portal['name'];
            }
        }
        unset($menu);

        $roleMap = collect($state['masters']['roles'])->keyBy('id');
        $levelMap = collect($state['masters']['levels'])->keyBy('id');
        foreach ($state['matrix']['portal_permissions'] as &$row) {
            $portal = $portalMap->get($row['portal_id'] ?? '');
            $role = $roleMap->get($row['access_role_id'] ?? '');
            $level = $levelMap->get($row['access_level_id'] ?? '');
            if ($portal) {
                $row['portal_code'] = $portal['code'];
                $row['portal_name'] = $portal['name'];
            }
            if ($role) {
                $row['role_name'] = $role['name'];
                $row['role_code'] = $role['code'];
            }
            if ($level) {
                $row['level_name'] = $level['name'];
                $row['level_code'] = $level['code'];
            }
        }
        unset($row);
        foreach ($state['matrix']['menu_permissions'] as &$row) {
            $menu = collect($state['masters']['menus'])->keyBy('id')->get($row['menu_id'] ?? '');
            $role = $roleMap->get($row['access_role_id'] ?? '');
            $level = $levelMap->get($row['access_level_id'] ?? '');
            if ($menu) {
                $row['menu_name'] = $menu['name'];
                $row['menu_path'] = $menu['path'];
                $row['portal_id'] = $menu['portal_id'];
                $row['portal_code'] = $menu['portal_code'] ?? null;
                $row['portal_name'] = $menu['portal_name'] ?? null;
            }
            if ($role) {
                $row['role_name'] = $role['name'];
                $row['role_code'] = $role['code'];
            }
            if ($level) {
                $row['level_name'] = $level['name'];
                $row['level_code'] = $level['code'];
            }
        }
        unset($row);
    }

    private function buildCurrentActorSession(?User $user, array $state): array
    {
        if (!$user) {
            return [
                'access' => ['portals' => [], 'menus' => []],
                'visible_backoffice_portals' => [],
                'can_edit_user_management' => true,
            ];
        }

        $userAccess = $state['user_access'][(string) $user->id] ?? [];
        $roleId = $userAccess['access_role_id'] ?? null;
        $levelId = $userAccess['access_level_id'] ?? null;
        $access = $this->buildAccessSnapshot($state, $roleId, $levelId);

        return [
            'access' => $access,
            'visible_backoffice_portals' => array_values(array_map(fn ($item) => ['code' => $item['code'], 'name' => $item['name']], array_filter($access['portals'], fn ($item) => !empty($item['can_view'])))),
            'can_edit_user_management' => true,
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    private function buildAccessSnapshot(array $state, ?string $roleId, ?string $levelId): array
    {
        $portals = [];
        foreach ($state['masters']['portals'] as $portal) {
            $base = $this->findMatrixRow($state['matrix']['portal_permissions'], $roleId, null, 'portal_id', $portal['id']);
            $exact = $levelId ? $this->findMatrixRow($state['matrix']['portal_permissions'], $roleId, $levelId, 'portal_id', $portal['id']) : null;
            $source = $exact ?: $base;
            $portals[] = [
                'id' => $portal['id'],
                'code' => $portal['code'],
                'name' => $portal['name'],
                'can_view' => (bool) ($source['can_view'] ?? false),
            ];
        }

        $menus = [];
        foreach ($state['masters']['menus'] as $menu) {
            $base = $this->findMatrixRow($state['matrix']['menu_permissions'], $roleId, null, 'menu_id', $menu['id']);
            $exact = $levelId ? $this->findMatrixRow($state['matrix']['menu_permissions'], $roleId, $levelId, 'menu_id', $menu['id']) : null;
            $source = $exact ?: $base;
            $menus[] = [
                'id' => $menu['id'],
                'portal_id' => $menu['portal_id'],
                'portal_code' => $menu['portal_code'] ?? null,
                'portal_name' => $menu['portal_name'] ?? null,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'can_view' => (bool) ($source['can_view'] ?? false),
                'can_create' => (bool) ($source['can_create'] ?? false),
                'can_edit' => (bool) ($source['can_edit'] ?? false),
                'can_delete' => (bool) ($source['can_delete'] ?? false),
            ];
        }

        return ['role_id' => $roleId, 'level_id' => $levelId, 'portals' => $portals, 'menus' => $menus];
    }

    private function findMatrixRow(array $rows, ?string $roleId, ?string $levelId, string $key, string $value): ?array
    {
        foreach ($rows as $row) {
            if (($row['access_role_id'] ?? null) !== $roleId) continue;
            if (($row['access_level_id'] ?? null) !== $levelId) continue;
            if (($row[$key] ?? null) !== $value) continue;
            return $row;
        }
        return null;
    }

    private function upsertScopedRow(array $rows, array $newRow, array $keys): array
    {
        $updated = false;
        foreach ($rows as &$row) {
            $matched = true;
            foreach ($keys as $key) {
                if (($row[$key] ?? null) !== ($newRow[$key] ?? null)) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $newRow['id'] = $row['id'] ?? $newRow['id'];
                $row = array_merge($row, $newRow);
                $updated = true;
                break;
            }
        }
        unset($row);

        if (!$updated) $rows[] = $newRow;
        return array_values($rows);
    }

    private function findUserType(array $state, ?string $id): ?array
    {
        if (!$id) return null;
        foreach ($state['masters']['user_types'] as $item) {
            if (($item['id'] ?? null) === $id) return $item;
        }
        return null;
    }

    private function countUsersByRoleLevel(array $state, string $roleId, ?string $levelId): int
    {
        return collect($state['user_access'] ?? [])->filter(function ($row) use ($roleId, $levelId) {
            return ($row['access_role_id'] ?? null) === $roleId && (($row['access_level_id'] ?? null) === $levelId);
        })->count();
    }

    private function defaultState(): array
    {
        $userTypes = [
            ['id' => 'ut-admin', 'code' => 'ADMIN', 'name' => 'Admin'],
            ['id' => 'ut-staff', 'code' => 'STAFF', 'name' => 'Staff'],
            ['id' => 'ut-squad', 'code' => 'SQUAD', 'name' => 'Squad'],
        ];

        $portals = [
            ['id' => 'portal-pos-outlet', 'code' => 'sales', 'name' => 'POS Outlet'],
            ['id' => 'portal-human-resource', 'code' => 'human-resource', 'name' => 'Human Resource'],
            ['id' => 'portal-finance', 'code' => 'finance', 'name' => 'Finance'],
            ['id' => 'portal-operational', 'code' => 'operational', 'name' => 'Chambers Operational'],
            ['id' => 'portal-hpp', 'code' => 'hpp-cogs', 'name' => 'HPP/COGS'],
            ['id' => 'portal-inventory', 'code' => 'inventory', 'name' => 'Stock Inventory'],
            ['id' => 'portal-customer', 'code' => 'customer', 'name' => 'Customer'],
        ];

        $menus = [
            ['id' => 'menu-sales', 'portal_id' => 'portal-finance', 'name' => 'Sales', 'path' => '/sales'],
            ['id' => 'menu-report', 'portal_id' => 'portal-finance', 'name' => 'Report', 'path' => '/reports'],
            ['id' => 'menu-cancel-bill', 'portal_id' => 'portal-finance', 'name' => 'Cancel Bill', 'path' => '/cancel-requests'],
            ['id' => 'menu-product', 'portal_id' => 'portal-pos-outlet', 'name' => 'Product', 'path' => '/products'],
            ['id' => 'menu-category', 'portal_id' => 'portal-pos-outlet', 'name' => 'Category', 'path' => '/categories'],
            ['id' => 'menu-payment-method', 'portal_id' => 'portal-pos-outlet', 'name' => 'Payment Method', 'path' => '/payment-methods'],
            ['id' => 'menu-discount', 'portal_id' => 'portal-pos-outlet', 'name' => 'Discount', 'path' => '/discounts'],
            ['id' => 'menu-taxes', 'portal_id' => 'portal-pos-outlet', 'name' => 'Taxes', 'path' => '/taxes'],
            ['id' => 'menu-customer', 'portal_id' => 'portal-customer', 'name' => 'Customer', 'path' => '/customers'],
            ['id' => 'menu-user-management', 'portal_id' => 'portal-human-resource', 'name' => 'User Management', 'path' => '/user-management'],
            ['id' => 'menu-outlet', 'portal_id' => 'portal-operational', 'name' => 'Outlet', 'path' => '/settings/outlet'],
            ['id' => 'menu-bom', 'portal_id' => 'portal-hpp', 'name' => 'Bill of Material', 'path' => '/bill-of-material'],
            ['id' => 'menu-check-stock', 'portal_id' => 'portal-inventory', 'name' => 'Cek Stock', 'path' => '/check-stock'],
            ['id' => 'menu-request-stock', 'portal_id' => 'portal-inventory', 'name' => 'Request Stock', 'path' => '/request-stock'],
        ];

        $roles = [
            ['id' => 'role-super-admin', 'user_type_id' => 'ut-admin', 'code' => 'SUPER_ADMIN', 'name' => 'Super Admin', 'description' => 'Akses penuh backoffice', 'is_active' => true],
            ['id' => 'role-finance-admin', 'user_type_id' => 'ut-admin', 'code' => 'FINANCE_ADMIN', 'name' => 'Finance Admin', 'description' => 'Akses finance', 'is_active' => true],
            ['id' => 'role-hr-admin', 'user_type_id' => 'ut-admin', 'code' => 'HR_ADMIN', 'name' => 'HR Admin', 'description' => 'Akses HR', 'is_active' => true],
        ];

        $levels = [
            ['id' => 'level-operator', 'code' => 'OPERATOR', 'name' => 'Operator', 'description' => 'Operator harian', 'is_active' => true],
            ['id' => 'level-supervisor', 'code' => 'SUPERVISOR', 'name' => 'Supervisor', 'description' => 'Supervisor', 'is_active' => true],
        ];

        $portalPermissions = [];
        foreach ($portals as $portal) {
            $portalPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-super-admin', 'access_level_id' => null, 'portal_id' => $portal['id'], 'can_view' => true];
        }
        foreach ($portals as $portal) {
            $portalPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-finance-admin', 'access_level_id' => null, 'portal_id' => $portal['id'], 'can_view' => in_array($portal['code'], ['finance'], true)];
            $portalPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-hr-admin', 'access_level_id' => null, 'portal_id' => $portal['id'], 'can_view' => in_array($portal['code'], ['human-resource'], true)];
        }

        $menuPermissions = [];
        foreach ($menus as $menu) {
            $menuPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-super-admin', 'access_level_id' => null, 'menu_id' => $menu['id'], 'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true];
            $menuPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-finance-admin', 'access_level_id' => null, 'menu_id' => $menu['id'], 'can_view' => str_starts_with($menu['id'], 'menu-sales') || in_array($menu['id'], ['menu-report', 'menu-cancel-bill'], true), 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
            $menuPermissions[] = ['id' => (string) Str::ulid(), 'access_role_id' => 'role-hr-admin', 'access_level_id' => null, 'menu_id' => $menu['id'], 'can_view' => $menu['id'] === 'menu-user-management', 'can_create' => $menu['id'] === 'menu-user-management', 'can_edit' => $menu['id'] === 'menu-user-management', 'can_delete' => false];
        }

        $firstUserId = User::query()->orderBy('name')->value('id');
        $userAccess = $firstUserId ? [(string) $firstUserId => ['access_role_id' => 'role-super-admin', 'access_level_id' => 'level-supervisor']] : [];

        return [
            'masters' => [
                'user_types' => $userTypes,
                'roles' => $roles,
                'levels' => $levels,
                'portals' => $portals,
                'menus' => $menus,
                'spatie_permissions' => [],
            ],
            'matrix' => [
                'portal_permissions' => $portalPermissions,
                'menu_permissions' => $menuPermissions,
            ],
            'user_access' => $userAccess,
        ];
    }
}
