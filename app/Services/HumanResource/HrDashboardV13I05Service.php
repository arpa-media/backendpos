<?php

namespace App\Services\HumanResource;

use App\Services\UserManagementService;
use App\Support\HumanResource\HrV13I05ManpowerAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Dashboard extension: read-only, outlet-scoped, no Android or posting dependencies. */
final class HrDashboardV13I05Service
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrOutletStaffingService $staffing,
    ) {}

    public function build(Request $request, array $i13): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        $outlets = Schema::hasTable('outlets') && $allowed !== []
            ? $this->scope->allowedOutletQuery($request)->get($this->outletColumns())
            : collect();
        $canOutlet = $this->hasAnyPermission($request, ['hr.outlet.view','hr.outlet.staffing.manage']);
        $canSquad = $this->hasAnyPermission($request, ['hr.squad.view','hr.squads.view','hr.outlet.view']);
        $canAttendance = $this->hasPermission($request, 'hr.attendance.data.view');
        $canSp = $this->hasAnyPermission($request, ['hr.sp.view','hr.punishment.view']);
        $canRecruitment = $this->hasPermission($request, 'hr.recruitment.view');
        $today = (string) data_get($i13, 'date.today', now('Asia/Jakarta')->toDateString());
        $timezone = (string) data_get($i13, 'date.timezone', 'Asia/Jakarta');

        // A user without access to a domain does not receive its underlying metrics.
        $manpower = $canOutlet ? $this->manpowerFor($outlets) : null;

        return [
            'contract' => 'hr-dashboard-v13-i05',
            'outlets' => $canOutlet ? $this->outletSummary($outlets) : null,
            'squads' => $canSquad ? $this->squadSummary($outlets) : null,
            'manpower' => $manpower,
            'attendance' => $canAttendance ? $this->absentToday($allowed, $today) : ['absent' => null, 'available' => false],
            'top_outside_radius_outlets' => $canAttendance ? $this->topOutsideRadiusOutlets($allowed, $today) : [],
            'requests' => ['sp_pending' => $canSp ? $this->spPending($allowed) : null],
            'recruitment' => ['vacancy_published' => $canRecruitment ? $this->publishedVacancies($allowed, $today) : null],
            'visibility' => [
                'outlet' => $canOutlet, 'squad' => $canSquad,
                'manpower' => $canOutlet, 'attendance' => $canAttendance,
                'sp' => $canSp, 'vacancy' => $canRecruitment,
            ],
            'meta' => ['as_of' => $today, 'timezone' => $timezone,
                'manpower_source' => 'HR_outlet_staffing_slots + HrOutletStaffingService',
                'region_source' => 'outlets.hr_region',
                'not_present_rule' => 'shift finished+60min, no checkin, no approved leave',
            ],
        ];
    }

    private function manpowerFor($outlets): array
    {
        $operational = $outlets->filter(fn ($o) => strtolower((string) $o->type) === 'outlet')->values();
        $fingerprint = $operational->map(fn ($o) => [
            (string) $o->id, (string) ($o->hr_region ?? ''), (string) $o->code,
        ])->all();
        $key = 'hr-v13-i05-manpower-'.hash('sha256', json_encode($fingerprint));
        $calculate = fn () => HrV13I05ManpowerAggregator::aggregate(
            $operational->map(fn ($o) => (array) $o)->all(),
            $this->staffing->summariesForOutlets($operational)
        );
        // Short cache reduces the expensive cross-outlet assignment roster query.
        // Cache failures never prevent the dashboard from showing uncached data.
        try { return Cache::remember($key, 20, $calculate); }
        catch (\Throwable) { return $calculate(); }
    }

    private function outletColumns(): array
    {
        $columns = ['id', 'code', 'name', 'type'];
        if (Schema::hasColumn('outlets', 'hr_region')) $columns[] = 'hr_region';
        return $columns;
    }

    private function outletSummary($outlets): array
    {
        $summary = ['total' => 0, 'outlet' => 0, 'warehouse' => 0, 'headquarter' => 0];
        foreach ($outlets as $outlet) {
            $type = strtolower((string) $outlet->type);
            $type = in_array($type, ['outlet','warehouse','headquarter'], true) ? $type : 'outlet';
            $summary[$type]++;
            $summary['total']++;
        }
        return $summary;
    }

    private function squadSummary($outlets): array
    {
        $empty = ['active' => 0, 'inactive' => 0, 'management' => 0, 'warehouse' => 0];
        if (! Schema::hasTable('HR_squads') || $outlets->isEmpty()) return $empty;
        // Match the existing HR squad assignment convention (outlet ID, code or name).
        $keys = [];
        $typeByKey = [];
        foreach ($outlets as $outlet) {
            foreach ([(string) $outlet->id, (string) $outlet->code, (string) $outlet->name] as $raw) {
                $key = mb_strtolower(trim($raw));
                if ($key === '') continue;
                $keys[$key] = true;
                $typeByKey[$key] = strtolower((string) $outlet->type);
            }
        }
        if ($keys === [] || ! Schema::hasColumn('HR_squads','assignment')) return $empty;
        $query = DB::table('HR_squads')->whereIn(DB::raw('LOWER(TRIM(assignment))'), array_keys($keys));
        if (Schema::hasColumn('HR_squads','deleted_at')) $query->whereNull('deleted_at');
        // V13 I08: explicitly classified Non-Squad profiles are not operational Squad KPI.
        if (Schema::hasColumn('HR_squads','hr_is_non_squad')) $query->where('hr_is_non_squad',false);
        // Preserve the existing rule that Observer/Stakeholder are not operational squads.
        foreach (['role_name', 'access_role'] as $column) {
            if (Schema::hasColumn('HR_squads', $column)) {
                $query->whereRaw("UPPER(TRIM(COALESCE(`{$column}`, ''))) NOT IN ('STAKEHOLDER','OBSERVER')");
            }
        }
        if (Schema::hasColumn('HR_squads', 'user_id') && Schema::hasTable('user_access_assignments') && Schema::hasTable('access_roles')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('user_access_assignments as scoped_uaa')
                ->join('access_roles as scoped_ar', 'scoped_ar.id','=','scoped_uaa.access_role_id')
                ->whereColumn('scoped_uaa.user_id', 'HR_squads.user_id')
                ->whereIn('scoped_ar.code', ['STAKEHOLDER','OBSERVER']));
        }
        foreach ($query->get(['assignment','status']) as $row) {
            $status = strtolower(trim((string) ($row->status ?? 'active')));
            if (in_array($status, ['active','aktif',''], true)) $empty['active']++;
            else $empty['inactive']++;
            $type = $typeByKey[mb_strtolower(trim((string) $row->assignment))] ?? '';
            if (in_array($type, ['headquarter','hq'], true)) $empty['management']++;
            if (in_array($type, ['warehouse','gudang'], true)) $empty['warehouse']++;
        }
        return $empty;
    }

    private function publishedVacancies(array $allowed, string $today): int
    {
        if ($allowed === [] || ! Schema::hasTable('HR_recruitments') || ! Schema::hasTable('HR_recruitment_positions')) return 0;
        return DB::table('HR_recruitments as r')
            ->where('r.status','published')->whereNull('r.deleted_at')
            ->where(fn ($q) => $q->whereNull('r.active_from')->orWhereDate('r.active_from','<=',$today))
            ->where(fn ($q) => $q->whereNull('r.active_until')->orWhereDate('r.active_until','>=',$today))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('HR_recruitment_positions as p')
                ->whereColumn('p.recruitment_id','r.id')->whereNull('p.deleted_at')
                ->where('p.is_active',true)->whereIn('p.destination_outlet_id',$allowed))
            ->count();
    }

    private function spPending(array $allowed): int
    {
        if ($allowed === [] || ! Schema::hasTable('HR_warning_letters')) return 0;
        $query = DB::table('HR_warning_letters as w')
            ->whereIn('w.outlet_id', $allowed)->whereNull('w.deleted_at');
        if (Schema::hasTable('HR_warning_letter_approvals')) {
            return $query->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('HR_warning_letter_approvals as a')->whereColumn('a.warning_letter_id','w.id')
                ->where('a.status','pending'))->distinct()->count('w.id');
        }
        return $query->where('w.status','submitted')->count();
    }

    private function topOutsideRadiusOutlets(array $allowed, string $today): array
    {
        if ($allowed === [] || ! Schema::hasTable('HR_attendances') || ! Schema::hasTable('outlets')) return [];
        return DB::table('HR_attendances as a')
            ->join('outlets as o', 'o.id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'))
            ->whereIn('o.id', $allowed)->where('a.business_date',$today)
            ->where('a.record_status','!=','cancelled')->whereNotNull('a.checkin_at')
            ->where(fn ($q) => $q->where('a.checkin_inside_radius', false)
                ->orWhereRaw("LOWER(COALESCE(a.checkin_location_status, '')) = 'outside'"))
            ->whereNotIn(DB::raw("LOWER(COALESCE(a.checkin_mode, 'normal'))"), ['field_duty','manual','manual_hr'])
            ->selectRaw('o.id AS outlet_id, o.code AS outlet_code, o.name AS outlet_name, COUNT(*) AS attendance_count')
            ->groupBy('o.id','o.code','o.name')
            ->orderByDesc('attendance_count')->orderBy('o.code')->limit(3)->get()
            ->map(fn ($r) => ['outlet_id' => (string) $r->outlet_id,
                'outlet_code' => (string) $r->outlet_code,
                'outlet_name' => (string) $r->outlet_name,
                'attendance_count' => (int) $r->attendance_count])->all();
    }

    /** The ongoing shift is NOT counted as absent. No schedule => unavailable rather than false zero. */
    private function absentToday(array $allowed, string $today): array
    {
        if ($allowed === [] || ! Schema::hasTable('HR_shift_schedules') || ! Schema::hasTable('HR_attendances')) {
            return ['absent' => null, 'available' => false];
        }
        $schedules = DB::table('HR_shift_schedules as s')
            ->join('employees as e','e.id','=','s.employee_id')
            ->leftJoin('users as u','u.id','=','e.user_id')
            ->whereIn('s.outlet_id', $allowed)->where('s.work_date', $today)
            ->where('s.schedule_type','shift')->whereNotNull('s.end_time_snapshot')
            ->when(Schema::hasColumn('users','is_active'), fn ($q) => $q->where(fn ($x) => $x->whereNull('u.id')->orWhere('u.is_active',true)))
            ->get(['s.employee_id','s.work_date','s.outlet_timezone_snapshot','s.start_time_snapshot',
                's.end_time_snapshot','s.is_overnight_snapshot']);
        if ($schedules->isEmpty()) return ['absent' => 0, 'available' => true];
        $employees = $schedules->pluck('employee_id')->unique()->all();
        $attended = DB::table('HR_attendances')->where('business_date',$today)
            ->whereIn('employee_id',$employees)->whereNotNull('checkin_at')
            ->where('record_status','!=','cancelled')->pluck('employee_id')->map(fn ($id) => (string) $id)->flip()->all();
        $onLeave = [];
        if (Schema::hasTable('HR_leave_requests')) {
            $onLeave = DB::table('HR_leave_requests')->where('status','approved')
                ->whereIn('employee_id',$employees)->whereDate('start_date','<=',$today)
                ->whereDate('end_date','>=',$today)->pluck('employee_id')
                ->map(fn ($id) => (string) $id)->flip()->all();
        }
        $utcNow = CarbonImmutable::now('UTC');
        $absent = 0;
        foreach ($schedules as $row) {
            $id = (string) $row->employee_id;
            if (isset($attended[$id]) || isset($onLeave[$id])) continue;
            $timezone = (string) ($row->outlet_timezone_snapshot ?: 'Asia/Jakarta');
            if (! in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Jakarta';
            try {
                $start = substr((string) $row->start_time_snapshot, 0, 5);
                $end = substr((string) $row->end_time_snapshot, 0, 5);
                if ($end === '') continue;
                $finish = CarbonImmutable::createFromFormat('Y-m-d H:i', $today.' '.$end, $timezone);
                if ((bool) $row->is_overnight_snapshot || ($start !== '' && $end <= $start)) $finish = $finish->addDay();
                if ($finish->addMinutes(60)->utc()->lte($utcNow)) $absent++;
            } catch (\Throwable) { /* Incomplete schedule snapshot is not evidence of absence. */ }
        }
        return ['absent' => $absent, 'available' => true];
    }

    private function hasAnyPermission(Request $request, array $permissions): bool
    {
        foreach ($permissions as $permission) if ($this->hasPermission($request, $permission)) return true;
        return false;
    }

    private function hasPermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) return false;
        try { if ($user->can($permission)) return true; } catch (\Throwable) {}
        try {
            $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
            if (in_array($permission, $snapshot['permissions'] ?? [], true)) return true;
            foreach (data_get($snapshot, 'access.menus', []) as $menu) {
                if (is_array($menu) && ($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
            }
        } catch (\Throwable) {}
        return false;
    }
}
