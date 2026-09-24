<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\HumanResource\HrAttendance;
use App\Models\HumanResource\HrAttendanceManualLog;
use App\Models\HumanResource\HrShiftSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrDailyReportManualAttendanceI16Service
{
    public const SOURCE = 'MANUAL_HR';

    public function __construct(
        private readonly HrManualEntryContextService $context,
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrAttendanceCalculationService $calculation,
    ) {}

    public function options(Request $request): array
    {
        return [
            'employees' => $this->context->employees($request),
            'outlets' => $this->scope->options($request),
            'capabilities' => [
                'can_create' => $this->canManage($request),
                'can_correct' => $this->canManage($request),
            ],
        ];
    }

    public function context(Request $request, string $employeeId, string $businessDate): array
    {
        $employee = $this->employeeForDateInScope($request, $employeeId, $businessDate);
        $user = $this->context->resolveEmployeeUser($employee);
        $schedule = $this->context->scheduleFor($employee, $businessDate);
        $leaveConflicts = $this->context->overlappingLeave($employee, $user, $businessDate, $businessDate);

        $attendance = HrAttendance::query()
            ->whereDate('business_date', $businessDate)
            ->where(function ($q) use ($employee, $user): void {
                $q->where('employee_id', (string) $employee->id)
                    ->orWhere('user_id', (string) $user->id);
            })
            ->orderByDesc('created_at')
            ->first();

        $schedulePayload = $this->schedulePayload($schedule);
        $attendancePayload = $attendance ? $this->attendancePayload($attendance, $schedulePayload['timezone'] ?? null) : null;
        $workingSchedule = $schedule && strtolower((string) $schedule->schedule_type) === 'shift';
        $inScope = $schedule?->outlet_id ? $this->scope->isOutletAllowed($request, (string) $schedule->outlet_id) : false;

        return [
            'employee' => $this->employeePayload($employee),
            'schedule' => $schedulePayload,
            'attendance_conflict' => $attendancePayload,
            'leave_conflicts' => $leaveConflicts,
            'can_create' => $workingSchedule && $inScope && ! $attendance && $leaveConflicts === [],
            'can_correct' => $workingSchedule && $inScope && (bool) $attendance && $leaveConflicts === [],
            'recommended_mode' => $attendance ? 'correct' : 'create',
            'source' => self::SOURCE,
        ];
    }

    public function save(Request $request, array $data): array
    {
        if (! $this->canManage($request)) {
            abort(403, 'Anda tidak memiliki akses Edit Daily Report / Input Absen Manual.');
        }

        $mode = strtolower(trim((string) ($data['mode'] ?? 'create')));
        if (! in_array($mode, ['create', 'correct'], true)) {
            throw ValidationException::withMessages(['mode' => ['Mode harus create atau correct.']]);
        }

        $businessDate = (string) $data['business_date'];
        $employee = $this->employeeForDateInScope($request, (string) $data['employee_id'], $businessDate);
        $user = $this->context->resolveEmployeeUser($employee);
        $schedule = $this->context->scheduleFor($employee, $businessDate);
        $this->assertWorkingSchedule($schedule);

        $outlet = $schedule?->outlet ?: $employee->assignment?->outlet;
        if (! $outlet) {
            throw ValidationException::withMessages(['employee_id' => ['Penugasan/schedule tidak memiliki outlet.']]);
        }
        if (! $this->scope->isOutletAllowed($request, (string) $outlet->id)) {
            abort(403, 'Penugasan attendance berada di luar scope Anda.');
        }

        $timezone = $this->context->safeTimezone($schedule?->outlet_timezone_snapshot ?: $outlet->timezone);
        $checkin = $this->parseLocal((string) $data['checkin_local'], $timezone, 'checkin_local');
        $checkout = $this->parseLocal((string) $data['checkout_local'], $timezone, 'checkout_local');
        if ($checkin->toDateString() !== $businessDate) {
            throw ValidationException::withMessages(['checkin_local' => ['Tanggal Absen Datang harus sama dengan tanggal Daily Report.']]);
        }
        $this->assertCheckoutRange($checkin, $checkout);

        $leave = $this->context->overlappingLeave($employee, $user, $businessDate, $businessDate);
        if ($leave !== []) {
            throw ValidationException::withMessages(['business_date' => ['Tidak dapat mengakui attendance manual karena terdapat Ijin/Cuti yang overlap.']]);
        }

        $reason = trim((string) $data['reason']);
        $note = trim((string) ($data['note'] ?? ''));
        $actor = $request->user();

        if ($mode === 'correct' && ! (bool) ($data['correction_confirmed'] ?? false)) {
            throw ValidationException::withMessages(['correction_confirmed' => ['Konfirmasi koreksi attendance wajib dicentang.']]);
        }

        try {
            $attendance = DB::transaction(function () use (
                $mode, $data, $employee, $user, $businessDate, $schedule, $outlet, $timezone,
                $checkin, $checkout, $reason, $note, $actor
            ): HrAttendance {
                $existing = HrAttendance::query()
                    ->whereDate('business_date', $businessDate)
                    ->where(function ($q) use ($employee, $user): void {
                        $q->where('employee_id', (string) $employee->id)
                            ->orWhere('user_id', (string) $user->id);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($mode === 'create' && $existing) {
                    throw ValidationException::withMessages([
                        'business_date' => ['Attendance pada tanggal tersebut sudah ada. Gunakan mode Koreksi secara eksplisit bila memang perlu diperbaiki.'],
                    ]);
                }
                if ($mode === 'correct' && ! $existing) {
                    throw ValidationException::withMessages(['business_date' => ['Tidak ada attendance existing yang dapat dikoreksi pada tanggal tersebut.']]);
                }
                if ($mode === 'correct' && filled($data['attendance_id'] ?? null) && (string) $existing->id !== (string) $data['attendance_id']) {
                    throw ValidationException::withMessages(['attendance_id' => ['Attendance existing berubah. Muat ulang Daily Report lalu ulangi koreksi.']]);
                }

                $before = $existing ? $this->snapshot($existing) : null;
                $attributes = $this->attendanceAttributes(
                    $employee,
                    $user,
                    $schedule,
                    $outlet,
                    $businessDate,
                    $timezone,
                    $checkin,
                    $checkout,
                    $actor,
                    $reason,
                    $note,
                    $existing
                );

                if ($mode === 'create') {
                    $row = HrAttendance::query()->create($attributes);
                } else {
                    $row = $existing;
                    $row->forceFill($attributes)->save();
                }

                $row = $this->calculation->recalculate($row);
                $action = $mode === 'create' ? 'daily_report_manual_created' : 'daily_report_manual_corrected';
                $this->writeAudit($row, $action, $actor, $reason, $note, $before, $this->snapshot($row));

                return $row;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'hr_att_user_date_uq')) {
                throw ValidationException::withMessages([
                    'business_date' => ['Attendance pada tanggal tersebut sudah ada. Gunakan mode Koreksi bila perubahan memang diperlukan.'],
                ]);
            }
            throw $e;
        }

        return $this->present($attendance->fresh(['employee', 'user']));
    }


    private function employeeForDateInScope(Request $request, string $employeeId, string $businessDate): Employee
    {
        $employee = Employee::query()
            ->with(['user', 'assignment.outlet'])
            ->whereKey($employeeId)
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Employee tidak ditemukan.']]);
        }

        // Historical Daily Report must follow the assignment snapshot on the selected date,
        // not only today's assignment. This lets HR correct a past attendance after a Squad
        // has moved outlet, while outlet_scope still gates the scheduled outlet.
        $schedule = $this->context->scheduleFor($employee, $businessDate);
        $outletId = $schedule?->outlet_id ?: $employee->assignment?->outlet_id;
        if (! $outletId || ! $this->scope->isOutletAllowed($request, (string) $outletId)) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee/schedule pada tanggal tersebut berada di luar scope outlet Anda.'],
            ]);
        }

        return $employee;
    }

    private function canManage(Request $request): bool
    {
        return $this->context->hasCapability(
            $request,
            'hr.attendance.daily-report.manual.create',
            'hr-attendance-daily-report',
            'can_edit'
        ) || $this->context->hasCapability(
            $request,
            'hr.attendance.recalculate',
            'hr-attendance-daily-report',
            'can_edit'
        );
    }

    private function attendanceAttributes(
        Employee $employee,
        User $user,
        HrShiftSchedule $schedule,
        object $outlet,
        string $businessDate,
        string $timezone,
        CarbonImmutable $checkin,
        CarbonImmutable $checkout,
        User $actor,
        string $reason,
        string $note,
        ?HrAttendance $existing
    ): array {
        $now = now();

        return [
            'user_id' => (string) $user->id,
            'squad_id' => $this->squadId($user),
            'employee_id' => (string) $employee->id,
            'assignment_outlet_id' => (string) $outlet->id,
            'shift_schedule_id' => (string) $schedule->id,
            'business_date' => $businessDate,
            'attendance_timezone' => $timezone,
            'assignment_timezone' => $timezone,
            'device_timezone' => $timezone,
            'record_status' => 'complete',

            'checkin_at' => $this->toUtcString($checkin),
            'checkin_outlet_id' => (string) $outlet->id,
            'checkin_outlet_name' => (string) $outlet->name,
            'checkin_outlet_timezone' => $timezone,
            'checkin_lat' => null,
            'checkin_lng' => null,
            'checkin_accuracy_m' => null,
            'checkin_distance_m' => null,
            'checkin_radius_m' => null,
            'checkin_radius_delta_m' => null,
            'checkin_inside_radius' => true,
            'checkin_location_status' => 'manual_hr',
            'checkin_mode' => 'manual_hr',
            'checkin_note' => $reason,
            'duty_location' => null,
            'checkin_photo_path' => null,
            'checkin_camera_status' => 'manual_hr',
            'checkin_camera_note' => $note !== '' ? $note : null,

            'checkout_at' => $this->toUtcString($checkout),
            'checkout_business_date' => $checkout->toDateString(),
            'checkout_timezone' => $timezone,
            'checkout_outlet_id' => (string) $outlet->id,
            'checkout_outlet_name' => (string) $outlet->name,
            'checkout_outlet_timezone' => $timezone,
            'checkout_lat' => null,
            'checkout_lng' => null,
            'checkout_accuracy_m' => null,
            'checkout_distance_m' => null,
            'checkout_radius_m' => null,
            'checkout_radius_delta_m' => null,
            'checkout_inside_radius' => true,
            'checkout_location_status' => 'manual_hr',
            'checkout_mode' => 'manual_hr',
            'checkout_note' => $reason,
            'checkout_duty_location' => null,
            'checkout_photo_path' => null,
            'checkout_camera_status' => 'manual_hr',
            'checkout_camera_note' => $note !== '' ? $note : null,

            'approval_required' => false,
            'approval_status' => 'approved_manual_hr',
            'calculation_eligible' => true,
            'exception_flags' => [],
            'checkout_exception_flags' => [],
            'device_info' => 'Backoffice Daily Report manual attendance (I16)',
            'source' => self::SOURCE,
            'manual_created_by_user_id' => $existing?->manual_created_by_user_id ?: (string) $actor->id,
            'manual_updated_by_user_id' => (string) $actor->id,
            'manual_reason' => $reason,
            'manual_approval_note' => $note !== '' ? $note : 'Input manual dari Daily Report',
            'manual_created_at' => $existing?->manual_created_at ?: $now,
            'manual_updated_at' => $now,
        ];
    }

    private function assertWorkingSchedule(?HrShiftSchedule $schedule): void
    {
        if (! $schedule) {
            throw ValidationException::withMessages(['business_date' => ['Schedule Squad belum dimapping pada tanggal tersebut.']]);
        }
        if (strtolower((string) $schedule->schedule_type) !== 'shift') {
            throw ValidationException::withMessages(['business_date' => ['Schedule berstatus OFF. Attendance manual tidak dapat dibuat.']]);
        }
    }

    private function parseLocal(string $value, string $timezone, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Tanggal/jam tidak valid.']]);
        }
    }

    private function assertCheckoutRange(CarbonImmutable $checkin, CarbonImmutable $checkout): void
    {
        if ($checkout->lte($checkin)) {
            throw ValidationException::withMessages(['checkout_local' => ['Jam Pulang harus setelah Jam Datang.']]);
        }
        if ($checkout->gt($checkin->addHours(36))) {
            throw ValidationException::withMessages(['checkout_local' => ['Rentang Datang–Pulang maksimum 36 jam.']]);
        }
    }

    private function schedulePayload(?HrShiftSchedule $schedule): ?array
    {
        if (! $schedule) return null;
        return [
            'id' => (string) $schedule->id,
            'schedule_type' => (string) $schedule->schedule_type,
            'shift_name' => (string) ($schedule->shift_name_snapshot ?: '-'),
            'start_time' => $schedule->start_time_snapshot,
            'end_time' => $schedule->end_time_snapshot,
            'is_overnight' => (bool) $schedule->is_overnight_snapshot,
            'outlet_id' => $schedule->outlet_id ? (string) $schedule->outlet_id : null,
            'outlet_name' => (string) ($schedule->outlet_name_snapshot ?: $schedule->outlet?->name ?: '-'),
            'timezone' => $this->context->safeTimezone($schedule->outlet_timezone_snapshot ?: $schedule->outlet?->timezone),
        ];
    }

    private function attendancePayload(HrAttendance $attendance, ?string $timezone): array
    {
        $timezone = $this->context->safeTimezone($timezone ?: $attendance->attendance_timezone);
        $local = static function ($raw) use ($timezone): ?string {
            if (! $raw) return null;
            try {
                return CarbonImmutable::parse((string) $raw, 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'id' => (string) $attendance->id,
            'source' => (string) $attendance->source,
            'record_status' => (string) $attendance->record_status,
            'approval_status' => (string) $attendance->approval_status,
            'checkin_local' => $local($attendance->getRawOriginal('checkin_at')),
            'checkout_local' => $local($attendance->getRawOriginal('checkout_at')),
            'manual_reason' => (string) ($attendance->manual_reason ?? ''),
            'manual_note' => (string) ($attendance->manual_approval_note ?? ''),
            'late_minutes' => $attendance->late_minutes !== null ? (int) $attendance->late_minutes : null,
            'work_minutes' => $attendance->work_minutes !== null ? (int) $attendance->work_minutes : null,
        ];
    }

    private function employeePayload(Employee $employee): array
    {
        return [
            'id' => (string) $employee->id,
            'nisj' => (string) ($employee->nisj ?? ''),
            'full_name' => (string) ($employee->full_name ?: $employee->user?->name ?: '-'),
            'outlet_id' => $employee->assignment?->outlet_id ? (string) $employee->assignment->outlet_id : null,
            'outlet_name' => (string) ($employee->assignment?->outlet?->name ?? '-'),
        ];
    }

    private function present(HrAttendance $row): array
    {
        $timezone = $this->context->safeTimezone($row->attendance_timezone);
        $local = static function ($raw) use ($timezone): ?string {
            if (! $raw) return null;
            return CarbonImmutable::parse((string) $raw, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i');
        };

        return [
            'id' => (string) $row->id,
            'employee_id' => $row->employee_id ? (string) $row->employee_id : null,
            'full_name' => (string) ($row->employee?->full_name ?: $row->user?->name ?: '-'),
            'nisj' => (string) ($row->employee?->nisj ?: $row->user?->nisj ?: ''),
            'business_date' => optional($row->business_date)->format('Y-m-d'),
            'source' => (string) $row->source,
            'checkin_local' => $local($row->getRawOriginal('checkin_at')),
            'checkout_local' => $local($row->getRawOriginal('checkout_at')),
            'late_minutes' => (int) ($row->late_minutes ?? 0),
            'work_minutes' => (int) ($row->work_minutes ?? 0),
            'calculation_status' => (string) ($row->calculation_status ?? ''),
            'calculation_eligible' => (bool) $row->calculation_eligible,
            'message' => 'Attendance Manual HR tersimpan dan kalkulasi Daily Report sudah diperbarui.',
        ];
    }

    private function snapshot(HrAttendance $row): array
    {
        return [
            'id' => (string) $row->id,
            'employee_id' => $row->employee_id ? (string) $row->employee_id : null,
            'user_id' => $row->user_id ? (string) $row->user_id : null,
            'business_date' => optional($row->business_date)->format('Y-m-d'),
            'assignment_outlet_id' => $row->assignment_outlet_id ? (string) $row->assignment_outlet_id : null,
            'shift_schedule_id' => $row->shift_schedule_id ? (string) $row->shift_schedule_id : null,
            'record_status' => (string) $row->record_status,
            'source' => (string) $row->source,
            'checkin_at' => $row->getRawOriginal('checkin_at'),
            'checkout_at' => $row->getRawOriginal('checkout_at'),
            'checkin_mode' => (string) ($row->checkin_mode ?? ''),
            'checkout_mode' => (string) ($row->checkout_mode ?? ''),
            'approval_required' => (bool) $row->approval_required,
            'approval_status' => (string) $row->approval_status,
            'calculation_eligible' => (bool) $row->calculation_eligible,
            'late_minutes' => $row->late_minutes !== null ? (int) $row->late_minutes : null,
            'work_minutes' => $row->work_minutes !== null ? (int) $row->work_minutes : null,
            'manual_reason' => (string) ($row->manual_reason ?? ''),
            'manual_approval_note' => (string) ($row->manual_approval_note ?? ''),
        ];
    }

    private function writeAudit(
        HrAttendance $row,
        string $action,
        User $actor,
        string $reason,
        string $note,
        ?array $before,
        array $after
    ): void {
        if (! Schema::hasTable('HR_attendance_manual_logs')) {
            throw new \RuntimeException('HR_attendance_manual_logs belum tersedia. Jalankan migration Iterasi 16.');
        }

        HrAttendanceManualLog::query()->create([
            'attendance_id' => (string) $row->id,
            'action' => $action,
            'actor_user_id' => (string) $actor->id,
            'actor_name_snapshot' => (string) ($actor->name ?: $actor->username ?: $actor->nisj ?: $actor->id),
            'reason' => $reason,
            'approval_note' => $note !== '' ? $note : 'Input melalui Daily Report I16',
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }

    private function toUtcString(CarbonImmutable $value): string
    {
        return $value->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    private function squadId(User $user): mixed
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasColumn('HR_squads', 'user_id')) return null;
        $query = DB::table('HR_squads')->where('user_id', (string) $user->id);
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('deleted_at');
        return $query->value('id');
    }
}
