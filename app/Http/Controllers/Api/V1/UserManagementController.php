<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\AccessLevel;
use App\Models\AccessMenu;
use App\Models\AccessPortal;
use App\Models\AccessRole;
use App\Models\AccessRoleMenuPermission;
use App\Models\AccessRolePortalPermission;
use App\Models\AccessUserType;
use App\Models\Outlet;
use App\Models\PosProvisionControl;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $userManagement)
    {
    }

    public function overview(Request $request)
    {
        $this->userManagement->ensureMasters();

        $q = trim((string) $request->string('q', ''));
        $perPage = max(1, min(100, (int) $request->integer('per_page', 10)));
        $page = max(1, (int) $request->integer('page', 1));
        $outletId = trim((string) $request->string('outlet_id', ''));

        $usersQuery = User::query()->with([
            'employee.assignment.outlet',
            'outlet',
            'roles',
            'accessAssignment.role.userType',
            'accessAssignment.level',
        ]);

        if ($q !== '') {
            $usersQuery->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('nisj', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($outletId !== '') {
            $usersQuery->where(function ($inner) use ($outletId) {
                $inner->where('outlet_id', $outletId)
                    ->orWhereHas('employee.assignment', function ($assignmentQuery) use ($outletId) {
                        $assignmentQuery->where('outlet_id', $outletId);
                    });
            });
        }

        $paginator = $usersQuery->orderBy('name')->paginate($perPage, ['*'], 'page', $page);

        /** @var EloquentCollection<int, User> $users */
        $users = new EloquentCollection($paginator->items());
        $users->each(function (User $user): void {
            $this->userManagement->ensureAccessAssignment($user);
        });
        $users->load([
            'employee.assignment.outlet',
            'outlet',
            'roles',
            'accessAssignment.role.userType',
            'accessAssignment.level',
        ]);

        $masters = $this->buildMasters();
        $matrix = $this->buildMatrix();

        $provisionControlRows = PosProvisionControl::query()
            ->whereIn('user_id', $users->pluck('id')->all())
            ->get(['user_id', 'outlet_id', 'allow_provision', 'notes', 'updated_at'])
            ->groupBy(fn (PosProvisionControl $row) => (string) $row->user_id);

        $payloadUsers = $users->map(function (User $user) use ($provisionControlRows) {
            $employee = $user->employee;
            $hrAssignment = $employee?->assignment;
            $outlet = $hrAssignment?->outlet ?: $user->outlet;
            $accessAssignment = $user->accessAssignment;
            $accessRole = $accessAssignment?->role;
            $accessLevel = $accessAssignment?->level;
            $provisionControl = $this->resolveProvisionControlPayload($user, $outlet, $provisionControlRows->get((string) $user->id));

            return [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'username' => $user->username,
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
                'assignment' => $hrAssignment ? [
                    'id' => (string) $hrAssignment->id,
                    'role_title' => $hrAssignment->role_title,
                    'status' => $hrAssignment->status,
                    'is_primary' => (bool) $hrAssignment->is_primary,
                ] : null,
                'access_assignment' => $accessAssignment ? [
                    'id' => (string) $accessAssignment->id,
                    'assigned_by_user_id' => $accessAssignment->assigned_by_user_id ? (string) $accessAssignment->assigned_by_user_id : null,
                ] : null,
                'outlet' => $outlet ? [
                    'id' => (string) $outlet->id,
                    'name' => $outlet->name,
                    'code' => $outlet->code,
                    'type' => $outlet->type,
                    'timezone' => $outlet->timezone,
                ] : null,
                'access' => [
                    'role' => $accessRole ? [
                        'id' => (string) $accessRole->id,
                        'user_type_id' => $accessRole->user_type_id ? (string) $accessRole->user_type_id : null,
                        'code' => (string) $accessRole->code,
                        'name' => (string) $accessRole->name,
                        'description' => $accessRole->description,
                        'spatie_role_name' => $accessRole->spatie_role_name,
                        'is_active' => (bool) ($accessRole->is_active ?? true),
                    ] : null,
                    'level' => $accessLevel ? [
                        'id' => (string) $accessLevel->id,
                        'code' => (string) $accessLevel->code,
                        'name' => (string) $accessLevel->name,
                        'description' => $accessLevel->description,
                        'is_active' => (bool) ($accessLevel->is_active ?? true),
                    ] : null,
                    'user_type' => $accessRole?->userType ? [
                        'id' => (string) $accessRole->userType->id,
                        'code' => (string) $accessRole->userType->code,
                        'name' => (string) $accessRole->userType->name,
                        'description' => $accessRole->userType->description,
                        'is_active' => (bool) ($accessRole->userType->is_active ?? true),
                    ] : null,
                ],
                'legacy' => [
                    'roles' => $user->roles->pluck('name')->values()->all(),
                ],
                'provision_control' => $provisionControl,
            ];
        })->values()->all();

        return ApiResponse::ok([
            'active_filters' => [
                'q' => $q !== '' ? $q : null,
                'outlet_id' => $outletId !== '' ? $outletId : null,
            ],
            'summary' => [
                'users' => User::query()->count(),
                'roles' => count($masters['roles']),
                'levels' => count($masters['levels']),
                'portals' => count($masters['portals']),
                'menus' => count($masters['menus']),
                'portal_permissions' => count($matrix['portal_permissions']),
                'menu_permissions' => count($matrix['menu_permissions']),
                'spatie_permissions' => Permission::query()->count(),
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'masters' => $masters,
            'matrix' => $matrix,
            'users' => $payloadUsers,
            'audit_logs' => [],
        ], 'OK');
    }

    public function storeRole(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'user_type_id' => ['nullable', 'string', Rule::exists('access_user_types', 'id')],
            'code' => ['required', 'string', 'max:100', Rule::unique('access_roles', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $role = AccessRole::query()->create([
            'user_type_id' => $data['user_type_id'] ?? null,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->load('userType');

        return ApiResponse::ok([
            'role' => $this->serializeRole($role),
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Role created');
    }

    public function updateRole(Request $request, string $id)
    {
        $this->userManagement->ensureMasters();

        $role = AccessRole::query()->find($id);
        if (!$role) {
            return ApiResponse::error('Role tidak ditemukan.', 'ROLE_NOT_FOUND', 404);
        }

        $data = $request->validate([
            'user_type_id' => ['nullable', 'string', Rule::exists('access_user_types', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $role->fill([
            'user_type_id' => $data['user_type_id'] ?? null,
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return ApiResponse::ok([
            'role' => $this->serializeRole($role->fresh()->load('userType')),
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Role updated');
    }

    public function storeLevel(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('access_levels', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $level = AccessLevel::query()->create([
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return ApiResponse::ok([
            'level' => $this->serializeLevel($level),
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Level created');
    }

    public function updateLevel(Request $request, string $id)
    {
        $this->userManagement->ensureMasters();

        $level = AccessLevel::query()->find($id);
        if (!$level) {
            return ApiResponse::error('Level tidak ditemukan.', 'LEVEL_NOT_FOUND', 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $level->fill([
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return ApiResponse::ok([
            'level' => $this->serializeLevel($level->fresh()),
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Level updated');
    }

    public function storeUser(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')],
            'nisj' => ['nullable', 'string', 'max:100'],
            'assignment_role_title' => ['nullable', 'string', 'max:255'],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $result = $this->userManagement->createUser($request->user(), $data);
        $fresh = $result['user'];
        $employee = $fresh->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $fresh->outlet;
        $accessAssignment = $fresh->accessAssignment;
        $accessRole = $accessAssignment?->role;
        $accessLevel = $accessAssignment?->level;

        return ApiResponse::ok([
            'user' => [
                'id' => (string) $fresh->id,
                'name' => $fresh->name,
                'username' => $fresh->username,
                'nisj' => $fresh->nisj,
                'email' => $fresh->email,
                'is_active' => (bool) $fresh->is_active,
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
                    'role' => $accessRole ? [
                        'id' => (string) $accessRole->id,
                        'code' => (string) $accessRole->code,
                        'name' => (string) $accessRole->name,
                    ] : null,
                    'level' => $accessLevel ? [
                        'id' => (string) $accessLevel->id,
                        'code' => (string) $accessLevel->code,
                        'name' => (string) $accessLevel->name,
                    ] : null,
                ],
            ],
            'subject_access' => $result['subject_access'] ?? null,
            'subject_permissions' => $result['subject_permissions'] ?? [],
            'current_actor_session' => $result['current_actor_session'] ?? $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'User created');
    }


    public function updateUserAccess(Request $request, string $userId)
    {
        $this->userManagement->ensureMasters();

        $subject = User::query()->find($userId);
        if (!$subject) {
            return ApiResponse::error('User tidak ditemukan.', 'USER_NOT_FOUND', 404);
        }

        $data = $request->validate([
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
        ]);

        $result = $this->userManagement->updateUserAssignment(
            $request->user(),
            $subject,
            $data['access_role_id'],
            $data['access_level_id'] ?? null,
        );

        return ApiResponse::ok([
            'user_access' => [
                'access_role_id' => $data['access_role_id'],
                'access_level_id' => $data['access_level_id'] ?? null,
            ],
            'subject_access' => $result['access'] ?? null,
            'subject_permissions' => $result['permissions'] ?? [],
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'User access updated');
    }


    public function updateUserProfile(Request $request, string $userId)
    {
        $this->userManagement->ensureMasters();

        $subject = User::query()->with(['employee.assignment.outlet', 'outlet', 'roles', 'accessAssignment.role.userType', 'accessAssignment.level'])->find($userId);
        if (! $subject) {
            return ApiResponse::error('User tidak ditemukan.', 'USER_NOT_FOUND', 404);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($subject->id)],
            'nisj' => ['nullable', 'string', 'max:100'],
            'assignment_role_title' => ['nullable', 'string', 'max:255'],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $this->userManagement->updateUserProfile($request->user(), $subject, $data);
        $fresh = $result['user'];
        $employee = $fresh->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $fresh->outlet;
        $accessAssignment = $fresh->accessAssignment;
        $accessRole = $accessAssignment?->role;
        $accessLevel = $accessAssignment?->level;

        return ApiResponse::ok([
            'user' => [
                'id' => (string) $fresh->id,
                'name' => $fresh->name,
                'username' => $fresh->username,
                'nisj' => $fresh->nisj,
                'email' => $fresh->email,
                'is_active' => (bool) $fresh->is_active,
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
                    'role' => $accessRole ? [
                        'id' => (string) $accessRole->id,
                        'code' => (string) $accessRole->code,
                        'name' => (string) $accessRole->name,
                    ] : null,
                    'level' => $accessLevel ? [
                        'id' => (string) $accessLevel->id,
                        'code' => (string) $accessLevel->code,
                        'name' => (string) $accessLevel->name,
                    ] : null,
                ],
            ],
            'current_actor_session' => $result['current_actor_session'] ?? $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'User profile updated');
    }

    public function updateUserProvisionControl(Request $request, string $userId)
    {
        $this->userManagement->ensureMasters();

        $subject = User::query()->with(['employee.assignment.outlet', 'outlet'])->find($userId);
        if (! $subject) {
            return ApiResponse::error('User tidak ditemukan.', 'USER_NOT_FOUND', 404);
        }

        $resolvedOutlet = $subject->employee?->assignment?->outlet ?: $subject->outlet;
        if (! $resolvedOutlet) {
            return ApiResponse::error('User belum punya outlet assignment aktif sehingga tidak bisa diatur untuk provision.', 'USER_OUTLET_CONTEXT_MISSING', 422);
        }

        $data = $request->validate([
            'allow_provision' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $control = PosProvisionControl::query()->updateOrCreate(
            [
                'user_id' => (string) $subject->id,
                'outlet_id' => (string) $resolvedOutlet->id,
            ],
            [
                'allow_provision' => (bool) $data['allow_provision'],
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'updated_by_user_id' => (string) $request->user()->id,
            ]
        );

        return ApiResponse::ok([
            'provision_control' => $this->serializeProvisionControl($control->fresh(), $resolvedOutlet),
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], (bool) $control->allow_provision ? 'Provision user diaktifkan.' : 'Provision user diblokir.');
    }

    protected function resolveProvisionControlPayload(User $user, $outlet, $controlRows = null): array
    {
        $rows = $controlRows instanceof EloquentCollection ? $controlRows : new EloquentCollection(collect($controlRows)->all());
        $effectiveOutletId = $outlet?->id ? (string) $outlet->id : null;
        $match = null;
        if ($effectiveOutletId) {
            $match = $rows->first(fn (PosProvisionControl $row) => (string) $row->outlet_id === $effectiveOutletId);
        }
        $match = $match ?: $rows->first();

        if (! $match) {
            return [
                'allow_provision' => true,
                'notes' => null,
                'source' => 'default_allow',
                'outlet_id' => $effectiveOutletId,
                'outlet_code' => $outlet?->code ? (string) $outlet->code : null,
                'updated_at' => null,
            ];
        }

        return $this->serializeProvisionControl($match, $outlet);
    }

    protected function serializeProvisionControl(PosProvisionControl $control, $outlet = null): array
    {
        $resolvedOutlet = $outlet ?: $control->outlet;

        return [
            'id' => (string) $control->id,
            'allow_provision' => (bool) $control->allow_provision,
            'notes' => $control->notes,
            'source' => 'explicit_control',
            'outlet_id' => $control->outlet_id ? (string) $control->outlet_id : ($resolvedOutlet?->id ? (string) $resolvedOutlet->id : null),
            'outlet_code' => $resolvedOutlet?->code ? (string) $resolvedOutlet->code : null,
            'updated_at' => optional($control->updated_at)->toIso8601String(),
        ];
    }


    public function updatePortalPermission(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'portal_id' => ['required', 'string', Rule::exists('access_portals', 'id')],
            'can_view' => ['required', 'boolean'],
        ]);

        $syncedUsers = $this->userManagement->upsertPortalPermissions(
            $data['access_role_id'],
            $data['access_level_id'] ?? null,
            [[
                'portal_id' => $data['portal_id'],
                'can_view' => (bool) $data['can_view'],
            ]]
        );

        return ApiResponse::ok([
            'portal_permission' => $this->findPortalMatrixRow($data['access_role_id'], $data['access_level_id'] ?? null, $data['portal_id']),
            'synced_users' => $syncedUsers,
            'sync_summary' => ['synced_users' => $syncedUsers],
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Portal permission updated');
    }

    public function bulkPortalPermissions(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.portal_id' => ['required', 'string', Rule::exists('access_portals', 'id')],
            'rows.*.can_view' => ['required', 'boolean'],
        ]);

        $rows = array_map(fn (array $row) => [
            'portal_id' => $row['portal_id'],
            'can_view' => (bool) $row['can_view'],
        ], $data['rows']);

        $syncedUsers = $this->userManagement->upsertPortalPermissions(
            $data['access_role_id'],
            $data['access_level_id'] ?? null,
            $rows,
        );

        return ApiResponse::ok([
            'portal_permissions' => $this->matrixPortalRowsForScope($data['access_role_id'], $data['access_level_id'] ?? null),
            'synced_users' => $syncedUsers,
            'sync_summary' => ['synced_users' => $syncedUsers],
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Portal permissions updated');
    }

    public function updateMenuPermission(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'menu_id' => ['required', 'string', Rule::exists('access_menus', 'id')],
            'can_view' => ['required', 'boolean'],
            'can_create' => ['required', 'boolean'],
            'can_edit' => ['required', 'boolean'],
            'can_delete' => ['required', 'boolean'],
        ]);

        $syncedUsers = $this->userManagement->upsertMenuPermissions(
            $data['access_role_id'],
            $data['access_level_id'] ?? null,
            [[
                'menu_id' => $data['menu_id'],
                'can_view' => (bool) $data['can_view'],
                'can_create' => (bool) $data['can_create'],
                'can_edit' => (bool) $data['can_edit'],
                'can_delete' => (bool) $data['can_delete'],
            ]]
        );

        return ApiResponse::ok([
            'menu_permission' => $this->findMenuMatrixRow($data['access_role_id'], $data['access_level_id'] ?? null, $data['menu_id']),
            'synced_users' => $syncedUsers,
            'sync_summary' => ['synced_users' => $syncedUsers],
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Menu permission updated');
    }

    public function bulkMenuPermissions(Request $request)
    {
        $this->userManagement->ensureMasters();

        $data = $request->validate([
            'access_role_id' => ['required', 'string', Rule::exists('access_roles', 'id')],
            'access_level_id' => ['nullable', 'string', Rule::exists('access_levels', 'id')],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.menu_id' => ['required', 'string', Rule::exists('access_menus', 'id')],
            'rows.*.can_view' => ['required', 'boolean'],
            'rows.*.can_create' => ['required', 'boolean'],
            'rows.*.can_edit' => ['required', 'boolean'],
            'rows.*.can_delete' => ['required', 'boolean'],
        ]);

        $rows = array_map(fn (array $row) => [
            'menu_id' => $row['menu_id'],
            'can_view' => (bool) $row['can_view'],
            'can_create' => (bool) $row['can_create'],
            'can_edit' => (bool) $row['can_edit'],
            'can_delete' => (bool) $row['can_delete'],
        ], $data['rows']);

        $syncedUsers = $this->userManagement->upsertMenuPermissions(
            $data['access_role_id'],
            $data['access_level_id'] ?? null,
            $rows,
        );

        return ApiResponse::ok([
            'menu_permissions' => $this->matrixMenuRowsForScope($data['access_role_id'], $data['access_level_id'] ?? null),
            'synced_users' => $syncedUsers,
            'sync_summary' => ['synced_users' => $syncedUsers],
            'current_actor_session' => $this->userManagement->currentSessionSnapshot($request->user()),
        ], 'Menu permissions updated');
    }

    private function buildMasters(): array
    {
        $userTypes = AccessUserType::query()->orderBy('name')->get()->map(fn (AccessUserType $item) => [
            'id' => (string) $item->id,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'description' => $item->description,
            'is_active' => (bool) ($item->is_active ?? true),
        ])->values()->all();

        $roles = AccessRole::query()->with('userType')->orderBy('name')->get()->map(fn (AccessRole $item) => $this->serializeRole($item))->values()->all();
        $levels = AccessLevel::query()->orderBy('name')->get()->map(fn (AccessLevel $item) => $this->serializeLevel($item))->values()->all();
        $portals = AccessPortal::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (AccessPortal $item) => $this->serializePortal($item))->values()->all();
        $menus = AccessMenu::query()->with('portal')->orderBy('sort_order')->orderBy('name')->get()->map(fn (AccessMenu $item) => $this->serializeMenu($item))->values()->all();

        $outlets = Outlet::query()
            ->where('is_active', true)
            ->orderByRaw("case when lower(coalesce(type, '')) = 'outlet' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->map(fn (Outlet $item) => [
                'id' => (string) $item->id,
                'code' => (string) ($item->code ?? ''),
                'name' => (string) ($item->name ?? ''),
                'type' => $item->type,
                'timezone' => $item->timezone,
            ])
            ->values()
            ->all();

        return [
            'user_types' => $userTypes,
            'roles' => $roles,
            'levels' => $levels,
            'portals' => $portals,
            'menus' => $menus,
            'outlets' => $outlets,
            'spatie_permissions' => Permission::query()->orderBy('name')->pluck('name')->values()->all(),
        ];
    }

    private function buildMatrix(): array
    {
        return [
            'portal_permissions' => $this->matrixPortalRows(),
            'menu_permissions' => $this->matrixMenuRows(),
        ];
    }

    private function matrixPortalRows(): array
    {
        $portals = AccessPortal::query()->get()->keyBy('id');
        $roles = AccessRole::query()->get()->keyBy('id');
        $levels = AccessLevel::query()->get()->keyBy('id');

        return AccessRolePortalPermission::query()
            ->orderBy('access_role_id')
            ->orderBy('access_level_id')
            ->get()
            ->map(function (AccessRolePortalPermission $row) use ($portals, $roles, $levels) {
                $portal = $portals->get($row->portal_id);
                $role = $roles->get($row->access_role_id);
                $level = $row->access_level_id ? $levels->get($row->access_level_id) : null;

                return [
                    'id' => (string) $row->id,
                    'access_role_id' => (string) $row->access_role_id,
                    'access_level_id' => $row->access_level_id ? (string) $row->access_level_id : null,
                    'portal_id' => (string) $row->portal_id,
                    'can_view' => (bool) $row->can_view,
                    'portal_name' => $portal?->name,
                    'portal_code' => $portal?->code,
                    'role_name' => $role?->name,
                    'role_code' => $role?->code,
                    'level_name' => $level?->name,
                    'level_code' => $level?->code,
                ];
            })
            ->values()
            ->all();
    }

    private function matrixMenuRows(): array
    {
        $menus = AccessMenu::query()->with('portal')->get()->keyBy('id');
        $roles = AccessRole::query()->get()->keyBy('id');
        $levels = AccessLevel::query()->get()->keyBy('id');

        return AccessRoleMenuPermission::query()
            ->orderBy('access_role_id')
            ->orderBy('access_level_id')
            ->get()
            ->map(function (AccessRoleMenuPermission $row) use ($menus, $roles, $levels) {
                $menu = $menus->get($row->menu_id);
                $role = $roles->get($row->access_role_id);
                $level = $row->access_level_id ? $levels->get($row->access_level_id) : null;

                return [
                    'id' => (string) $row->id,
                    'access_role_id' => (string) $row->access_role_id,
                    'access_level_id' => $row->access_level_id ? (string) $row->access_level_id : null,
                    'menu_id' => (string) $row->menu_id,
                    'can_view' => (bool) $row->can_view,
                    'can_create' => (bool) $row->can_create,
                    'can_edit' => (bool) $row->can_edit,
                    'can_delete' => (bool) $row->can_delete,
                    'menu_name' => $menu?->name,
                    'menu_path' => $menu?->path,
                    'portal_id' => $menu?->portal_id ? (string) $menu->portal_id : null,
                    'portal_name' => $menu?->portal?->name,
                    'portal_code' => $menu?->portal?->code,
                    'role_name' => $role?->name,
                    'role_code' => $role?->code,
                    'level_name' => $level?->name,
                    'level_code' => $level?->code,
                ];
            })
            ->values()
            ->all();
    }

    private function matrixPortalRowsForScope(string $roleId, ?string $levelId): array
    {
        return array_values(array_filter(
            $this->matrixPortalRows(),
            fn (array $row) => $row['access_role_id'] === $roleId && (($row['access_level_id'] ?? null) === $levelId)
        ));
    }

    private function matrixMenuRowsForScope(string $roleId, ?string $levelId): array
    {
        return array_values(array_filter(
            $this->matrixMenuRows(),
            fn (array $row) => $row['access_role_id'] === $roleId && (($row['access_level_id'] ?? null) === $levelId)
        ));
    }

    private function findPortalMatrixRow(string $roleId, ?string $levelId, string $portalId): ?array
    {
        foreach ($this->matrixPortalRowsForScope($roleId, $levelId) as $row) {
            if (($row['portal_id'] ?? null) === $portalId) {
                return $row;
            }
        }

        return null;
    }

    private function findMenuMatrixRow(string $roleId, ?string $levelId, string $menuId): ?array
    {
        foreach ($this->matrixMenuRowsForScope($roleId, $levelId) as $row) {
            if (($row['menu_id'] ?? null) === $menuId) {
                return $row;
            }
        }

        return null;
    }

    private function serializeRole(AccessRole $item): array
    {
        return [
            'id' => (string) $item->id,
            'user_type_id' => $item->user_type_id ? (string) $item->user_type_id : null,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'description' => $item->description,
            'spatie_role_name' => $item->spatie_role_name,
            'is_active' => (bool) ($item->is_active ?? true),
            'user_type' => $item->userType ? [
                'id' => (string) $item->userType->id,
                'code' => (string) $item->userType->code,
                'name' => (string) $item->userType->name,
                'description' => $item->userType->description,
                'is_active' => (bool) ($item->userType->is_active ?? true),
            ] : null,
        ];
    }

    private function serializeLevel(AccessLevel $item): array
    {
        return [
            'id' => (string) $item->id,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'description' => $item->description,
            'is_active' => (bool) ($item->is_active ?? true),
        ];
    }

    private function serializePortal(AccessPortal $item): array
    {
        return [
            'id' => (string) $item->id,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'description' => $item->description,
            'sort_order' => (int) ($item->sort_order ?? 0),
            'is_active' => (bool) ($item->is_active ?? true),
        ];
    }

    private function serializeMenu(AccessMenu $item): array
    {
        return [
            'id' => (string) $item->id,
            'portal_id' => (string) $item->portal_id,
            'portal_code' => $item->portal?->code,
            'portal_name' => $item->portal?->name,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'path' => (string) $item->path,
            'sort_order' => (int) ($item->sort_order ?? 0),
            'permission_view' => $item->permission_view,
            'permission_create' => $item->permission_create,
            'permission_update' => $item->permission_update,
            'permission_delete' => $item->permission_delete,
            'is_active' => (bool) ($item->is_active ?? true),
        ];
    }
}
