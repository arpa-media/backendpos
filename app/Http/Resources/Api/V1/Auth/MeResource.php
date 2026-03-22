<?php

namespace App\Http\Resources\Api\V1\Auth;

use App\Services\ReportPortalAccessService;
use App\Services\UserManagementService;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $this->resource;
        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $resolvedOutlet = $assignment?->outlet ?: $user->outlet;
        $authContext = app(UserAuthContextResolver::class)->resolve($user);
        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'nisj' => $user->nisj ? (string) $user->nisj : null,
            'username' => $user->username ? (string) $user->username : null,
            'email' => (string) $user->email,
            'is_active' => (bool) ($user->is_active ?? true),
            'outlet_id' => $resolvedOutlet?->id ? (string) $resolvedOutlet->id : null,
            'outlet_code' => $resolvedOutlet?->code ? (string) $resolvedOutlet->code : null,
            'outlet_type' => $resolvedOutlet?->type ? (string) $resolvedOutlet->type : null,
            'scope_locked' => (bool) ($authContext['scope_locked'] ?? false),
            'can_adjust_scope' => (bool) ($authContext['can_adjust_scope'] ?? false),
            'outlet' => $resolvedOutlet ? [
                'id' => (string) $resolvedOutlet->id,
                'code' => (string) $resolvedOutlet->code,
                'name' => (string) $resolvedOutlet->name,
                'type' => (string) ($resolvedOutlet->type ?? 'outlet'),
                'timezone' => (string) ($resolvedOutlet->timezone ?? 'Asia/Jakarta'),
            ] : null,
            'employee' => $employee ? [
                'id' => (string) $employee->id,
                'hr_employee_id' => $employee->hr_employee_id,
                'full_name' => $employee->full_name,
                'nickname' => $employee->nickname,
                'employment_status' => $employee->employment_status,
            ] : null,
            'assignment' => $assignment ? [
                'id' => (string) $assignment->id,
                'hr_assignment_id' => $assignment->hr_assignment_id,
                'role_title' => $assignment->role_title,
                'status' => $assignment->status,
                'is_primary' => (bool) $assignment->is_primary,
            ] : null,
            'auth_context' => $authContext,
            'legacy_bridge' => [
                'user_outlet_id' => $user->outlet_id ? (string) $user->outlet_id : null,
                'auth_source' => $authContext['auth_source'] ?? 'none',
                'requires_legacy_bridge' => (bool) ($authContext['requires_legacy_bridge'] ?? false),
            ],
            'roles' => $user->roles?->pluck('name')->values() ?? [],
            'permissions' => $snapshot['permissions'] ?? $user->getAllPermissions()->pluck('name')->values(),
            'access' => $snapshot['access'] ?? ['portals' => [], 'menus' => []],
            'visible_backoffice_portals' => $snapshot['visible_backoffice_portals'] ?? [],
            'can_edit_user_management' => (bool) ($snapshot['can_edit_user_management'] ?? false),
            'report_access' => $snapshot['report_access'] ?? app(ReportPortalAccessService::class)->snapshot($user),
        ];
    }
}
