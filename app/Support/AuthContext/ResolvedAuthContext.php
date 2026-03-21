<?php

namespace App\Support\AuthContext;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;

class ResolvedAuthContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?Employee $employee,
        public readonly ?Assignment $assignment,
        public readonly ?Outlet $outlet,
        public readonly string $scopeMode,
        public readonly ?string $timezone,
        public readonly array $warnings = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'user' => [
                'id' => (string) $this->user->id,
                'hr_user_id' => $this->user->hr_user_id,
                'nisj' => $this->user->nisj,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_active' => (bool) $this->user->is_active,
            ],
            'employee' => $this->employee ? [
                'id' => (string) $this->employee->id,
                'hr_employee_id' => $this->employee->hr_employee_id,
                'nisj' => $this->employee->nisj,
                'full_name' => $this->employee->full_name,
                'employment_status' => $this->employee->employment_status,
            ] : null,
            'assignment' => $this->assignment ? [
                'id' => (string) $this->assignment->id,
                'hr_assignment_id' => $this->assignment->hr_assignment_id,
                'role_title' => $this->assignment->role_title,
                'is_primary' => (bool) $this->assignment->is_primary,
                'status' => $this->assignment->status,
            ] : null,
            'outlet' => $this->outlet ? [
                'id' => (string) $this->outlet->id,
                'hr_outlet_id' => $this->outlet->hr_outlet_id,
                'code' => $this->outlet->code,
                'name' => $this->outlet->name,
                'type' => $this->outlet->type,
                'timezone' => $this->outlet->timezone,
                'is_hr_source' => (bool) $this->outlet->is_hr_source,
                'is_compatibility_stub' => (bool) $this->outlet->is_compatibility_stub,
            ] : null,
            'scope_mode' => $this->scopeMode,
            'timezone' => $this->timezone,
            'warnings' => $this->warnings,
        ];
    }
}
