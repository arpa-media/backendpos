<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\HumanResource\HrAttendance;
use App\Models\HumanResource\HrOvertimeI06;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrOvertimeManualFlexibleI08Hotfix02Service
{
    private const MAX_OVERTIME_MINUTES = 24 * 60;

    public function __construct(
        private readonly HrOvertimeI06Service $canonical,
        private readonly HrAttendanceBackofficeScopeService $scope,
    ) {}

    /**
     * Manual HR overtime is intentionally allowed without a completed attendance.
     *
     * If a completed checkout exists we reuse the canonical I06 path so its original
     * checkout guard remains intact. OFF / not-yet-attended / incomplete-attendance
     * cases use the flexible path below and are anchored to employee + business date.
     */
    public function manualUpsert(Request $request, array $data): array
    {
        $employee = Employee::query()->with('user')->find((string) $data['employee_id']);
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Employee tidak ditemukan.']]);
        }

        $date = (string) $data['business_date'];
        $attendance = HrAttendance::query()
            ->where('employee_id', (string) $employee->id)
            ->whereDate('business_date', $date)
            ->where('record_status', '!=', 'cancelled')
            ->orderByDesc('updated_at')
            ->first();

        // Keep I06's stricter semantics when there really is a completed checkout.
        if ($attendance && $attendance->record_status === 'complete' && $attendance->checkout_at) {
            return $this->canonical->manualUpsert($request, $data);
        }

        $outletId = $this->resolveOutletId($request, $employee, $attendance, $date, (string) ($data['outlet_id'] ?? ''));
        $timezone = $this->resolveTimezone($attendance, $outletId);
        $start = $this->parseLocal((string) $data['start_local'], $timezone, 'start_local');
        $end = $this->parseLocal((string) $data['end_local'], $timezone, 'end_local');

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_local' => ['Jam selesai lembur harus setelah jam mulai.']]);
        }

        // If checkout happens to exist even though attendance is not complete, avoid
        // overlapping regular attendance time. No checkout means no checkout guard.
        if ($attendance?->checkout_at) {
            $checkout = $this->utcMoment($attendance->getRawOriginal('checkout_at'))?->setTimezone($timezone);
            if ($checkout && $start->lt($checkout)) {
                throw ValidationException::withMessages(['start_local' => ['Jam mulai lembur tidak boleh sebelum Jam Pulang yang sudah tercatat.']]);
            }
        }

        $minutes = max(1, (int) floor($start->diffInSeconds($end) / 60));
        if ($minutes > self::MAX_OVERTIME_MINUTES) {
            throw ValidationException::withMessages(['end_local' => ['Durasi lembur maksimum 24 jam.']]);
        }

        $salary = $this->salarySnapshotForEmployee($employee);
        $actor = $request->user();
        if (! $actor) abort(401);

        return DB::transaction(function () use ($employee, $attendance, $outletId, $date, $timezone, $start, $end, $minutes, $data, $salary, $actor): array {
            $row = HrOvertimeI06::query()
                ->where('employee_id', (string) $employee->id)
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->first();

            $before = $row ? $this->snapshot($row) : null;
            $payload = [
                'attendance_id' => $attendance?->id ? (string) $attendance->id : null,
                'user_id' => $employee->user_id ? (string) $employee->user_id : null,
                'employee_id' => (string) $employee->id,
                'squad_id' => $salary['squad_id'],
                'outlet_id' => $outletId,
                'business_date' => $date,
                'timezone' => $timezone,
                'source' => 'manual_hr',
                'status' => 'completed',
                'start_at' => $start->setTimezone('UTC')->format('Y-m-d H:i:s'),
                'end_at' => $end->setTimezone('UTC')->format('Y-m-d H:i:s'),
                'overtime_minutes' => $minutes,
                'overtime_rate_snapshot' => $salary['hourly_overtime'],
                'amount_snapshot' => round(($minutes / 60) * $salary['hourly_overtime'], 2),
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'manual_reason' => trim((string) $data['reason']),
                'created_by_user_id' => $row?->created_by_user_id ?: (string) $actor->id,
                'updated_by_user_id' => (string) $actor->id,
                'cancelled_by_user_id' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ];

            if ($row) {
                $row->forceFill($payload)->save();
                $action = 'manual_update_flexible';
            } else {
                $row = HrOvertimeI06::query()->create($payload);
                $action = 'manual_create_flexible';
            }

            $row = $row->fresh();
            $this->audit(
                $row,
                $action,
                $actor,
                (string) $data['reason'],
                $before,
                $this->snapshot($row)
            );

            $result = $this->canonical->findForEmployeeDate((string) $employee->id, $date) ?? [];
            $result['message'] = $action === 'manual_create_flexible'
                ? 'Lembur manual berhasil disimpan.'
                : 'Data lembur manual berhasil dikoreksi.';
            $result['attendance_requirement'] = 'not_required_for_manual_hr';
            return $result;
        });
    }

    private function resolveOutletId(Request $request, Employee $employee, ?HrAttendance $attendance, string $date, string $requested): string
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) {
            throw ValidationException::withMessages(['outlet_id' => ['User tidak mempunyai scope outlet untuk input lembur.']]);
        }

        $requested = trim($requested);
        if ($requested !== '') {
            if (! in_array($requested, $allowed, true)) {
                throw ValidationException::withMessages(['outlet_id' => ['Outlet lembur berada di luar scope user.']]);
            }
            return $requested;
        }

        foreach ([
            $attendance?->assignment_outlet_id,
            $attendance?->checkout_outlet_id,
            $attendance?->checkin_outlet_id,
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && in_array($candidate, $allowed, true)) return $candidate;
        }

        $assignment = DB::table('assignments as x')
            ->where('x.employee_id', (string) $employee->id)
            ->whereIn('x.outlet_id', $allowed)
            ->where(function ($q) use ($date) {
                $q->whereNull('x.start_date')->orWhereDate('x.start_date', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('x.end_date')->orWhereDate('x.end_date', '>=', $date);
            })
            ->where(function ($q) {
                $q->whereNull('x.status')->orWhereRaw("LOWER(x.status) NOT IN ('inactive','cancelled')");
            })
            ->orderByDesc('x.is_primary')
            ->orderByDesc('x.start_date')
            ->first(['x.outlet_id']);

        if ($assignment?->outlet_id) return (string) $assignment->outlet_id;

        throw ValidationException::withMessages([
            'outlet_id' => ['Outlet wajib dipilih untuk lembur manual Squad yang belum mempunyai attendance.'],
        ]);
    }

    private function resolveTimezone(?HrAttendance $attendance, string $outletId): string
    {
        foreach ([
            $attendance?->checkout_timezone,
            $attendance?->attendance_timezone,
            DB::table('outlets')->where('id', $outletId)->value('timezone'),
        ] as $timezone) {
            $timezone = trim((string) $timezone);
            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) return $timezone;
        }
        return 'Asia/Jakarta';
    }

    private function salarySnapshotForEmployee(Employee $employee): array
    {
        if (! Schema::hasTable('HR_squads')) return ['squad_id' => null, 'hourly_overtime' => 0.0];
        $nisj = mb_strtolower(trim((string) $employee->nisj));
        if ($nisj === '') return ['squad_id' => null, 'hourly_overtime' => 0.0];

        $query = DB::table('HR_squads')->whereRaw('LOWER(TRIM(nisj)) = ?', [$nisj]);
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('deleted_at');
        $squad = $query->first(['id', 'hourly_overtime']);

        return [
            'squad_id' => $squad?->id ? (int) $squad->id : null,
            'hourly_overtime' => round((float) ($squad?->hourly_overtime ?? 0), 2),
        ];
    }

    private function parseLocal(string $value, string $timezone, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Format waktu lembur tidak valid.']]);
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

    private function snapshot(HrOvertimeI06 $row): array
    {
        return [
            'id' => (string) $row->id,
            'attendance_id' => $row->attendance_id ? (string) $row->attendance_id : null,
            'employee_id' => (string) $row->employee_id,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'business_date' => $row->business_date?->format('Y-m-d') ?? (string) $row->business_date,
            'source' => (string) $row->source,
            'status' => (string) $row->status,
            'start_at' => $row->getRawOriginal('start_at'),
            'end_at' => $row->getRawOriginal('end_at'),
            'overtime_minutes' => (int) $row->overtime_minutes,
            'overtime_rate_snapshot' => (float) $row->overtime_rate_snapshot,
            'amount_snapshot' => (float) $row->amount_snapshot,
            'manual_reason' => (string) ($row->manual_reason ?? ''),
            'note' => (string) ($row->note ?? ''),
        ];
    }

    private function audit(HrOvertimeI06 $row, string $action, $actor, string $note, ?array $before, array $after): void
    {
        if (! Schema::hasTable('HR_overtime_logs')) return;
        DB::table('HR_overtime_logs')->insert([
            'id' => (string) Str::ulid(),
            'overtime_id' => (string) $row->id,
            'action' => $action,
            'actor_user_id' => (string) $actor->id,
            'actor_name_snapshot' => (string) ($actor->name ?: $actor->username ?: $actor->nisj ?: $actor->id),
            'note' => $note,
            'before_json' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }
}
