<?php

namespace App\Services\HumanResource\AttendanceReportPolicies;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved Ijin/Cuti suppresses Alpha for a scheduled day with no attendance.
 * Actual attendance always wins because only the base state "alpha" is transformed.
 * Approved ranges are cached once per employee for the current HTTP request so recap
 * does not execute one leave query for every scheduled day.
 */
class ApprovedLeavePolicy
{
    private static ?int $requestObjectId = null;
    private static array $employeeLeaveCache = [];

    public function priority(): int { return 50; }

    public function apply(array $state, array $context): array
    {
        if (($state['calculation_status'] ?? null) !== 'alpha') return $state;
        if (! Schema::hasTable('HR_leave_requests')) return $state;

        $employee = $context['employee'] ?? null;
        $employeeId = is_object($employee) ? ($employee->id ?? null) : data_get($employee, 'id');
        $date = (string) ($context['date'] ?? $state['work_date'] ?? $state['date'] ?? '');
        if (! filled($employeeId) || $date === '') return $state;

        $leave = $this->approvedLeaveOn((string) $employeeId, $date);
        if (! $leave) return $state;

        $label = match ((string) $leave->type) {
            'sick' => 'Sakit', 'cuti' => 'Cuti', default => 'Izin',
        };

        $state['calculation_status'] = 'leave';
        $state['calculation_status_label'] = $label;
        $state['counts_as_work_day'] = false;
        $state['counts_as_alpha'] = false;
        $state['late_minutes'] = 0;
        $state['work_minutes'] = 0;
        $state['work_hours'] = 0;
        $state['work_hours_label'] = '0j 0m';
        $state['leave_request_id'] = (string) $leave->id;
        $state['leave_type'] = (string) $leave->type;
        $state['leave_type_label'] = $label;
        return $state;
    }

    private function approvedLeaveOn(string $employeeId, string $date): ?object
    {
        $requestId = app()->bound('request') ? spl_object_id(request()) : 0;
        if (self::$requestObjectId !== $requestId) {
            self::$requestObjectId = $requestId;
            self::$employeeLeaveCache = [];
        }

        if (! array_key_exists($employeeId, self::$employeeLeaveCache)) {
            self::$employeeLeaveCache[$employeeId] = DB::table('HR_leave_requests')
                ->where('employee_id', $employeeId)
                ->where('status', 'approved')
                ->orderBy('start_date')
                ->orderByRaw("CASE type WHEN 'sick' THEN 1 WHEN 'izin' THEN 2 WHEN 'cuti' THEN 3 ELSE 9 END")
                ->orderBy('created_at')
                ->get(['id', 'type', 'start_date', 'end_date'])
                ->all();
        }

        foreach (self::$employeeLeaveCache[$employeeId] as $leave) {
            if ((string) $leave->start_date <= $date && (string) $leave->end_date >= $date) return $leave;
        }
        return null;
    }
}
