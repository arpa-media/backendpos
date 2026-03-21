<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class PosAuthContextSnapshotBuilder
{
    public const VERSION = 2;
    public const CONTRACT = 'hr-pos-auth-context';

    /**
     * @param  array<string, mixed>  $options
     */
    public function build(array $options = []): array
    {
        $activeOnly = (bool) ($options['active_only'] ?? false);
        $since = $this->normalizeSince($options['since'] ?? null);

        $employeesQuery = Employee::query()
            ->with(['user:id,username,email,password,is_active,updated_at'])
            ->orderBy('updated_at');

        if ($activeOnly) {
            $employeesQuery->where('employment_status', 'active');
        }

        if ($since) {
            $employeesQuery->where(function ($query) use ($since) {
                $query->where('employees.updated_at', '>=', $since)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('updated_at', '>=', $since));
            });
        }

        $employees = $employeesQuery->get();

        $employeeIds = $employees->pluck('id')->filter()->values();
        $userIds = $employees->pluck('user_id')->filter()->unique()->values();

        $assignmentsQuery = Assignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('updated_at');

        if ($activeOnly) {
            $assignmentsQuery->where('status', 'active');
        }

        if ($since) {
            $assignmentsQuery->where('updated_at', '>=', $since);
        }

        $assignments = $assignmentsQuery->get();

        $referencedOutletIds = $assignments
            ->pluck('outlet_id')
            ->filter()
            ->unique()
            ->values();

        $outletsQuery = Outlet::query()->orderBy('updated_at');

        if ($referencedOutletIds->isNotEmpty()) {
            $outletsQuery->whereIn('id', $referencedOutletIds);
        }

        if ($since && $referencedOutletIds->isEmpty()) {
            $outletsQuery->where('updated_at', '>=', $since);
        }

        $outlets = $outletsQuery->get();

        $usersQuery = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('updated_at');

        if ($activeOnly) {
            $usersQuery->where('is_active', true);
        }

        if ($since) {
            $usersQuery->where('updated_at', '>=', $since);
        }

        $users = $usersQuery->get();

        $serializedOutlets = $this->serializeOutlets($outlets);
        $serializedUsers = $this->serializeUsers($users);
        $serializedEmployees = $this->serializeEmployees($employees);
        $serializedAssignments = $this->serializeAssignments($assignments);

        $validation = $this->validateMinimumRelations($users, $employees, $assignments, $outlets);

        $snapshot = [
            'meta' => [
                'source' => 'hr',
                'contract' => self::CONTRACT,
                'version' => self::VERSION,
                'exported_at' => now()->toIso8601String(),
                'app_timezone' => config('app.timezone', 'UTC'),
                'filters' => [
                    'active_only' => $activeOnly,
                    'since' => $since?->toIso8601String(),
                ],
                'entity_counts' => [
                    'outlets' => count($serializedOutlets),
                    'users' => count($serializedUsers),
                    'employees' => count($serializedEmployees),
                    'assignments' => count($serializedAssignments),
                ],
                'validation' => $validation,
                'checksums' => [
                    'outlets' => $this->checksum($serializedOutlets),
                    'users' => $this->checksum($serializedUsers),
                    'employees' => $this->checksum($serializedEmployees),
                    'assignments' => $this->checksum($serializedAssignments),
                ],
            ],
            'outlets' => $serializedOutlets,
            'users' => $serializedUsers,
            'employees' => $serializedEmployees,
            'assignments' => $serializedAssignments,
        ];

        $snapshot['meta']['checksums']['snapshot'] = $this->checksum([
            'meta' => [
                'contract' => $snapshot['meta']['contract'],
                'version' => $snapshot['meta']['version'],
                'filters' => $snapshot['meta']['filters'],
            ],
            'outlets' => $snapshot['outlets'],
            'users' => $snapshot['users'],
            'employees' => $snapshot['employees'],
            'assignments' => $snapshot['assignments'],
        ]);

        return $snapshot;
    }

    public function toJson(array $snapshot, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($snapshot, $flags | JSON_THROW_ON_ERROR);
    }

    public function write(array $snapshot, ?string $path = null, bool $pretty = true): string
    {
        $path = $path ?: $this->defaultPath();
        $directory = dirname($path);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, $this->toJson($snapshot, $pretty));

        return $path;
    }

    public function defaultPath(): string
    {
        return storage_path('app/exports/hr-pos-sync-' . now()->format('Ymd-His') . '.json');
    }

    protected function serializeUsers(Collection $users): array
    {
        return $users->map(function (User $user) {
            return [
                'hr_user_id' => (string) $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'password' => $user->password,
                'is_active' => (bool) ($user->is_active ?? false),
                'updated_at' => $this->serializeDateTime($user->updated_at),
            ];
        })->values()->all();
    }

    protected function serializeEmployees(Collection $employees): array
    {
        return $employees->map(function (Employee $employee) {
            return [
                'hr_employee_id' => (string) $employee->id,
                'hr_user_id' => $employee->user_id ? (string) $employee->user_id : null,
                'nisj' => $employee->nisj,
                'nik' => $employee->nik,
                'full_name' => $employee->full_name,
                'nickname' => $employee->nickname,
                'tier' => $employee->tier,
                'employment_type' => $employee->employment_type,
                'employment_status' => $employee->employment_status,
                'join_date' => $this->serializeDate($employee->join_date),
                'resign_date' => $this->serializeDate($employee->resign_date),
                'assignment_id' => $employee->assignment_id ? (string) $employee->assignment_id : null,
                'updated_at' => $this->serializeDateTime($employee->updated_at),
            ];
        })->values()->all();
    }

    protected function serializeAssignments(Collection $assignments): array
    {
        return $assignments->map(function (Assignment $assignment) {
            return [
                'hr_assignment_id' => (string) $assignment->id,
                'hr_employee_id' => $assignment->employee_id ? (string) $assignment->employee_id : null,
                'hr_outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
                'role_title' => $assignment->role_title,
                'start_date' => $this->serializeDate($assignment->start_date),
                'end_date' => $this->serializeDate($assignment->end_date),
                'is_primary' => (bool) ($assignment->is_primary ?? false),
                'status' => $assignment->status,
                'created_by' => $assignment->created_by ? (string) $assignment->created_by : null,
                'updated_at' => $this->serializeDateTime($assignment->updated_at),
            ];
        })->values()->all();
    }

    protected function serializeOutlets(Collection $outlets): array
    {
        return $outlets->map(function (Outlet $outlet) {
            return [
                'hr_outlet_id' => (string) $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'type' => $outlet->type,
                'timezone' => $outlet->timezone,
                'address' => $outlet->address,
                'phone' => $outlet->phone ?? null,
                'latitude' => $outlet->latitude,
                'longitude' => $outlet->longitude,
                'radius_m' => $outlet->radius_m,
                'updated_at' => $this->serializeDateTime($outlet->updated_at),
            ];
        })->values()->all();
    }

    protected function validateMinimumRelations(Collection $users, Collection $employees, Collection $assignments, Collection $outlets): array
    {
        $userIds = $users->pluck('id')->map(fn ($value) => (string) $value)->flip();
        $employeeIds = $employees->pluck('id')->map(fn ($value) => (string) $value)->flip();
        $assignmentIds = $assignments->pluck('id')->map(fn ($value) => (string) $value)->flip();
        $outletIds = $outlets->pluck('id')->map(fn ($value) => (string) $value)->flip();

        $duplicateNisj = $employees
            ->filter(fn (Employee $employee) => filled($employee->nisj))
            ->groupBy(fn (Employee $employee) => trim((string) $employee->nisj))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group, string $nisj) => [
                'nisj' => $nisj,
                'employee_ids' => $group->pluck('id')->map(fn ($value) => (string) $value)->values()->all(),
            ])
            ->values()
            ->all();

        $employeesWithoutUser = $employees
            ->filter(fn (Employee $employee) => ! $employee->user_id || ! $userIds->has((string) $employee->user_id))
            ->map(fn (Employee $employee) => [
                'hr_employee_id' => (string) $employee->id,
                'hr_user_id' => $employee->user_id ? (string) $employee->user_id : null,
                'nisj' => $employee->nisj,
            ])
            ->values()
            ->all();

        $employeesWithMissingAssignment = $employees
            ->filter(fn (Employee $employee) => filled($employee->assignment_id) && ! $assignmentIds->has((string) $employee->assignment_id))
            ->map(fn (Employee $employee) => [
                'hr_employee_id' => (string) $employee->id,
                'assignment_id' => (string) $employee->assignment_id,
                'nisj' => $employee->nisj,
            ])
            ->values()
            ->all();

        $assignmentsWithoutEmployee = $assignments
            ->filter(fn (Assignment $assignment) => ! $assignment->employee_id || ! $employeeIds->has((string) $assignment->employee_id))
            ->map(fn (Assignment $assignment) => [
                'hr_assignment_id' => (string) $assignment->id,
                'hr_employee_id' => $assignment->employee_id ? (string) $assignment->employee_id : null,
            ])
            ->values()
            ->all();

        $assignmentsWithoutOutlet = $assignments
            ->filter(fn (Assignment $assignment) => ! $assignment->outlet_id || ! $outletIds->has((string) $assignment->outlet_id))
            ->map(fn (Assignment $assignment) => [
                'hr_assignment_id' => (string) $assignment->id,
                'hr_outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
            ])
            ->values()
            ->all();

        $primaryAssignmentConflicts = $assignments
            ->filter(fn (Assignment $assignment) => (bool) $assignment->is_primary)
            ->groupBy(fn (Assignment $assignment) => (string) $assignment->employee_id)
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group, string $employeeId) => [
                'hr_employee_id' => $employeeId,
                'hr_assignment_ids' => $group->pluck('id')->map(fn ($value) => (string) $value)->values()->all(),
            ])
            ->values()
            ->all();

        $warningCounts = [
            'duplicate_nisj' => count($duplicateNisj),
            'employees_without_user' => count($employeesWithoutUser),
            'employees_with_missing_assignment' => count($employeesWithMissingAssignment),
            'assignments_without_employee' => count($assignmentsWithoutEmployee),
            'assignments_without_outlet' => count($assignmentsWithoutOutlet),
            'multiple_primary_assignments' => count($primaryAssignmentConflicts),
        ];

        return [
            'is_valid' => array_sum($warningCounts) === 0,
            'warning_counts' => $warningCounts,
            'warnings' => [
                'duplicate_nisj' => $duplicateNisj,
                'employees_without_user' => $employeesWithoutUser,
                'employees_with_missing_assignment' => $employeesWithMissingAssignment,
                'assignments_without_employee' => $assignmentsWithoutEmployee,
                'assignments_without_outlet' => $assignmentsWithoutOutlet,
                'multiple_primary_assignments' => $primaryAssignmentConflicts,
            ],
        ];
    }

    protected function serializeDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    protected function serializeDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    protected function checksum(mixed $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function normalizeSince(mixed $since): ?Carbon
    {
        if (! is_string($since) || trim($since) === '') {
            return null;
        }

        return Carbon::parse($since)->startOfSecond();
    }
}
