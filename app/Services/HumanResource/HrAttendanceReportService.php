<?php

namespace App\Services\HumanResource;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrAttendanceReportService
{
    private const PER_PAGE = [10, 25, 50, 100, 500];

    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrAttendanceReportDayPolicyRegistry $policies,
    ) {}

    public function options(Request $request): array
    {
        $outlets = collect($this->scope->options($request));
        $allowed = $outlets->pluck('id')->values()->all();
        $companyMappings = $this->companyMappings($allowed);
        $companyMeta = $this->companyMeta();

        $outlets = $outlets->map(function (array $outlet) use ($companyMappings) {
            $map = $companyMappings->get((string) $outlet['id']);
            return array_merge($outlet, [
                'company_code' => $map?->company_code ? (string) $map->company_code : null,
            ]);
        })->values();

        $companyCodes = $companyMappings->pluck('company_code')->filter()->unique()->sort()->values();
        $companies = $companyCodes->map(function ($code) use ($companyMeta) {
            $meta = $companyMeta->get((string) $code);
            return [
                'code' => (string) $code,
                'name' => (string) ($meta?->name ?? $code),
                'legal_name' => $meta?->legal_name ? (string) $meta->legal_name : null,
            ];
        })->values();

        return [
            'outlets' => $outlets,
            'companies' => $companies,
            'per_page_options' => self::PER_PAGE,
            'company_filter_available' => Schema::hasTable('finance_outlet_company_mappings'),
        ];
    }

    public function daily(Request $request): array
    {
        $date = (string) $request->query('date');
        $scopeOutlets = $this->scopedOutletIds($request, $date);
        if ($scopeOutlets === []) return $this->emptyResult($request);

        $requestedOutlet = trim((string) $request->query('outlet_id', ''));
        $companyCode = strtoupper(trim((string) $request->query('company_code', '')));
        $this->validateRequestedScope($scopeOutlets, $requestedOutlet, $companyCode, $date);

        $assignments = $this->activeAssignments($scopeOutlets, $date, $date);
        $scheduleRows = DB::table('HR_shift_schedules as s')
            ->whereDate('s.work_date', $date)
            ->whereIn('s.outlet_id', $scopeOutlets)
            ->get();
        $attendanceRows = DB::table('HR_attendances as a')
            ->whereDate('a.business_date', $date)
            ->whereIn(DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'), $scopeOutlets)
            ->get();
        $overtimeRows = Schema::hasTable('HR_overtimes')
            ? DB::table('HR_overtimes as o')
                ->whereDate('o.business_date', $date)
                ->whereIn('o.outlet_id', $scopeOutlets)
                ->get()
            : collect();

        $employeeIds = $assignments->pluck('employee_id')
            ->concat($scheduleRows->pluck('employee_id'))
            ->concat($attendanceRows->pluck('employee_id'))
            ->concat($overtimeRows->pluck('employee_id'))
            ->filter()->map(fn ($id) => (string) $id)->unique()->values();

        $employees = $this->employees($employeeIds->all());
        $schedules = $scheduleRows->keyBy(fn ($r) => (string) $r->employee_id);
        $attendances = $attendanceRows->keyBy(fn ($r) => (string) $r->employee_id);
        $overtimes = $overtimeRows->keyBy(fn ($r) => (string) $r->employee_id);
        $assignmentGroups = $assignments->groupBy(fn ($r) => (string) $r->employee_id);
        $metadata = $this->squadMetadata($employees->pluck('nisj')->filter()->all());
        $outletMeta = $this->outletMeta($scopeOutlets);
        $companyMappings = $this->companyMappings($scopeOutlets);
        $companyMeta = $this->companyMeta();

        $rows = $employeeIds->map(function ($employeeId) use (
            $date, $employees, $schedules, $attendances, $overtimes, $assignmentGroups, $metadata,
            $outletMeta, $companyMappings, $companyMeta, $requestedOutlet, $companyCode
        ) {
            $employee = $employees->get($employeeId);
            if (! $employee || ! (bool) ($employee->user_is_active ?? false)) return null;

            $schedule = $schedules->get($employeeId);
            $attendance = $attendances->get($employeeId);
            $employeeAssignments = $assignmentGroups->get($employeeId, collect());
            $assignment = $this->pickAssignment($employeeAssignments, $schedule, $attendance, $requestedOutlet);

            $outletId = $schedule?->outlet_id
                ?: ($attendance?->assignment_outlet_id ?: $attendance?->checkin_outlet_id)
                ?: ($assignment?->outlet_id ?: null);
            $outletId = $outletId ? (string) $outletId : null;
            if (! $outletId || ! $outletMeta->has($outletId)) return null;
            if ($requestedOutlet !== '' && $outletId !== $requestedOutlet) return null;

            $resolvedCompany = $this->companyForOutlet($outletId, $date, $companyMappings);
            if ($companyCode !== '' && $resolvedCompany !== $companyCode) return null;

            $state = $this->dayState($date, $schedule, $attendance, [
                'employee' => $employee,
                'assignment' => $assignment,
                'outlet_id' => $outletId,
            ]);
            $state = array_merge($state, $this->overtimeState($overtimes->get($employeeId), (string) ($state['timezone'] ?? 'Asia/Jakarta')));
            $meta = $metadata->get($this->normalizeNisj($employee->nisj ?? null));
            $outlet = $outletMeta->get($outletId);
            $company = $resolvedCompany ? $companyMeta->get($resolvedCompany) : null;

            return array_merge([
                'employee_id' => $employeeId,
                'full_name' => (string) ($employee->full_name ?: $employee->username ?: '-'),
                'nisj' => (string) ($employee->nisj ?? ''),
                'position' => (string) ($assignment?->role_title ?: ($meta?->position_name ?? '-')),
                'work_date' => $date,
                'outlet_id' => $outletId,
                'outlet_name' => (string) ($schedule?->outlet_name_snapshot ?: ($outlet?->name ?? '-')),
                'company_code' => $resolvedCompany,
                'company_name' => $resolvedCompany ? (string) ($company?->name ?? $resolvedCompany) : '-',
            ], $state);
        })->filter()->values();

        $rows = $this->applySearch($rows, $request);
        return $this->sortAndPaginate($rows, $request, [
            'full_name', 'nisj', 'work_date', 'company_code', 'outlet_name', 'position',
            'schedule_label', 'shift_name', 'start_time', 'checkin_time', 'checkout_time',
            'location_method', 'location_detail', 'late_minutes', 'work_minutes', 'overtime_minutes', 'calculation_status_label',
        ], 'full_name', 'asc');
    }

    public function late(Request $request): array
    {
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $scopeOutlets = $this->scopedOutletIds($request, $to);
        if ($scopeOutlets === []) return $this->emptyResult($request);

        $requestedOutlet = trim((string) $request->query('outlet_id', ''));
        $companyCode = strtoupper(trim((string) $request->query('company_code', '')));
        $this->validateRequestedScope($scopeOutlets, $requestedOutlet, $companyCode, $to);

        $schedules = DB::table('HR_shift_schedules as s')
            ->join('employees as e', 'e.id', '=', 's.employee_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('HR_attendances as a', function ($join) {
                $join->on('a.employee_id', '=', 's.employee_id')
                    ->on('a.business_date', '=', 's.work_date');
            })
            ->whereBetween('s.work_date', [$from, $to])
            ->where('s.schedule_type', 'shift')
            ->whereIn('s.outlet_id', $scopeOutlets)
            ->where('u.is_active', true)
            ->whereNotNull('a.id')
            ->select([
                's.*',
                'e.full_name', 'e.nisj', 'e.id as employee_key',
                'a.id as attendance_id', 'a.record_status', 'a.checkin_at', 'a.checkout_at',
                'a.checkout_business_date', 'a.calculation_eligible', 'a.approval_required', 'a.approval_status',
                'a.checkin_inside_radius', 'a.checkout_inside_radius', 'a.checkin_mode', 'a.checkout_mode',
                'a.source', 'a.manual_reason', 'a.checkin_note', 'a.checkout_note',
                'a.checkin_outlet_name', 'a.checkout_outlet_name',
                'a.checkin_distance_m', 'a.checkin_radius_m', 'a.checkout_distance_m', 'a.checkout_radius_m',
                'a.shift_schedule_id', 'a.late_minutes', 'a.work_minutes', 'a.calculation_status', 'a.calculated_at',
            ])
            ->get();

        $companyMappings = $this->companyMappings($scopeOutlets);
        $companyMeta = $this->companyMeta();
        $metadata = $this->squadMetadata($schedules->pluck('nisj')->filter()->all());
        $rows = $schedules->map(function ($row) use ($companyMappings, $companyMeta, $metadata, $requestedOutlet, $companyCode) {
            $outletId = (string) $row->outlet_id;
            if ($requestedOutlet !== '' && $outletId !== $requestedOutlet) return null;
            $workDate = (string) $row->work_date;
            $resolvedCompany = $this->companyForOutlet($outletId, $workDate, $companyMappings);
            if ($companyCode !== '' && $resolvedCompany !== $companyCode) return null;

            $attendance = (object) [
                'id' => $row->attendance_id,
                'record_status' => $row->record_status,
                'checkin_at' => $row->checkin_at,
                'checkout_at' => $row->checkout_at,
                'checkout_business_date' => $row->checkout_business_date,
                'calculation_eligible' => $row->calculation_eligible,
                'approval_required' => $row->approval_required,
                'approval_status' => $row->approval_status,
                'checkin_inside_radius' => $row->checkin_inside_radius,
                'checkout_inside_radius' => $row->checkout_inside_radius,
                'checkin_mode' => $row->checkin_mode,
                'checkout_mode' => $row->checkout_mode,
                'source' => $row->source ?? null,
                'manual_reason' => $row->manual_reason ?? null,
                'checkin_note' => $row->checkin_note ?? null,
                'checkout_note' => $row->checkout_note ?? null,
                'checkin_outlet_name' => $row->checkin_outlet_name,
                'checkout_outlet_name' => $row->checkout_outlet_name,
                'checkin_distance_m' => $row->checkin_distance_m ?? null,
                'checkin_radius_m' => $row->checkin_radius_m ?? null,
                'checkout_distance_m' => $row->checkout_distance_m ?? null,
                'checkout_radius_m' => $row->checkout_radius_m ?? null,
                'shift_schedule_id' => $row->shift_schedule_id ?? null,
                'late_minutes' => $row->late_minutes ?? null,
                'work_minutes' => $row->work_minutes ?? null,
                'calculation_status' => $row->calculation_status ?? null,
                'calculated_at' => $row->calculated_at ?? null,
            ];
            $state = $this->dayState($workDate, $row, $attendance, ['outlet_id' => $outletId]);
            if ((int) ($state['late_minutes'] ?? 0) <= 0) return null;
            $meta = $metadata->get($this->normalizeNisj($row->nisj ?? null));
            $company = $resolvedCompany ? $companyMeta->get($resolvedCompany) : null;

            return array_merge([
                'employee_id' => (string) $row->employee_key,
                'full_name' => (string) ($row->full_name ?: '-'),
                'nisj' => (string) ($row->nisj ?? ''),
                'position' => (string) ($meta?->position_name ?? '-'),
                'work_date' => $workDate,
                'outlet_id' => $outletId,
                'outlet_name' => (string) ($row->outlet_name_snapshot ?: '-'),
                'company_code' => $resolvedCompany,
                'company_name' => $resolvedCompany ? (string) ($company?->name ?? $resolvedCompany) : '-',
            ], $state);
        })->filter()->unique(fn ($r) => $r['employee_id'].'|'.$r['work_date'])->values();

        $rows = $this->applySearch($rows, $request);
        return $this->sortAndPaginate($rows, $request, [
            'full_name', 'nisj', 'work_date', 'company_code', 'outlet_name', 'position',
            'shift_name', 'start_time', 'checkin_time', 'late_minutes', 'location_method',
        ], 'work_date', 'desc');
    }

    public function recap(Request $request): array
    {
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $scopeOutlets = $this->scopedOutletIds($request, $to);
        if ($scopeOutlets === []) return $this->emptyResult($request, ['summary' => $this->emptyRecapSummary()]);

        $requestedOutlet = trim((string) $request->query('outlet_id', ''));
        $companyCode = strtoupper(trim((string) $request->query('company_code', '')));
        $this->validateRequestedScope($scopeOutlets, $requestedOutlet, $companyCode, $to);
        $companyMappings = $this->companyMappings($scopeOutlets);
        $companyMeta = $this->companyMeta();
        $outletMeta = $this->outletMeta($scopeOutlets);

        $groups = collect();
        $ensureGroup = function (string $employeeId, string $outletId, ?object $employee = null, ?object $assignment = null) use (&$groups, $outletMeta, $companyMappings, $companyMeta, $to, $requestedOutlet, $companyCode) {
            if ($requestedOutlet !== '' && $outletId !== $requestedOutlet) return null;
            $resolvedCompany = $this->companyForOutlet($outletId, $to, $companyMappings);
            if ($companyCode !== '' && $resolvedCompany !== $companyCode) return null;
            $key = $employeeId.'|'.$outletId;
            if (! $groups->has($key)) {
                $outlet = $outletMeta->get($outletId);
                $company = $resolvedCompany ? $companyMeta->get($resolvedCompany) : null;
                $groups->put($key, [
                    'employee_id' => $employeeId,
                    'outlet_id' => $outletId,
                    'outlet_name' => (string) ($outlet?->name ?? '-'),
                    'company_code' => $resolvedCompany,
                    'company_name' => $resolvedCompany ? (string) ($company?->name ?? $resolvedCompany) : '-',
                    'full_name' => (string) ($employee?->full_name ?? '-'),
                    'nisj' => (string) ($employee?->nisj ?? ''),
                    'position' => (string) ($assignment?->role_title ?? '-'),
                    'scheduled_shift_days' => 0,
                    'off_days' => 0,
                    'work_days' => 0,
                    'work_minutes' => 0,
                    'overtime_minutes' => 0,
                    'late_minutes' => 0,
                    'alpha_days' => 0,
                    'unmapped_attendance_days' => 0,
                    'pending_exception_days' => 0,
                    'rejected_exception_days' => 0,
                    'incomplete_days' => 0,
                    'off_attendance_days' => 0,
                    'recovered_checkout_days' => 0,
                ]);
            }
            return $key;
        };

        // Universe: active assignments that overlap the selected period.
        $assignments = $this->activeAssignments($scopeOutlets, $from, $to);
        $employeeIds = $assignments->pluck('employee_id')->filter()->map(fn ($v) => (string) $v)->unique()->values();
        $employees = $this->employees($employeeIds->all());
        foreach ($assignments as $assignment) {
            $employee = $employees->get((string) $assignment->employee_id);
            if (! $employee || ! (bool) ($employee->user_is_active ?? false)) continue;
            $ensureGroup((string) $assignment->employee_id, (string) $assignment->outlet_id, $employee, $assignment);
        }

        $schedules = DB::table('HR_shift_schedules as s')
            ->join('employees as e', 'e.id', '=', 's.employee_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereBetween('s.work_date', [$from, $to])
            ->whereIn('s.outlet_id', $scopeOutlets)
            ->where('u.is_active', true)
            ->select(['s.*', 'e.full_name', 'e.nisj'])
            ->get();
        $scheduleEmployeeIds = $schedules->pluck('employee_id')->map(fn ($v) => (string) $v)->unique();

        $attendanceRows = DB::table('HR_attendances as a')
            ->whereBetween('a.business_date', [$from, $to])
            ->whereIn(DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'), $scopeOutlets)
            ->get();
        $overtimeRows = Schema::hasTable('HR_overtimes')
            ? DB::table('HR_overtimes as o')
                ->whereBetween('o.business_date', [$from, $to])
                ->whereIn('o.outlet_id', $scopeOutlets)
                ->where('o.status', 'completed')
                ->get()
            : collect();
        $attendanceEmployeeIds = $attendanceRows->pluck('employee_id')->filter()->map(fn ($v) => (string) $v)->unique();
        $overtimeEmployeeIds = $overtimeRows->pluck('employee_id')->filter()->map(fn ($v) => (string) $v)->unique();
        $allEmployeeIds = $employeeIds->concat($scheduleEmployeeIds)->concat($attendanceEmployeeIds)->concat($overtimeEmployeeIds)->unique()->values();
        $employees = $this->employees($allEmployeeIds->all());
        $attendanceMap = $attendanceRows->keyBy(fn ($a) => (string) $a->employee_id.'|'.(string) $a->business_date);
        $scheduleKeys = collect();

        foreach ($schedules as $schedule) {
            $employeeId = (string) $schedule->employee_id;
            $outletId = (string) $schedule->outlet_id;
            $employee = $employees->get($employeeId) ?: (object) ['full_name' => $schedule->full_name, 'nisj' => $schedule->nisj];
            $key = $ensureGroup($employeeId, $outletId, $employee, null);
            if (! $key) continue;
            $group = $groups->get($key);
            if ($group['full_name'] === '-' && $schedule->full_name) $group['full_name'] = (string) $schedule->full_name;
            if ($group['nisj'] === '' && $schedule->nisj) $group['nisj'] = (string) $schedule->nisj;

            $date = (string) $schedule->work_date;
            $scheduleKeys->push($employeeId.'|'.$date);
            $attendance = $attendanceMap->get($employeeId.'|'.$date);
            $state = $this->dayState($date, $schedule, $attendance, ['employee' => $employee, 'outlet_id' => $outletId]);

            if ($schedule->schedule_type === 'off') {
                $group['off_days']++;
                if ($attendance) $group['off_attendance_days']++;
            } else {
                $group['scheduled_shift_days']++;

                // Pending exception tetap boleh menampilkan metrik raw untuk kebutuhan review.
                // Namun exception yang FINAL REJECTED bukan attendance yang diakui:
                // tidak menambah terlambat, jam kerja, recovered checkout, maupun hari kerja.
                if ($attendance && ($state['calculation_status'] ?? '') !== 'rejected') {
                    $group['late_minutes'] += (int) ($state['late_minutes'] ?? 0);
                    if (($attendance->record_status ?? '') === 'complete' && ! empty($attendance->checkout_at)) {
                        $group['work_minutes'] += (int) ($state['work_minutes'] ?? 0);
                    }
                    if (($state['recovered_checkout'] ?? false) === true) $group['recovered_checkout_days']++;
                }

                if (($state['counts_as_work_day'] ?? false) === true) {
                    $group['work_days']++;
                } elseif (($state['counts_as_alpha'] ?? false) === true) {
                    $group['alpha_days']++;
                } elseif (($state['calculation_status'] ?? '') === 'pending_approval') {
                    $group['pending_exception_days']++;
                } elseif (($state['calculation_status'] ?? '') === 'rejected') {
                    $group['rejected_exception_days']++;
                } elseif (($state['calculation_status'] ?? '') === 'incomplete') {
                    $group['incomplete_days']++;
                }
            }
            $groups->put($key, $group);
        }

        // Attendance without schedule is visible as Unmapped but never counted as work/late.
        $scheduleKeySet = $scheduleKeys->flip();
        foreach ($attendanceRows as $attendance) {
            $employeeId = (string) $attendance->employee_id;
            $dateKey = $employeeId.'|'.(string) $attendance->business_date;
            if ($scheduleKeySet->has($dateKey)) continue;
            $outletId = (string) ($attendance->assignment_outlet_id ?: $attendance->checkin_outlet_id ?: '');
            if ($outletId === '') continue;
            $employee = $employees->get($employeeId);
            if (! $employee || ! (bool) ($employee->user_is_active ?? false)) continue;
            $key = $ensureGroup($employeeId, $outletId, $employee, null);
            if (! $key) continue;
            $group = $groups->get($key);
            $group['unmapped_attendance_days']++;
            $groups->put($key, $group);
        }

        // Lembur is independent from work-duration minutes. Only completed overtime
        // contributes to recap; open/cancelled sessions remain visible on Data Lembur only.
        foreach ($overtimeRows as $overtime) {
            $employeeId = (string) ($overtime->employee_id ?? '');
            $outletId = (string) ($overtime->outlet_id ?? '');
            if ($employeeId === '' || $outletId === '') continue;
            $employee = $employees->get($employeeId);
            if (! $employee || ! (bool) ($employee->user_is_active ?? false)) continue;
            $key = $ensureGroup($employeeId, $outletId, $employee, null);
            if (! $key) continue;
            $group = $groups->get($key);
            $group['overtime_minutes'] += max(0, (int) ($overtime->overtime_minutes ?? 0));
            $groups->put($key, $group);
        }

        $metadata = $this->squadMetadata($groups->pluck('nisj')->filter()->all());
        $rows = $groups->map(function (array $row) use ($metadata) {
            $meta = $metadata->get($this->normalizeNisj($row['nisj'] ?? null));
            if (($row['position'] ?? '-') === '-' && $meta?->position_name) $row['position'] = (string) $meta->position_name;
            $row['work_hours'] = round(((int) $row['work_minutes']) / 60, 2);
            $row['work_hours_label'] = $this->durationLabel((int) $row['work_minutes']);
            $row['overtime_hours'] = round(((int) $row['overtime_minutes']) / 60, 2);
            $row['overtime_hours_label'] = $this->durationLabel((int) $row['overtime_minutes']);
            return $row;
        })->values();
        $rows = $this->applySearch($rows, $request);

        $summary = [
            'employees' => $rows->pluck('employee_id')->unique()->count(),
            'rows' => $rows->count(),
            'work_days' => (int) $rows->sum('work_days'),
            'work_minutes' => (int) $rows->sum('work_minutes'),
            'work_hours_label' => $this->durationLabel((int) $rows->sum('work_minutes')),
            'overtime_minutes' => (int) $rows->sum('overtime_minutes'),
            'overtime_hours' => round(((int) $rows->sum('overtime_minutes')) / 60, 2),
            'overtime_hours_label' => $this->durationLabel((int) $rows->sum('overtime_minutes')),
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'alpha_days' => (int) $rows->sum('alpha_days'),
            'unmapped_attendance_days' => (int) $rows->sum('unmapped_attendance_days'),
            'pending_exception_days' => (int) $rows->sum('pending_exception_days'),
        ];

        $result = $this->sortAndPaginate($rows, $request, [
            'full_name', 'nisj', 'company_code', 'outlet_name', 'position',
            'scheduled_shift_days', 'work_days', 'work_minutes', 'overtime_minutes', 'late_minutes', 'alpha_days',
            'unmapped_attendance_days', 'pending_exception_days', 'rejected_exception_days', 'incomplete_days',
        ], 'full_name', 'asc');
        $result['summary'] = $summary;
        return $result;
    }

    private function dayState(string $date, ?object $schedule, ?object $attendance, array $context = []): array
    {
        $scheduleType = strtolower((string) ($schedule?->schedule_type ?? ''));
        $timezone = $this->safeTimezone((string) ($schedule?->outlet_timezone_snapshot ?? $attendance?->attendance_timezone ?? ''));
        $base = [
            'schedule_type' => $scheduleType !== '' ? $scheduleType : 'unmapped',
            'schedule_label' => $scheduleType === 'off' ? 'OFF' : ($scheduleType === 'shift' ? 'Terjadwal' : 'Unmapped'),
            'shift_name' => $scheduleType === 'shift' ? (string) ($schedule?->shift_name_snapshot ?: 'Unmapped') : ($scheduleType === 'off' ? 'OFF' : 'Unmapped'),
            'start_time' => $scheduleType === 'shift' ? $this->timeHm($schedule?->start_time_snapshot) : null,
            'end_time' => $scheduleType === 'shift' ? $this->timeHm($schedule?->end_time_snapshot) : null,
            'timezone' => $timezone,
            'attendance_id' => $attendance?->id ? (string) $attendance->id : null,
            'attendance_source' => (string) ($attendance?->source ?? ''),
            'is_manual_hr' => strtoupper((string) ($attendance?->source ?? '')) === 'MANUAL_HR',
            'checkin_time' => $this->localTime($attendance?->checkin_at, $timezone),
            'checkout_time' => $this->localTime($attendance?->checkout_at, $timezone),
            'checkin_local_at' => $this->localDateTime($attendance?->checkin_at, $timezone),
            'checkout_local_at' => $this->localDateTime($attendance?->checkout_at, $timezone),
            'location_method' => $this->locationMethod($attendance),
            'location_detail' => $this->locationDetail($attendance),
            'attendance_record_status' => (string) ($attendance?->record_status ?? 'none'),
            'approval_status' => (string) ($attendance?->approval_status ?? 'not_required'),
            'calculation_eligible_raw' => (bool) ($attendance?->calculation_eligible ?? false),
            'calculation_status' => 'unmapped',
            'calculation_status_label' => 'Unmapped',
            'counts_as_work_day' => false,
            'counts_as_alpha' => false,
            'late_minutes' => 0,
            'work_minutes' => 0,
            'work_hours' => 0.0,
            'work_hours_label' => '0j 0m',
            'recovered_checkout' => false,
            'work_time_source' => null,
        ];

        if (! $schedule) {
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        if ($scheduleType === 'off') {
            $base['calculation_status'] = $attendance ? 'off_attendance' : 'off';
            $base['calculation_status_label'] = $attendance ? 'Absen di Hari OFF' : 'OFF';
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        if ($scheduleType !== 'shift') {
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        if (! $attendance) {
            $base['calculation_status'] = 'alpha';
            $base['calculation_status_label'] = 'Alpha';
            $base['counts_as_alpha'] = true;
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        // Iterasi 21 decouples raw time metrics from approval state. Late is available as soon
        // as check-in exists; work duration becomes available at checkout. Approval only gates
        // whether the completed record counts toward payroll/work-day recap.
        [$late, $work, $recovered, $source] = $this->calculateShiftMetrics($date, $schedule, $attendance, $timezone);
        $samePersistedSchedule = filled($attendance->shift_schedule_id ?? null)
            && (string) ($attendance->shift_schedule_id ?? '') === (string) ($schedule->id ?? '');
        if ($samePersistedSchedule && isset($attendance->late_minutes) && $attendance->late_minutes !== null) {
            $late = max(0, (int) $attendance->late_minutes);
        }
        if ($samePersistedSchedule && ! empty($attendance->checkout_at) && isset($attendance->work_minutes) && $attendance->work_minutes !== null) {
            $work = max(0, (int) $attendance->work_minutes);
        }

        $base['late_minutes'] = $late;
        $base['work_minutes'] = $work;
        $base['work_hours'] = round($work / 60, 2);
        $base['work_hours_label'] = $this->durationLabel($work);
        $base['recovered_checkout'] = $recovered;
        $base['work_time_source'] = $source;

        // Final rejection takes precedence over incomplete/complete state.
        // A rejected outside-radius/exception attendance is not recognized for any
        // attendance calculation, while the persisted raw timestamps remain intact for audit.
        $approval = strtolower((string) ($attendance->approval_status ?? 'pending'));
        $isRejected = ! (bool) ($attendance->calculation_eligible ?? false) && $approval === 'rejected';
        if ($isRejected) {
            $base['calculation_status'] = 'rejected';
            $base['calculation_status_label'] = 'Exception Ditolak';
            $base['late_minutes'] = 0;
            $base['work_minutes'] = 0;
            $base['work_hours'] = 0.0;
            $base['work_hours_label'] = '0j 0m';
            $base['recovered_checkout'] = false;
            $base['work_time_source'] = 'rejected_exception';
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        if (($attendance->record_status ?? '') !== 'complete' || empty($attendance->checkout_at)) {
            $base['calculation_status'] = 'incomplete';
            $base['calculation_status_label'] = 'Hadir - Belum Absen Pulang';
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        if (! (bool) ($attendance->calculation_eligible ?? false)) {
            $base['calculation_status'] = 'pending_approval';
            $base['calculation_status_label'] = 'Hadir - Menunggu Approval';
            return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
        }

        $base['calculation_status'] = 'present';
        $base['calculation_status_label'] = $recovered ? 'Hadir · Checkout Recovery' : 'Hadir';
        $base['counts_as_work_day'] = true;

        return $this->policies->apply($base, array_merge($context, compact('date', 'schedule', 'attendance')));
    }

    private function calculateShiftMetrics(string $date, object $schedule, object $attendance, string $timezone): array
    {
        $start = $this->localScheduleMoment($date, $schedule->start_time_snapshot ?? null, $timezone);
        $end = $this->localScheduleMoment($date, $schedule->end_time_snapshot ?? null, $timezone);
        if (! $start || ! $end) return [0, 0, false, 'invalid_schedule'];
        if ((bool) ($schedule->is_overnight_snapshot ?? false) || $end <= $start) $end = $end->addDay();

        $checkin = $this->utcMoment($attendance->checkin_at ?? null)?->setTimezone($timezone);
        $checkout = $this->utcMoment($attendance->checkout_at ?? null)?->setTimezone($timezone);
        if (! $checkin) return [0, 0, false, 'missing_checkin'];

        $late = $checkin > $start ? (int) floor($start->diffInSeconds($checkin) / 60) : 0;
        if (! $checkout) return [$late, 0, false, 'open_attendance'];

        $recovered = $checkout->toDateString() > $end->toDateString();
        if ($recovered) {
            $effectiveStart = $checkin > $start ? $checkin : $start;
            $work = $effectiveStart < $end ? (int) floor($effectiveStart->diffInSeconds($end) / 60) : 0;
            return [$late, max(0, $work), true, 'scheduled_cap_after_recovery'];
        }

        if ($checkout <= $checkin) return [$late, 0, false, 'invalid_timestamp_order'];
        $work = (int) floor($checkin->diffInSeconds($checkout) / 60);
        $work = max(0, min($work, 24 * 60));
        return [$late, $work, false, 'actual_timestamp'];
    }

    private function locationMethod(?object $attendance): string
    {
        if (! $attendance) return '-';
        $modes = [strtolower((string) ($attendance->checkin_mode ?? '')), strtolower((string) ($attendance->checkout_mode ?? ''))];
        if (strtoupper((string) ($attendance->source ?? '')) === 'MANUAL_HR' || in_array('manual_hr', $modes, true)) return 'Manual HR';
        if (in_array('field_duty', $modes, true)) return 'Dinas Luar';
        $insideIn = (bool) ($attendance->checkin_inside_radius ?? false);
        $insideOut = $attendance->checkout_at ? (bool) ($attendance->checkout_inside_radius ?? false) : $insideIn;
        return $insideIn && $insideOut ? 'Dalam Radius' : 'Luar Radius';
    }

    private function locationDetail(?object $attendance): string
    {
        if (! $attendance) return '-';
        $modes = [strtolower((string) ($attendance->checkin_mode ?? '')), strtolower((string) ($attendance->checkout_mode ?? ''))];
        if (strtoupper((string) ($attendance->source ?? '')) === 'MANUAL_HR' || in_array('manual_hr', $modes, true)) {
            $reason = trim((string) ($attendance->manual_reason ?? $attendance->checkin_note ?? ''));
            return $reason !== '' ? 'Manual HR · '.$reason : 'Manual HR';
        }

        $format = function (string $prefix) use ($attendance): string {
            $inside = (bool) ($attendance->{$prefix.'_inside_radius'} ?? false);
            $mode = strtolower((string) ($attendance->{$prefix.'_mode'} ?? ''));
            $label = $inside ? 'Dalam Radius' : ($mode === 'field_duty' ? 'Dinas Luar' : 'Luar Radius');

            // Iterasi 02: when the attendance is outside radius, show the actual
            // distance measured against the evaluated outlet and its configured radius.
            if (! $inside) {
                $distance = $attendance->{$prefix.'_distance_m'} ?? null;
                $radius = $attendance->{$prefix.'_radius_m'} ?? null;
                if ($distance !== null) $label .= ' · jarak '.number_format((float) $distance, 0, ',', '.').' m dari outlet';
                if ($radius !== null) $label .= ' · radius '.number_format((float) $radius, 0, ',', '.').' m';
            }

            return $label;
        };

        $in = $format('checkin');
        if (! $attendance->checkout_at) return 'Datang: '.$in;
        return 'Datang: '.$in.' · Pulang: '.$format('checkout');
    }

    private function activeAssignments(array $outletIds, string $from, string $to): Collection
    {
        if ($outletIds === []) return collect();
        return DB::table('assignments as x')
            ->join('employees as e', 'e.id', '=', 'x.employee_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereIn('x.outlet_id', $outletIds)
            ->where('u.is_active', true)
            ->where(function ($q) use ($to) { $q->whereNull('x.start_date')->orWhereDate('x.start_date', '<=', $to); })
            ->where(function ($q) use ($from) { $q->whereNull('x.end_date')->orWhereDate('x.end_date', '>=', $from); })
            ->where(function ($q) { $q->whereNull('x.status')->orWhereRaw("LOWER(x.status) NOT IN ('inactive','cancelled')"); })
            ->select(['x.*'])
            ->orderByDesc('x.is_primary')->orderByDesc('x.start_date')
            ->get();
    }

    private function employees(array $ids): Collection
    {
        if ($ids === []) return collect();
        return DB::table('employees as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->whereIn('e.id', $ids)
            ->select(['e.id', 'e.user_id', 'e.nisj', 'e.full_name', 'e.employment_status', 'u.username', 'u.is_active as user_is_active'])
            ->get()->keyBy(fn ($r) => (string) $r->id);
    }

    private function pickAssignment(Collection $rows, ?object $schedule, ?object $attendance, string $requestedOutlet): ?object
    {
        if ($rows->isEmpty()) return null;
        $targets = array_filter([
            $schedule?->outlet_id ? (string) $schedule->outlet_id : null,
            $attendance?->assignment_outlet_id ? (string) $attendance->assignment_outlet_id : null,
            $requestedOutlet !== '' ? $requestedOutlet : null,
        ]);
        foreach ($targets as $target) {
            $found = $rows->first(fn ($r) => (string) $r->outlet_id === $target);
            if ($found) return $found;
        }
        // activeAssignments() is already ordered primary first and newest start date first.
        return $rows->first();
    }

    private function squadMetadata(array $nisjs): Collection
    {
        if (! Schema::hasTable('HR_squads') || $nisjs === []) return collect();
        $normalized = collect($nisjs)->map(fn ($v) => $this->normalizeNisj($v))->filter()->unique()->values();
        if ($normalized->isEmpty()) return collect();
        return DB::table('HR_squads')
            ->whereNull('deleted_at')->whereIn('nisj', $normalized->all())
            ->get(['nisj', 'position_name', 'division_name', 'photo_path'])
            ->keyBy(fn ($r) => $this->normalizeNisj($r->nisj ?? null));
    }

    private function outletMeta(array $ids): Collection
    {
        if ($ids === []) return collect();
        return DB::table('outlets')->whereIn('id', $ids)->get(['id', 'code', 'name', 'timezone'])
            ->keyBy(fn ($r) => (string) $r->id);
    }

    private function companyMappings(array $allowedOutletIds): Collection
    {
        if (! Schema::hasTable('finance_outlet_company_mappings') || $allowedOutletIds === []) return collect();
        return DB::table('finance_outlet_company_mappings')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->where('is_active', true)
            ->get(['outlet_id', 'company_code', 'effective_from', 'effective_to'])
            ->keyBy(fn ($r) => (string) $r->outlet_id);
    }

    private function companyMeta(): Collection
    {
        if (Schema::hasTable('finance_companies')) {
            return DB::table('finance_companies')->where('is_active', true)->get(['code', 'name', 'legal_name'])
                ->keyBy(fn ($r) => (string) $r->code);
        }
        if (Schema::hasTable('HR_master_data')) {
            return DB::table('HR_master_data')->where('type', 'company')->where('is_active', true)->whereNull('deleted_at')
                ->get(['name'])->map(fn ($r) => (object) ['code' => $r->name, 'name' => $r->name, 'legal_name' => null])
                ->keyBy(fn ($r) => (string) $r->code);
        }
        return collect();
    }

    private function companyForOutlet(string $outletId, string $date, Collection $mappings): ?string
    {
        $row = $mappings->get($outletId);
        if (! $row) return null;
        if ($row->effective_from && $date < (string) $row->effective_from) return null;
        if ($row->effective_to && $date > (string) $row->effective_to) return null;
        return strtoupper((string) $row->company_code);
    }

    private function scopedOutletIds(Request $request, string $date): array
    {
        return $this->scope->allowedOutletIds($request);
    }

    private function validateRequestedScope(array $allowed, string $outletId, string $companyCode, string $date): void
    {
        if ($outletId !== '' && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet berada di luar scope user.']]);
        }
        if ($companyCode !== '') {
            $mappings = $this->companyMappings($allowed);
            $valid = $mappings->contains(fn ($row, $id) => $this->companyForOutlet((string) $id, $date, $mappings) === $companyCode);
            if (! $valid) throw ValidationException::withMessages(['company_code' => ['PT tidak tersedia pada scope outlet user untuk periode ini.']]);
            if ($outletId !== '' && $this->companyForOutlet($outletId, $date, $mappings) !== $companyCode) {
                throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak termasuk PT yang dipilih.']]);
            }
        }
    }

    private function applySearch(Collection $rows, Request $request): Collection
    {
        $name = mb_strtolower(trim((string) $request->query('name', '')));
        $nisj = mb_strtolower(trim((string) $request->query('nisj', '')));
        return $rows->filter(function ($row) use ($name, $nisj) {
            $row = (array) $row;
            if ($name !== '' && ! str_contains(mb_strtolower((string) ($row['full_name'] ?? '')), $name)) return false;
            if ($nisj !== '' && ! str_contains(mb_strtolower((string) ($row['nisj'] ?? '')), $nisj)) return false;
            return true;
        })->values();
    }

    private function sortAndPaginate(Collection $rows, Request $request, array $allowedSort, string $defaultSort, string $defaultDir): array
    {
        $sortBy = (string) $request->query('sort_by', $defaultSort);
        if (! in_array($sortBy, $allowedSort, true)) $sortBy = $defaultSort;
        $sortDir = strtolower((string) $request->query('sort_dir', $defaultDir)) === 'desc' ? 'desc' : 'asc';
        $rows = $rows->sortBy(function ($row) use ($sortBy) {
            $value = data_get($row, $sortBy);
            if (is_numeric($value)) return (float) $value;
            return mb_strtolower((string) ($value ?? ''));
        }, SORT_NATURAL | SORT_FLAG_CASE, $sortDir === 'desc')->values();

        // Internal-only switch used by the XLSX exporter. This avoids changing
        // public pagination semantics while guaranteeing that export is not cut
        // by the selected Show Data value.
        if ((bool) $request->attributes->get('hr_attendance_report_export_all', false)) {
            $total = $rows->count();
            return [
                'items' => $rows,
                'pagination' => [
                    'page' => 1, 'per_page' => $total, 'total' => $total,
                    'last_page' => 1, 'from' => $total ? 1 : null, 'to' => $total ?: null,
                ],
            ];
        }

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE, true)) $perPage = 25;
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query('page', 1), $lastPage));
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $from = $total ? (($page - 1) * $perPage) + 1 : null;
        $to = $total ? min($page * $perPage, $total) : null;

        return [
            'items' => $slice,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => $lastPage, 'from' => $from, 'to' => $to,
            ],
        ];
    }

    private function emptyResult(Request $request, array $extra = []): array
    {
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE, true)) $perPage = 25;
        return array_merge([
            'items' => [],
            'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null],
        ], $extra);
    }

    private function emptyRecapSummary(): array
    {
        return ['employees' => 0, 'rows' => 0, 'work_days' => 0, 'work_minutes' => 0, 'work_hours_label' => '0j 0m', 'overtime_minutes' => 0, 'overtime_hours' => 0.0, 'overtime_hours_label' => '0j 0m', 'late_minutes' => 0, 'alpha_days' => 0, 'unmapped_attendance_days' => 0, 'pending_exception_days' => 0];
    }

    private function utcMoment(mixed $value): ?CarbonImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        try { return CarbonImmutable::parse($raw, 'UTC'); } catch (\Throwable) { return null; }
    }

    private function localScheduleMoment(string $date, mixed $time, string $timezone): ?CarbonImmutable
    {
        $hm = $this->timeHm($time);
        if (! $hm) return null;
        try { return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$hm, $timezone); } catch (\Throwable) { return null; }
    }

    private function localTime(mixed $value, string $timezone): ?string
    {
        $moment = $this->utcMoment($value);
        return $moment ? $moment->setTimezone($timezone)->format('H:i') : null;
    }

    private function localDateTime(mixed $value, string $timezone): ?string
    {
        $moment = $this->utcMoment($value);
        return $moment ? $moment->setTimezone($timezone)->format('Y-m-d H:i:s') : null;
    }

    private function timeHm(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : substr($raw, 0, 5);
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Jakarta';
    }

    private function overtimeState(?object $overtime, string $timezone): array
    {
        $minutes = $overtime && (string) ($overtime->status ?? '') === 'completed'
            ? max(0, (int) ($overtime->overtime_minutes ?? 0))
            : 0;
        $start = $overtime ? $this->localTime($overtime->start_at ?? null, $timezone) : null;
        $end = $overtime ? $this->localTime($overtime->end_at ?? null, $timezone) : null;

        return [
            'overtime_id' => $overtime?->id ? (string) $overtime->id : null,
            'overtime_status' => (string) ($overtime?->status ?? 'none'),
            'overtime_source' => (string) ($overtime?->source ?? ''),
            'overtime_start_time' => $start,
            'overtime_end_time' => $end,
            'overtime_start_local_at' => $overtime ? $this->localDateTime($overtime->start_at ?? null, $timezone) : null,
            'overtime_end_local_at' => $overtime ? $this->localDateTime($overtime->end_at ?? null, $timezone) : null,
            'overtime_minutes' => $minutes,
            'overtime_hours' => round($minutes / 60, 2),
            'overtime_hours_label' => $this->durationLabel($minutes),
        ];
    }

    private function durationLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return intdiv($minutes, 60).'j '.($minutes % 60).'m';
    }

    private function normalizeNisj(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
