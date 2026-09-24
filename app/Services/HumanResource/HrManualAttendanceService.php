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

class HrManualAttendanceService
{
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
                'can_create' => $this->context->hasCapability($request, 'hr.attendance.manual.create', 'hr-approval-attendance', 'can_create'),
                'can_update' => $this->context->hasCapability($request, 'hr.attendance.manual.update', 'hr-approval-attendance', 'can_edit'),
            ],
        ];
    }

    public function context(Request $request, string $employeeId, string $businessDate): array
    {
        $employee = $this->context->employeeInScope($request, $employeeId);
        $user = $this->context->resolveEmployeeUser($employee);
        $schedule = $this->context->scheduleFor($employee, $businessDate);
        $attendance = $this->context->overlappingAttendance($employee, $user, $businessDate, $businessDate);
        $leave = $this->context->overlappingLeave($employee, $user, $businessDate, $businessDate);

        return [
            'employee' => $this->employeePayload($employee),
            'schedule' => $this->schedulePayload($schedule),
            'attendance_conflict' => $attendance[0] ?? null,
            'leave_conflicts' => $leave,
            'can_submit' => $schedule && (string) $schedule->schedule_type === 'shift' && $attendance === [] && $leave === [],
        ];
    }

    public function openManual(Request $request): array
    {
        if (! $this->context->hasCapability($request, 'hr.attendance.manual.update', 'hr-approval-attendance', 'can_edit')) return [];
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return [];

        return HrAttendance::query()
            ->with(['employee', 'user'])
            ->where('source', 'manual')
            ->where('record_status', 'open')
            ->whereNull('checkout_at')
            ->whereIn('assignment_outlet_id', $allowed)
            ->orderByDesc('business_date')
            ->limit(100)
            ->get()
            ->map(fn (HrAttendance $row) => $this->present($row))
            ->values()->all();
    }

    public function create(Request $request, array $data): array
    {
        $this->authorize($request, 'hr.attendance.manual.create', 'can_create', 'Anda tidak memiliki akses membuat absensi manual.');

        $employee = $this->context->employeeInScope($request, (string) $data['employee_id']);
        $user = $this->context->resolveEmployeeUser($employee);
        $businessDate = (string) $data['business_date'];
        $schedule = $this->context->scheduleFor($employee, $businessDate);
        $this->assertWorkingSchedule($schedule);

        $outlet = $schedule?->outlet ?: $employee->assignment?->outlet;
        if (! $outlet) throw ValidationException::withMessages(['employee_id' => ['Outlet penugasan/schedule tidak ditemukan.']]);
        if (! $this->scope->isOutletAllowed($request, (string) $outlet->id)) abort(403, 'Outlet schedule berada di luar scope Anda.');

        $timezone = $this->context->safeTimezone($schedule?->outlet_timezone_snapshot ?: $outlet->timezone);
        $checkin = $this->parseLocal((string) $data['checkin_local'], $timezone, 'checkin_local');
        if ($checkin->toDateString() !== $businessDate) {
            throw ValidationException::withMessages(['checkin_local' => ['Tanggal Absen Datang harus sama dengan Business Date.']]);
        }
        $checkout = filled($data['checkout_local'] ?? null)
            ? $this->parseLocal((string) $data['checkout_local'], $timezone, 'checkout_local')
            : null;
        $this->assertCheckoutRange($checkin, $checkout);

        $this->assertNoConflicts($employee, $user, $businessDate);
        $actor = $request->user();
        $reason = trim((string) $data['reason']);
        $approvalNote = trim((string) $data['approval_note']);

        try {
            $attendance = DB::transaction(function () use ($employee, $user, $schedule, $outlet, $businessDate, $timezone, $checkin, $checkout, $actor, $reason, $approvalNote): HrAttendance {
                $duplicate = HrAttendance::query()
                    ->where('user_id', (string) $user->id)
                    ->whereDate('business_date', $businessDate)
                    ->where('record_status', '!=', 'cancelled')
                    ->lockForUpdate()->first(['id']);
                if ($duplicate) throw ValidationException::withMessages(['business_date' => ['Sudah ada attendance aktif pada tanggal tersebut.']]);

                if ($this->context->overlappingLeave($employee, $user, $businessDate, $businessDate) !== []) {
                    throw ValidationException::withMessages(['business_date' => ['Tidak dapat membuat attendance manual karena terdapat Ijin/Cuti yang overlap.']]);
                }

                $row = HrAttendance::query()->create(array_merge([
                    'user_id' => (string) $user->id,
                    'squad_id' => $this->squadId($user),
                    'employee_id' => (string) $employee->id,
                    'assignment_outlet_id' => (string) $outlet->id,
                    'business_date' => $businessDate,
                    'attendance_timezone' => $timezone,
                    'assignment_timezone' => $timezone,
                    'record_status' => $checkout ? 'complete' : 'open',
                    'checkin_at' => $this->toUtcString($checkin),
                    'checkin_outlet_id' => (string) $outlet->id,
                    'checkin_outlet_name' => (string) $outlet->name,
                    'checkin_outlet_timezone' => $timezone,
                    'checkin_inside_radius' => true,
                    'checkin_location_status' => 'manual',
                    'checkin_mode' => 'manual',
                    'checkin_note' => $reason,
                    'checkin_camera_status' => 'manual',
                    'approval_required' => false,
                    'approval_status' => 'approved_manual',
                    'calculation_eligible' => true,
                    'exception_flags' => [],
                    'device_info' => 'Backoffice manual attendance',
                    'source' => 'manual',
                    'manual_created_by_user_id' => (string) $actor->id,
                    'manual_updated_by_user_id' => (string) $actor->id,
                    'manual_reason' => $reason,
                    'manual_approval_note' => $approvalNote,
                    'manual_created_at' => now(),
                    'manual_updated_at' => now(),
                ], $checkout ? $this->checkoutAttributes($checkout, $timezone, $outlet, $reason) : []));

                $this->log($row, 'created', $actor, $reason, $approvalNote, null, $this->snapshot($row));
                return $row;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'hr_att_user_date_uq')) {
                throw ValidationException::withMessages(['business_date' => ['Attendance pada tanggal tersebut sudah ada.']]);
            }
            throw $e;
        }

        return $this->present($this->calculation->recalculate($attendance)->fresh(['employee', 'user']));
    }

    public function updateOpen(Request $request, string $attendanceId, array $data): array
    {
        $this->authorize($request, 'hr.attendance.manual.update', 'can_edit', 'Anda tidak memiliki akses memperbarui absensi manual.');
        $actor = $request->user();

        $attendance = DB::transaction(function () use ($request, $attendanceId, $data, $actor): HrAttendance {
            $row = HrAttendance::query()->with(['employee', 'user', 'checkinOutlet'])->lockForUpdate()->findOrFail($attendanceId);
            if ((string) $row->source !== 'manual') throw ValidationException::withMessages(['attendance' => ['Hanya attendance source manual yang dapat diubah dari fitur ini.']]);
            if ((string) $row->record_status !== 'open' || $row->checkout_at) throw ValidationException::withMessages(['attendance' => ['Attendance manual sudah lengkap/final.']]);
            if (! $row->assignment_outlet_id || ! $this->scope->isOutletAllowed($request, (string) $row->assignment_outlet_id)) abort(403, 'Attendance berada di luar scope outlet Anda.');

            if ($row->employee && $this->context->overlappingLeave($row->employee, $row->user, optional($row->business_date)->format('Y-m-d'), optional($row->business_date)->format('Y-m-d')) !== []) {
                throw ValidationException::withMessages(['attendance' => ['Attendance manual sekarang overlap dengan Ijin/Cuti aktif. Selesaikan conflict terlebih dahulu.']]);
            }

            $timezone = $this->context->safeTimezone($row->attendance_timezone);
            $checkout = $this->parseLocal((string) $data['checkout_local'], $timezone, 'checkout_local');
            $checkin = CarbonImmutable::parse((string) $row->getRawOriginal('checkin_at'), 'UTC')->setTimezone($timezone);
            $this->assertCheckoutRange($checkin, $checkout);

            $reason = trim((string) $data['reason']);
            $approvalNote = trim((string) $data['approval_note']);
            $before = $this->snapshot($row);
            $outlet = $row->checkinOutlet ?: $row->employee?->assignment?->outlet;
            if (! $outlet) throw ValidationException::withMessages(['attendance' => ['Outlet attendance tidak ditemukan.']]);

            $row->forceFill(array_merge(
                $this->checkoutAttributes($checkout, $timezone, $outlet, $reason),
                [
                    'record_status' => 'complete',
                    'approval_required' => false,
                    'approval_status' => 'approved_manual',
                    'calculation_eligible' => true,
                    'manual_updated_by_user_id' => (string) $actor->id,
                    'manual_reason' => $reason,
                    'manual_approval_note' => $approvalNote,
                    'manual_updated_at' => now(),
                ]
            ))->save();

            $this->log($row, 'manual_checkout', $actor, $reason, $approvalNote, $before, $this->snapshot($row));
            return $row;
        });

        return $this->present($this->calculation->recalculate($attendance)->fresh(['employee', 'user']));
    }

    public function recordSelfCheckoutContinuation(HrAttendance $attendance, User $actor, ?array $before = null): void
    {
        if ((string) $attendance->source !== 'manual' || ! Schema::hasTable('HR_attendance_manual_logs')) return;
        $this->log(
            $attendance,
            'self_checkout_continuation',
            $actor,
            (string) ($attendance->checkout_note ?: 'Checkout dilanjutkan oleh user melalui Attendance Portal.'),
            'Manual check-in dilanjutkan dengan checkout normal oleh employee.',
            $before,
            $this->snapshot($attendance)
        );
    }

    private function authorize(Request $request, string $permission, string $flag, string $message): void
    {
        if (! $this->context->hasCapability($request, $permission, 'hr-approval-attendance', $flag)) abort(403, $message);
    }

    private function assertWorkingSchedule(?HrShiftSchedule $schedule): void
    {
        if (! $schedule) throw ValidationException::withMessages(['business_date' => ['Schedule employee belum dimapping pada tanggal tersebut.']]);
        if ((string) $schedule->schedule_type !== 'shift') {
            throw ValidationException::withMessages(['business_date' => ['Schedule employee berstatus OFF. Attendance manual tidak dapat dibuat.']]);
        }
    }

    private function assertNoConflicts(Employee $employee, User $user, string $businessDate): void
    {
        if ($this->context->overlappingAttendance($employee, $user, $businessDate, $businessDate) !== []) {
            throw ValidationException::withMessages(['business_date' => ['Sudah ada attendance aktif pada tanggal tersebut.']]);
        }
        if ($this->context->overlappingLeave($employee, $user, $businessDate, $businessDate) !== []) {
            throw ValidationException::withMessages(['business_date' => ['Terdapat Ijin/Cuti aktif yang overlap dengan tanggal attendance manual.']]);
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

    private function assertCheckoutRange(CarbonImmutable $checkin, ?CarbonImmutable $checkout): void
    {
        if (! $checkout) return;
        if ($checkout->lte($checkin)) throw ValidationException::withMessages(['checkout_local' => ['Absen Pulang harus setelah Absen Datang.']]);
        if ($checkout->gt($checkin->addHours(36))) throw ValidationException::withMessages(['checkout_local' => ['Rentang Datang–Pulang maksimum 36 jam.']]);
    }

    private function checkoutAttributes(CarbonImmutable $checkout, string $timezone, object $outlet, string $reason): array
    {
        return [
            'checkout_at' => $this->toUtcString($checkout),
            'checkout_business_date' => $checkout->toDateString(),
            'checkout_timezone' => $timezone,
            'checkout_outlet_id' => (string) $outlet->id,
            'checkout_outlet_name' => (string) $outlet->name,
            'checkout_outlet_timezone' => $timezone,
            'checkout_inside_radius' => true,
            'checkout_location_status' => 'manual',
            'checkout_mode' => 'manual',
            'checkout_note' => $reason,
            'checkout_camera_status' => 'manual',
        ];
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

    private function log(HrAttendance $row, string $action, User $actor, ?string $reason, ?string $approvalNote, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('HR_attendance_manual_logs')) return;
        HrAttendanceManualLog::query()->create([
            'attendance_id' => (string) $row->id,
            'action' => $action,
            'actor_user_id' => (string) $actor->id,
            'actor_name_snapshot' => (string) ($actor->name ?: $actor->username ?: $actor->nisj ?: $actor->id),
            'reason' => $reason,
            'approval_note' => $approvalNote,
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }

    private function snapshot(HrAttendance $row): array
    {
        return [
            'id' => (string) $row->id,
            'business_date' => optional($row->business_date)->format('Y-m-d'),
            'record_status' => (string) $row->record_status,
            'checkin_at' => $row->getRawOriginal('checkin_at'),
            'checkout_at' => $row->getRawOriginal('checkout_at'),
            'source' => (string) $row->source,
            'approval_status' => (string) $row->approval_status,
            'calculation_eligible' => (bool) $row->calculation_eligible,
            'manual_reason' => $row->manual_reason,
            'manual_approval_note' => $row->manual_approval_note,
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

    private function schedulePayload(?HrShiftSchedule $schedule): ?array
    {
        if (! $schedule) return null;
        return [
            'id' => (string) $schedule->id,
            'schedule_type' => (string) $schedule->schedule_type,
            'shift_name' => (string) ($schedule->shift_name_snapshot ?? '-'),
            'start_time' => $schedule->start_time_snapshot,
            'end_time' => $schedule->end_time_snapshot,
            'is_overnight' => (bool) $schedule->is_overnight_snapshot,
            'outlet_id' => $schedule->outlet_id ? (string) $schedule->outlet_id : null,
            'outlet_name' => (string) ($schedule->outlet_name_snapshot ?: $schedule->outlet?->name ?: '-'),
            'timezone' => $this->context->safeTimezone($schedule->outlet_timezone_snapshot),
        ];
    }

    private function present(HrAttendance $row): array
    {
        $timezone = $this->context->safeTimezone($row->attendance_timezone);
        $toLocal = static function ($raw) use ($timezone): ?string {
            if (! $raw) return null;
            return CarbonImmutable::parse((string) $raw, 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i');
        };
        return [
            'id' => (string) $row->id,
            'employee_id' => $row->employee_id ? (string) $row->employee_id : null,
            'full_name' => (string) ($row->employee?->full_name ?: $row->user?->name ?: '-'),
            'nisj' => (string) ($row->employee?->nisj ?: $row->user?->nisj ?: ''),
            'business_date' => optional($row->business_date)->format('Y-m-d'),
            'timezone' => $timezone,
            'checkin_local' => $toLocal($row->getRawOriginal('checkin_at')),
            'checkout_local' => $toLocal($row->getRawOriginal('checkout_at')),
            'record_status' => (string) $row->record_status,
            'source' => (string) $row->source,
            'manual_reason' => (string) ($row->manual_reason ?? ''),
            'manual_approval_note' => (string) ($row->manual_approval_note ?? ''),
        ];
    }
}
