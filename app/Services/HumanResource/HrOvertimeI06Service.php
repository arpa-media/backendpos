<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\HumanResource\HrAttendance;
use App\Models\HumanResource\HrOvertimeI06;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrOvertimeI06Service
{
    private const SELF_CHECKOUT_WINDOW_HOURS = 18;
    private const MAX_OVERTIME_MINUTES = 24 * 60;
    private const PER_PAGE = [10, 25, 50, 100, 500];

    public function __construct(
        private readonly HrAttendanceIdentityService $identity,
        private readonly HrAttendanceBackofficeScopeService $scope,
    ) {}

    public function selfContext(User $user): array
    {
        $identity = $this->identity->resolve($user);
        $employee = $identity['employee'] ?? null;
        $attendance = $this->latestEligibleAttendanceForUser((string) $user->id);
        $overtime = null;

        if ($employee) {
            // An overtime session already in progress must remain finishable even when
            // its checkout has passed the 18-hour new-start window. The window gates
            // starting a new overtime session, not finishing an existing one.
            $overtime = HrOvertimeI06::query()
                ->where('employee_id', (string) $employee->id)
                ->where('status', 'open')
                ->orderByDesc('business_date')
                ->first();

            if (! $overtime && $attendance) {
                $overtime = HrOvertimeI06::query()
                    ->where('employee_id', (string) $employee->id)
                    ->whereDate('business_date', $this->dateString($attendance->business_date))
                    ->first();
            }

            if (! $attendance && $overtime?->attendance_id) {
                $attendance = HrAttendance::query()->find((string) $overtime->attendance_id);
            }
        }

        $startEligible = (bool) ($employee && $attendance && $this->isAttendanceInsideSelfStartWindow($attendance));
        return [
            'eligible' => $startEligible,
            'can_start' => (bool) ($startEligible && (! $overtime || $overtime->status === 'cancelled')),
            'can_finish' => (bool) ($overtime && $overtime->status === 'open'),
            'attendance' => $attendance ? $this->attendanceSummary($attendance) : null,
            'overtime' => $overtime ? $this->present($overtime) : null,
            'rate' => $employee ? $this->salarySnapshotForEmployee($employee)['hourly_overtime'] : 0.0,
            'rule' => [
                'source_of_truth' => 'overtime_minutes',
                'hours_formula' => 'overtime_minutes / 60',
                'self_service_requires_completed_checkout' => true,
            ],
        ];
    }

    public function startSelf(User $actor): array
    {
        $identity = $this->identity->resolve($actor);
        /** @var Employee|null $employee */
        $employee = $identity['employee'] ?? null;
        if (! $employee) {
            throw ValidationException::withMessages(['employee' => ['User belum terhubung ke Data Squad/Employee.']]);
        }

        return DB::transaction(function () use ($actor, $employee): array {
            $attendance = $this->latestEligibleAttendanceForUser((string) $actor->id, true);
            if (! $attendance) {
                throw ValidationException::withMessages(['attendance' => ['Lembur hanya dapat dimulai setelah Absen Pulang berhasil disimpan.']]);
            }

            $businessDate = $this->dateString($attendance->business_date);
            $existing = HrOvertimeI06::query()
                ->where('employee_id', (string) $employee->id)
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'open') {
                return $this->present($existing);
            }
            if ($existing && $existing->status === 'completed') {
                throw ValidationException::withMessages(['overtime' => ['Lembur pada tanggal ini sudah selesai.']]);
            }

            $nowUtc = CarbonImmutable::now('UTC');
            $checkoutUtc = $this->utcMoment($attendance->getRawOriginal('checkout_at'));
            if (! $checkoutUtc) {
                throw ValidationException::withMessages(['attendance' => ['Jam Absen Pulang tidak ditemukan.']]);
            }
            if ($nowUtc->lt($checkoutUtc)) {
                throw ValidationException::withMessages(['attendance' => ['Waktu perangkat/server berada sebelum jam Absen Pulang.']]);
            }

            $salary = $this->salarySnapshotForEmployee($employee);
            $outletId = $attendance->assignment_outlet_id ?: $attendance->checkout_outlet_id ?: $attendance->checkin_outlet_id;
            $timezone = $this->safeTimezone((string) ($attendance->checkout_timezone ?: $attendance->attendance_timezone));
            $payload = [
                'attendance_id' => (string) $attendance->id,
                'user_id' => (string) $actor->id,
                'employee_id' => (string) $employee->id,
                'squad_id' => $salary['squad_id'],
                'outlet_id' => $outletId ? (string) $outletId : null,
                'business_date' => $businessDate,
                'timezone' => $timezone,
                'source' => 'self-service',
                'status' => 'open',
                'start_at' => $nowUtc->format('Y-m-d H:i:s'),
                'end_at' => null,
                'overtime_minutes' => 0,
                'overtime_rate_snapshot' => $salary['hourly_overtime'],
                'amount_snapshot' => 0,
                'note' => null,
                'manual_reason' => null,
                'created_by_user_id' => (string) $actor->id,
                'updated_by_user_id' => (string) $actor->id,
                'cancelled_by_user_id' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ];

            $before = $existing ? $this->snapshot($existing) : null;
            if ($existing) {
                $existing->forceFill($payload)->save();
                $row = $existing->fresh();
            } else {
                $row = HrOvertimeI06::query()->create($payload);
            }

            $this->audit($row, 'self_start', $actor, 'Lembur dimulai setelah Absen Pulang.', $before, $this->snapshot($row));
            return $this->present($row);
        });
    }

    public function finishSelf(User $actor, ?string $note = null): array
    {
        $identity = $this->identity->resolve($actor);
        /** @var Employee|null $employee */
        $employee = $identity['employee'] ?? null;
        if (! $employee) {
            throw ValidationException::withMessages(['employee' => ['User belum terhubung ke Data Squad/Employee.']]);
        }

        return DB::transaction(function () use ($actor, $employee, $note): array {
            $row = HrOvertimeI06::query()
                ->where('employee_id', (string) $employee->id)
                ->where('status', 'open')
                ->orderByDesc('business_date')
                ->lockForUpdate()
                ->first();
            if (! $row) {
                throw ValidationException::withMessages(['overtime' => ['Tidak ada lembur aktif yang dapat diselesaikan.']]);
            }

            $start = $this->utcMoment($row->getRawOriginal('start_at'));
            $end = CarbonImmutable::now('UTC');
            if (! $start || $end->lte($start)) {
                throw ValidationException::withMessages(['overtime' => ['Rentang waktu lembur tidak valid.']]);
            }

            $minutes = max(1, (int) floor($start->diffInSeconds($end) / 60));
            if ($minutes > self::MAX_OVERTIME_MINUTES) {
                throw ValidationException::withMessages(['overtime' => ['Durasi lembur melebihi 24 jam. Koreksi melalui Data Lembur.']]);
            }

            $before = $this->snapshot($row);
            $rate = (float) $row->overtime_rate_snapshot;
            $row->forceFill([
                'status' => 'completed',
                'end_at' => $end->format('Y-m-d H:i:s'),
                'overtime_minutes' => $minutes,
                'amount_snapshot' => round(($minutes / 60) * $rate, 2),
                'note' => trim((string) $note) ?: $row->note,
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            $row = $row->fresh();
            $this->audit($row, 'self_finish', $actor, 'Lembur diselesaikan oleh Squad.', $before, $this->snapshot($row));
            return $this->present($row);
        });
    }

    public function options(Request $request): array
    {
        return [
            'outlets' => $this->scope->options($request),
            'per_page_options' => self::PER_PAGE,
            'statuses' => [
                ['value' => 'completed', 'label' => 'Selesai'],
                ['value' => 'open', 'label' => 'Sedang Lembur'],
                ['value' => 'cancelled', 'label' => 'Dibatalkan'],
            ],
            'sources' => [
                ['value' => 'self-service', 'label' => 'Self Service'],
                ['value' => 'manual_hr', 'label' => 'Manual HR'],
            ],
        ];
    }

    public function index(Request $request): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return $this->emptyPage($request);

        $query = DB::table('HR_overtimes as o')
            ->join('employees as e', 'e.id', '=', 'o.employee_id')
            ->leftJoin('outlets as x', 'x.id', '=', 'o.outlet_id')
            ->leftJoin('HR_attendances as a', 'a.id', '=', 'o.attendance_id')
            ->whereIn('o.outlet_id', $allowed)
            ->select([
                'o.*', 'e.full_name', 'e.nisj', 'x.name as outlet_name',
                'a.checkout_at as attendance_checkout_at', 'a.checkout_timezone as attendance_checkout_timezone',
            ]);

        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        if ($from !== '') $query->whereDate('o.business_date', '>=', $from);
        if ($to !== '') $query->whereDate('o.business_date', '<=', $to);

        $outletId = trim((string) $request->query('outlet_id', ''));
        if ($outletId !== '') {
            if (! in_array($outletId, $allowed, true)) return $this->emptyPage($request);
            $query->where('o.outlet_id', $outletId);
        }
        $status = strtolower(trim((string) $request->query('status', '')));
        if (in_array($status, ['open', 'completed', 'cancelled'], true)) $query->where('o.status', $status);
        $source = strtolower(trim((string) $request->query('source', '')));
        if (in_array($source, ['self-service', 'manual_hr'], true)) $query->where('o.source', $source);
        $name = mb_strtolower(trim((string) $request->query('name', '')));
        if ($name !== '') $query->whereRaw('LOWER(e.full_name) LIKE ?', ['%'.$name.'%']);
        $nisj = mb_strtolower(trim((string) $request->query('nisj', '')));
        if ($nisj !== '') $query->whereRaw('LOWER(COALESCE(e.nisj,\'\')) LIKE ?', ['%'.$nisj.'%']);

        $sortBy = (string) $request->query('sort_by', 'business_date');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortMap = [
            'business_date' => 'o.business_date', 'full_name' => 'e.full_name', 'nisj' => 'e.nisj',
            'outlet_name' => 'x.name', 'overtime_minutes' => 'o.overtime_minutes', 'status' => 'o.status', 'source' => 'o.source',
        ];
        $query->orderBy($sortMap[$sortBy] ?? 'o.business_date', $sortDir)->orderBy('e.full_name');

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE, true)) $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->presentObject($row))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function manualUpsert(Request $request, array $data): array
    {
        $employee = Employee::query()->with(['user', 'assignment.outlet'])->find((string) $data['employee_id']);
        if (! $employee) throw ValidationException::withMessages(['employee_id' => ['Employee tidak ditemukan.']]);

        $date = (string) $data['business_date'];
        $attendance = HrAttendance::query()
            ->where('employee_id', (string) $employee->id)
            ->whereDate('business_date', $date)
            ->where('record_status', '!=', 'cancelled')
            ->first();
        if (! $attendance || $attendance->record_status !== 'complete' || ! $attendance->checkout_at) {
            throw ValidationException::withMessages(['business_date' => ['Lembur manual hanya dapat diinput jika Squad sudah Absen Pulang pada Daily Report.']]);
        }

        $outletId = (string) ($data['outlet_id'] ?? ($attendance->assignment_outlet_id ?: $attendance->checkout_outlet_id ?: $attendance->checkin_outlet_id));
        if ($outletId === '' || ! $this->scope->isOutletAllowed($request, $outletId)) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet lembur berada di luar scope user.']]);
        }

        $timezone = $this->safeTimezone((string) ($attendance->checkout_timezone ?: $attendance->attendance_timezone ?: $employee->assignment?->outlet?->timezone));
        $start = $this->parseLocal((string) $data['start_local'], $timezone, 'start_local');
        $end = $this->parseLocal((string) $data['end_local'], $timezone, 'end_local');
        $checkout = $this->utcMoment($attendance->getRawOriginal('checkout_at'))?->setTimezone($timezone);
        if ($checkout && $start->lt($checkout)) {
            throw ValidationException::withMessages(['start_local' => ['Jam mulai lembur tidak boleh sebelum Jam Pulang.']]);
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_local' => ['Jam selesai lembur harus setelah jam mulai.']]);
        }
        $minutes = max(1, (int) floor($start->diffInSeconds($end) / 60));
        if ($minutes > self::MAX_OVERTIME_MINUTES) {
            throw ValidationException::withMessages(['end_local' => ['Durasi lembur maksimum 24 jam.']]);
        }

        $actor = $request->user();
        $salary = $this->salarySnapshotForEmployee($employee);
        return DB::transaction(function () use ($employee, $attendance, $outletId, $date, $timezone, $start, $end, $minutes, $data, $actor, $salary): array {
            $row = HrOvertimeI06::query()
                ->where('employee_id', (string) $employee->id)
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->first();
            $before = $row ? $this->snapshot($row) : null;
            $rate = (float) $salary['hourly_overtime'];
            $payload = [
                'attendance_id' => (string) $attendance->id,
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
                'overtime_rate_snapshot' => $rate,
                'amount_snapshot' => round(($minutes / 60) * $rate, 2),
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
                $action = 'manual_update';
            } else {
                $row = HrOvertimeI06::query()->create($payload);
                $action = 'manual_create';
            }
            $row = $row->fresh();
            $this->audit($row, $action, $actor, (string) $data['reason'], $before, $this->snapshot($row));
            $result = $this->present($row);
            $result['message'] = $action === 'manual_create' ? 'Lembur manual berhasil disimpan.' : 'Data lembur berhasil dikoreksi.';
            return $result;
        });
    }

    public function findForEmployeeDate(string $employeeId, string $date): ?array
    {
        if (! Schema::hasTable('HR_overtimes')) return null;
        $row = HrOvertimeI06::query()->where('employee_id', $employeeId)->whereDate('business_date', $date)->first();
        return $row ? $this->present($row) : null;
    }

    private function isAttendanceInsideSelfStartWindow(HrAttendance $attendance): bool
    {
        if ($attendance->record_status !== 'complete' || ! $attendance->checkout_at) return false;
        $checkout = $this->utcMoment($attendance->getRawOriginal('checkout_at'));
        if (! $checkout) return false;
        $now = CarbonImmutable::now('UTC');
        return ! $now->lt($checkout) && $checkout->gte($now->subHours(self::SELF_CHECKOUT_WINDOW_HOURS));
    }

    private function latestEligibleAttendanceForUser(string $userId, bool $lock = false): ?HrAttendance
    {
        $cutoff = CarbonImmutable::now('UTC')->subHours(self::SELF_CHECKOUT_WINDOW_HOURS)->format('Y-m-d H:i:s');
        $query = HrAttendance::query()
            ->where('user_id', $userId)
            ->where('record_status', 'complete')
            ->whereNotNull('checkout_at')
            ->where('checkout_at', '>=', $cutoff)
            ->orderByDesc('checkout_at');
        if ($lock) $query->lockForUpdate();
        return $query->first();
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

    private function attendanceSummary(HrAttendance $attendance): array
    {
        $timezone = $this->safeTimezone((string) ($attendance->checkout_timezone ?: $attendance->attendance_timezone));
        return [
            'id' => (string) $attendance->id,
            'business_date' => $this->dateString($attendance->business_date),
            'checkout_at' => $this->localTimestamp($attendance->getRawOriginal('checkout_at'), $timezone),
            'outlet_id' => $attendance->assignment_outlet_id ?: $attendance->checkout_outlet_id ?: $attendance->checkin_outlet_id,
            'outlet_name' => $attendance->checkout_outlet_name ?: $attendance->checkin_outlet_name,
            'timezone' => $timezone,
        ];
    }

    private function present(HrOvertimeI06 $row): array
    {
        return $this->presentObject((object) [
            ...$row->getAttributes(),
            'full_name' => $row->employee?->full_name,
            'nisj' => $row->employee?->nisj,
            'outlet_name' => $row->outlet?->name,
            'attendance_checkout_at' => $row->attendance?->getRawOriginal('checkout_at'),
            'attendance_checkout_timezone' => $row->attendance?->checkout_timezone,
        ]);
    }

    private function presentObject(object $row): array
    {
        $timezone = $this->safeTimezone((string) ($row->timezone ?? 'Asia/Jakarta'));
        $minutes = max(0, (int) ($row->overtime_minutes ?? 0));
        return [
            'id' => (string) $row->id,
            'attendance_id' => $row->attendance_id ? (string) $row->attendance_id : null,
            'employee_id' => (string) $row->employee_id,
            'full_name' => (string) ($row->full_name ?? ''),
            'nisj' => (string) ($row->nisj ?? ''),
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'outlet_name' => (string) ($row->outlet_name ?? '-'),
            'business_date' => $this->dateString($row->business_date ?? null),
            'timezone' => $timezone,
            'source' => (string) $row->source,
            'source_label' => $row->source === 'manual_hr' ? 'Manual HR' : 'Self Service',
            'status' => (string) $row->status,
            'status_label' => match ((string) $row->status) { 'completed' => 'Selesai', 'open' => 'Sedang Lembur', 'cancelled' => 'Dibatalkan', default => (string) $row->status },
            'attendance_checkout_at' => $this->localTimestamp($row->attendance_checkout_at ?? null, $this->safeTimezone((string) ($row->attendance_checkout_timezone ?? $timezone))),
            'start_at' => $this->localTimestamp($row->start_at ?? null, $timezone),
            'end_at' => $this->localTimestamp($row->end_at ?? null, $timezone),
            'overtime_minutes' => $minutes,
            'overtime_hours' => round($minutes / 60, 2),
            'overtime_hours_label' => $this->durationLabel($minutes),
            'overtime_rate_snapshot' => round((float) ($row->overtime_rate_snapshot ?? 0), 2),
            'amount_snapshot' => round((float) ($row->amount_snapshot ?? 0), 2),
            'note' => (string) ($row->note ?? ''),
            'manual_reason' => (string) ($row->manual_reason ?? ''),
        ];
    }

    private function audit(HrOvertimeI06 $row, string $action, User $actor, string $note, ?array $before, array $after): void
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

    private function snapshot(HrOvertimeI06 $row): array
    {
        return [
            'id' => (string) $row->id,
            'attendance_id' => $row->attendance_id ? (string) $row->attendance_id : null,
            'employee_id' => (string) $row->employee_id,
            'business_date' => $this->dateString($row->business_date),
            'source' => (string) $row->source,
            'status' => (string) $row->status,
            'start_at' => $row->getRawOriginal('start_at'),
            'end_at' => $row->getRawOriginal('end_at'),
            'overtime_minutes' => (int) $row->overtime_minutes,
            'overtime_rate_snapshot' => (float) $row->overtime_rate_snapshot,
            'amount_snapshot' => (float) $row->amount_snapshot,
            'note' => (string) ($row->note ?? ''),
            'manual_reason' => (string) ($row->manual_reason ?? ''),
        ];
    }

    private function parseLocal(string $value, string $timezone, string $field): CarbonImmutable
    {
        try { return CarbonImmutable::parse($value, $timezone); }
        catch (\Throwable) { throw ValidationException::withMessages([$field => ['Tanggal/jam tidak valid.']]); }
    }

    private function utcMoment(mixed $value): ?CarbonImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        try { return CarbonImmutable::parse($raw, 'UTC'); } catch (\Throwable) { return null; }
    }

    private function localTimestamp(mixed $value, string $timezone): ?string
    {
        $moment = $this->utcMoment($value);
        return $moment ? $moment->setTimezone($timezone)->format('Y-m-d H:i:s') : null;
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Jakarta';
    }

    private function dateString(mixed $value): string
    {
        if ($value && method_exists($value, 'format')) return $value->format('Y-m-d');
        return substr((string) ($value ?? ''), 0, 10);
    }

    private function durationLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return intdiv($minutes, 60).'j '.($minutes % 60).'m';
    }

    private function emptyPage(Request $request): array
    {
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE, true)) $perPage = 25;
        return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
    }
}
