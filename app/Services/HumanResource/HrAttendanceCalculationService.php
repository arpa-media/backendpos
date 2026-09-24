<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrAttendanceCalculationService
{
    public const VERSION = 'attendance-v21';

    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function recalculate(HrAttendance $attendance): HrAttendance
    {
        if (! Schema::hasTable('HR_shift_schedules') || ! Schema::hasTable('HR_attendances')) {
            return $attendance;
        }

        $employeeId = $this->employeeId($attendance);
        $date = $this->businessDate($attendance);
        $schedule = ($employeeId && $date)
            ? DB::table('HR_shift_schedules')
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $date)
                ->first()
            : null;

        if (! $schedule) {
            $attendance->forceFill([
                'shift_schedule_id' => null,
                'late_minutes' => null,
                'work_minutes' => null,
                'calculation_status' => 'unmapped',
                'calculation_version' => self::VERSION,
                'calculated_at' => now(),
            ])->save();
            return $attendance->fresh();
        }

        $scheduleType = strtolower(trim((string) ($schedule->schedule_type ?? 'shift')));
        if ($scheduleType !== 'shift') {
            $attendance->forceFill([
                'shift_schedule_id' => (string) $schedule->id,
                'late_minutes' => 0,
                'work_minutes' => $attendance->checkout_at ? 0 : null,
                'calculation_status' => 'off_attendance',
                'calculation_version' => self::VERSION,
                'calculated_at' => now(),
            ])->save();
            return $attendance->fresh();
        }

        $timezone = $this->safeTimezone((string) ($schedule->outlet_timezone_snapshot ?? $attendance->attendance_timezone ?? 'Asia/Jakarta'));
        $start = $this->scheduleMoment($date, $schedule->start_time_snapshot ?? null, $timezone);
        $end = $this->scheduleMoment($date, $schedule->end_time_snapshot ?? null, $timezone);
        if ($start && $end && ((bool) ($schedule->is_overnight_snapshot ?? false) || $end <= $start)) {
            $end = $end->addDay();
        }

        $checkin = $this->utcMoment($attendance->getRawOriginal('checkin_at'))?->setTimezone($timezone);
        $checkout = $this->utcMoment($attendance->getRawOriginal('checkout_at'))?->setTimezone($timezone);
        $late = ($start && $checkin && $checkin > $start)
            ? max(0, (int) floor($start->diffInSeconds($checkin) / 60))
            : 0;

        $work = null;
        $status = 'open';
        if ($checkout) {
            if (! $checkin || $checkout <= $checkin) {
                $work = 0;
                $status = 'invalid_timestamp_order';
            } else {
                // Existing HR rule protects payroll from forgotten checkout that is completed
                // days later by capping recovery at scheduled end. Normal checkout remains actual in-out.
                $recovered = $end && $checkout->toDateString() > $end->toDateString();
                if ($recovered) {
                    $effectiveStart = $start && $checkin < $start ? $start : $checkin;
                    $work = ($end && $effectiveStart < $end)
                        ? max(0, (int) floor($effectiveStart->diffInSeconds($end) / 60))
                        : 0;
                    $status = 'complete_recovered';
                } else {
                    $work = max(0, min((int) floor($checkin->diffInSeconds($checkout) / 60), 24 * 60));
                    $status = 'complete';
                }
            }
        }

        $attendance->forceFill([
            'shift_schedule_id' => (string) $schedule->id,
            'late_minutes' => $late,
            'work_minutes' => $work,
            'calculation_status' => $status,
            'calculation_version' => self::VERSION,
            'calculated_at' => now(),
        ])->save();

        return $attendance->fresh();
    }

    public function recalculateDate(Request $request, string $date, ?string $outletId = null): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) {
            return $this->emptySummary($date);
        }

        $outletId = trim((string) $outletId);
        if ($outletId !== '' && ! in_array($outletId, $allowed, true)) {
            abort(403, 'Penugasan berada di luar scope Anda.');
        }

        $query = DB::table('HR_attendances as a')
            ->whereDate('a.business_date', $date)
            ->where('a.record_status', '!=', 'cancelled')
            ->whereIn(DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'), $allowed);
        if ($outletId !== '') {
            $query->whereRaw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id) = ?', [$outletId]);
        }

        $ids = $query->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (string) $id)->all();
        $summary = $this->emptySummary($date);
        $summary['scanned'] = count($ids);

        foreach (array_chunk($ids, 250) as $chunk) {
            foreach (HrAttendance::query()->whereIn('id', $chunk)->get() as $attendance) {
                $fresh = $this->recalculate($attendance);
                $summary['recalculated']++;
                if ((string) $fresh->calculation_status === 'unmapped') $summary['unmapped']++;
                else $summary['mapped']++;
                if ((int) ($fresh->late_minutes ?? 0) > 0) $summary['late_records']++;
                if ($fresh->checkout_at) $summary['completed_records']++;
            }
        }

        return $summary;
    }

    private function emptySummary(string $date): array
    {
        return [
            'date' => $date,
            'scanned' => 0,
            'recalculated' => 0,
            'mapped' => 0,
            'unmapped' => 0,
            'late_records' => 0,
            'completed_records' => 0,
            'calculation_version' => self::VERSION,
        ];
    }

    private function employeeId(HrAttendance $attendance): ?string
    {
        if ($attendance->employee_id) return (string) $attendance->employee_id;
        if (! $attendance->user_id || ! Schema::hasTable('employees')) return null;
        $id = DB::table('employees')->where('user_id', (string) $attendance->user_id)->value('id');
        if ($id) {
            $attendance->forceFill(['employee_id' => (string) $id])->save();
            return (string) $id;
        }
        return null;
    }

    private function businessDate(HrAttendance $attendance): ?string
    {
        $value = $attendance->business_date;
        if (! $value) return null;
        return method_exists($value, 'format') ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function scheduleMoment(string $date, mixed $time, string $timezone): ?CarbonImmutable
    {
        $raw = trim((string) ($time ?? ''));
        if ($raw === '') return null;
        try {
            return CarbonImmutable::parse($date.' '.$raw, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function utcMoment(mixed $value): ?CarbonImmutable
    {
        if (! $value) return null;
        try {
            return CarbonImmutable::parse((string) $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Asia/Jakarta';
    }
}
