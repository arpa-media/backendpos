<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\HrLeaveRequest;
use App\Models\HumanResource\HrAttendance;
use App\Models\HumanResource\HrShiftSchedule;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrManualEntryContextService
{
    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function employees(Request $request): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return [];

        return Employee::query()
            ->with(['user', 'assignment.outlet'])
            ->whereHas('assignment', fn ($q) => $q->whereIn('outlet_id', $allowed))
            ->orderBy('full_name')
            ->limit(5000)
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => (string) $employee->id,
                'user_id' => $employee->user_id ? (string) $employee->user_id : null,
                'nisj' => (string) ($employee->nisj ?? ''),
                'full_name' => (string) ($employee->full_name ?: $employee->user?->name ?: '-'),
                'employment_status' => (string) ($employee->employment_status ?? ''),
                'outlet_id' => $employee->assignment?->outlet_id ? (string) $employee->assignment->outlet_id : null,
                'outlet_name' => (string) ($employee->assignment?->outlet?->name ?? '-'),
            ])->values()->all();
    }

    public function employeeInScope(Request $request, string $employeeId): Employee
    {
        $allowed = $this->scope->allowedOutletIds($request);
        $employee = Employee::query()->with(['user', 'assignment.outlet'])
            ->whereKey($employeeId)
            ->whereHas('assignment', fn ($q) => $q->whereIn('outlet_id', $allowed))
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Employee tidak ditemukan atau berada di luar scope outlet Anda.']]);
        }
        return $employee;
    }

    public function resolveEmployeeUser(Employee $employee): User
    {
        $user = $employee->user;
        if (! $user && filled($employee->nisj)) {
            $needle = Str::lower(trim((string) $employee->nisj));
            $user = User::query()->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(TRIM(nisj)) = ?', [$needle])
                    ->orWhereRaw('LOWER(TRIM(username)) = ?', [$needle]);
            })->first();
        }
        if (! $user) {
            throw ValidationException::withMessages(['employee_id' => ['Employee belum memiliki akun user POS yang dapat ditautkan ke attendance.']]);
        }
        if (Schema::hasColumn('users', 'is_active') && ! (bool) ($user->is_active ?? true)) {
            throw ValidationException::withMessages(['employee_id' => ['Akun user employee sedang inactive.']]);
        }
        return $user;
    }

    public function scheduleFor(Employee $employee, string $businessDate): ?HrShiftSchedule
    {
        if (! Schema::hasTable('HR_shift_schedules')) return null;
        return HrShiftSchedule::query()->with('outlet')
            ->where('employee_id', (string) $employee->id)
            ->whereDate('work_date', $businessDate)
            ->first();
    }

    public function overlappingLeave(Employee $employee, ?User $user, string $startDate, string $endDate): array
    {
        if (! Schema::hasTable('HR_leave_requests')) return [];
        $query = HrLeaveRequest::query()
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->where(function ($q) use ($employee, $user): void {
                $q->where('employee_id', (string) $employee->id);
                if ($user) $q->orWhere('user_id', (string) $user->id);
            });

        return $query->orderBy('start_date')->get(['id', 'type', 'start_date', 'end_date', 'status'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'start_date' => optional($row->start_date)->format('Y-m-d'),
                'end_date' => optional($row->end_date)->format('Y-m-d'),
                'status' => (string) $row->status,
            ])->values()->all();
    }

    public function overlappingAttendance(Employee $employee, ?User $user, string $startDate, string $endDate): array
    {
        if (! Schema::hasTable('HR_attendances')) return [];
        $query = HrAttendance::query()
            ->where('record_status', '!=', 'cancelled')
            ->whereBetween('business_date', [$startDate, $endDate])
            ->where(function ($q) use ($employee, $user): void {
                $q->where('employee_id', (string) $employee->id);
                if ($user) $q->orWhere('user_id', (string) $user->id);
            });

        return $query->orderBy('business_date')->get(['id', 'business_date', 'record_status', 'source', 'checkin_at', 'checkout_at'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'business_date' => optional($row->business_date)->format('Y-m-d'),
                'record_status' => (string) $row->record_status,
                'source' => (string) $row->source,
            ])->values()->all();
    }

    public function currentQuota(Employee $employee, ?User $user): int
    {
        if (! Schema::hasTable('HR_squads')) return 0;
        $query = DB::table('HR_squads');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('deleted_at');

        $row = null;
        if ($user && Schema::hasColumn('HR_squads', 'user_id')) {
            $row = (clone $query)->where('user_id', (string) $user->id)->first(['leave_quota']);
        }
        if (! $row && filled($employee->nisj) && Schema::hasColumn('HR_squads', 'nisj')) {
            $row = (clone $query)->whereRaw('LOWER(TRIM(nisj)) = ?', [Str::lower(trim((string) $employee->nisj))])->first(['leave_quota']);
        }
        return max(0, (int) ($row->leave_quota ?? 0));
    }

    public function hasCapability(Request $request, string $permission, string $menuCode, string $matrixFlag): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (strtolower((string) ($menu['code'] ?? '')) !== strtolower($menuCode)) continue;
            return (bool) ($menu[$matrixFlag] ?? false);
        }
        return false;
    }

    public function safeTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Jakarta';
    }
}
