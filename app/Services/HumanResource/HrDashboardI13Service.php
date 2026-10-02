<?php

namespace App\Services\HumanResource;

use App\Services\UserManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrDashboardI13Service
{
    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function build(Request $request): array
    {
        $timezone = (string) ($request->attributes->get('outlet_timezone') ?: config('app.timezone', 'Asia/Jakarta'));
        if (! in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Jakarta';

        $now = CarbonImmutable::now($timezone);
        $today = $now->toDateString();
        $thisMonth = [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];
        $previous = $now->subMonthNoOverflow();
        $previousMonth = [$previous->startOfMonth()->toDateString(), $previous->endOfMonth()->toDateString()];
        $allowedOutletIds = $this->scope->allowedOutletIds($request);

        $canAttendance = $this->hasPermission($request, 'hr.attendance.data.view');
        $canLeaveApproval = $this->hasPermission($request, 'hr.leave.approval.view');
        $canAttendanceApproval = $this->hasPermission($request, 'hr.attendance.approval.view');
        $canDutyApproval = $this->hasPermission($request, 'hr.attendance.duty.view');
        $canRecruitment = $this->hasPermission($request, 'hr.recruitment.applicant_register.view');
        $canPayroll = $this->hasPermission($request, 'hr.payroll.cutoff.view');
        $canBonus = $this->hasAnyPermission($request, ['hr.bonus.projection.view', 'hr.bonus.cutoff.view']);

        $attendance = $canAttendance ? $this->attendanceToday($request, $today) : ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'late' => 0, 'outside_radius' => 0];

        return [
            'date' => [
                'today' => $today,
                'timezone' => $timezone,
                'current_month' => ['from' => $thisMonth[0], 'to' => $thisMonth[1], 'label' => $now->translatedFormat('F Y')],
                'previous_month' => ['from' => $previousMonth[0], 'to' => $previousMonth[1], 'label' => $previous->translatedFormat('F Y')],
            ],
            'attendance' => $attendance,
            'top_late_outlets' => $canAttendance ? $this->topLateOutlets($request, $today) : [],
            'top_outside_radius_attendances' => $canAttendance ? $this->topOutsideRadiusAttendances($request, $today) : [],
            'requests' => [
                'leave_pending' => $canLeaveApproval ? $this->leavePending($allowedOutletIds) : 0,
                'attendance_approval_pending' => $canAttendanceApproval ? $this->attendanceApprovalPending($request, 'attendance_exception') : 0,
                'field_duty_approval_pending' => $canDutyApproval ? $this->attendanceApprovalPending($request, 'field_duty') : 0,
            ],
            'recruitment' => $canRecruitment ? $this->recruitmentSummary($allowedOutletIds, $thisMonth) : ['applicant_total' => 0, 'applicant_this_month' => 0, 'registration_pending' => 0],
            'payroll' => [
                'previous_month' => $canPayroll ? $this->payrollRecap($allowedOutletIds, $previousMonth) : ['cutoff_count' => 0, 'slip_count' => 0, 'amount' => 0.0],
                'current_month' => $canPayroll ? $this->payrollRecap($allowedOutletIds, $thisMonth) : ['cutoff_count' => 0, 'slip_count' => 0, 'amount' => 0.0],
            ],
            'bonus' => [
                'previous_month' => $canBonus ? $this->bonusRecap($allowedOutletIds, $previousMonth) : ['projection_count' => 0, 'amount' => 0.0],
                'current_month' => $canBonus ? $this->bonusRecap($allowedOutletIds, $thisMonth) : ['projection_count' => 0, 'amount' => 0.0],
            ],
            'visibility' => [
                'attendance' => $canAttendance,
                'leave_request' => $canLeaveApproval,
                'attendance_approval' => $canAttendanceApproval,
                'field_duty_approval' => $canDutyApproval,
                'recruitment' => $canRecruitment,
                'payroll' => $canPayroll,
                'bonus' => $canBonus,
            ],
            'meta' => [
                'contract' => 'hr-dashboard-v10-i13',
                'attendance_date_source' => 'HR_attendances.business_date',
                'mapped_rule' => 'shift_schedule_id IS NOT NULL AND calculation_status <> unmapped',
                'outside_radius_rule' => 'check-in outside radius excluding field_duty/manual modes',
                'payroll_approved_rule' => 'cutoff.status=finalized; approval month uses finalized_at, fallback period_to',
                'bonus_approved_rule' => 'projection.status=finalized; approval month uses finalized_at, fallback period_to',
                'outlet_scope_count' => count($allowedOutletIds),
            ],
        ];
    }

    private function attendanceToday(Request $request, string $today): array
    {
        if (! Schema::hasTable('HR_attendances')) {
            return ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'late' => 0, 'outside_radius' => 0];
        }

        $query = DB::table('HR_attendances as a')
            ->whereDate('a.business_date', $today)
            ->where('a.record_status', '!=', 'cancelled');
        $this->scope->applyAttendanceScope($query, $request);

        $row = $query->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN a.shift_schedule_id IS NULL OR LOWER(COALESCE(a.calculation_status, '')) = 'unmapped' THEN 1 ELSE 0 END) AS unmapped")
            ->selectRaw("SUM(CASE WHEN a.shift_schedule_id IS NOT NULL AND LOWER(COALESCE(a.calculation_status, '')) <> 'unmapped' THEN 1 ELSE 0 END) AS mapped")
            ->selectRaw('SUM(CASE WHEN COALESCE(a.late_minutes, 0) > 0 THEN 1 ELSE 0 END) AS late')
            ->selectRaw("SUM(CASE WHEN a.checkin_at IS NOT NULL AND (COALESCE(a.checkin_inside_radius, 0) = 0 OR LOWER(COALESCE(a.checkin_location_status, '')) = 'outside') AND LOWER(COALESCE(a.checkin_mode, 'normal')) NOT IN ('field_duty','manual','manual_hr') THEN 1 ELSE 0 END) AS outside_radius")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'mapped' => (int) ($row->mapped ?? 0),
            'unmapped' => (int) ($row->unmapped ?? 0),
            'late' => (int) ($row->late ?? 0),
            'outside_radius' => (int) ($row->outside_radius ?? 0),
        ];
    }

    private function topLateOutlets(Request $request, string $today): array
    {
        if (! Schema::hasTable('HR_attendances') || ! Schema::hasTable('outlets')) return [];

        $query = DB::table('HR_attendances as a')
            ->leftJoin('outlets as o', 'o.id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'))
            ->whereDate('a.business_date', $today)
            ->where('a.record_status', '!=', 'cancelled')
            ->where('a.late_minutes', '>', 0);
        $this->scope->applyAttendanceScope($query, $request);

        return $query
            ->selectRaw("COALESCE(a.assignment_outlet_id, a.checkin_outlet_id) AS outlet_id")
            ->selectRaw("MAX(COALESCE(NULLIF(o.code, ''), '-')) AS outlet_code")
            ->selectRaw("MAX(COALESCE(NULLIF(o.name, ''), a.checkin_outlet_name, 'Tanpa Outlet')) AS outlet_name")
            ->selectRaw('COUNT(*) AS attendance_count')
            ->selectRaw('COALESCE(SUM(a.late_minutes), 0) AS late_minutes')
            ->groupBy(DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'))
            ->orderByDesc('attendance_count')->orderByDesc('late_minutes')->limit(3)->get()
            ->map(fn ($row) => [
                'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
                'outlet_code' => (string) ($row->outlet_code ?? '-'),
                'outlet_name' => (string) ($row->outlet_name ?? 'Tanpa Outlet'),
                'attendance_count' => (int) $row->attendance_count,
                'late_minutes' => (int) $row->late_minutes,
            ])->values()->all();
    }

    private function topOutsideRadiusAttendances(Request $request, string $today): array
    {
        if (! Schema::hasTable('HR_attendances') || ! Schema::hasTable('outlets')) return [];

        $query = DB::table('HR_attendances as a')
            ->leftJoin('outlets as o', 'o.id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'));
        if (Schema::hasTable('HR_squads')) {
            $query->leftJoin('HR_squads as s', 's.id', '=', 'a.squad_id');
        }
        if (Schema::hasTable('employees')) {
            $query->leftJoin('employees as e', 'e.id', '=', 'a.employee_id');
        }

        $query->whereDate('a.business_date', $today)
            ->where('a.record_status', '!=', 'cancelled')
            ->whereNotNull('a.checkin_at')
            ->where(function ($q): void {
                $q->where('a.checkin_inside_radius', false)
                    ->orWhereRaw("LOWER(COALESCE(a.checkin_location_status, '')) = 'outside'");
            })
            ->whereNotIn(DB::raw("LOWER(COALESCE(a.checkin_mode, 'normal'))"), ['field_duty', 'manual', 'manual_hr']);
        $this->scope->applyAttendanceScope($query, $request);

        $nameExpression = Schema::hasTable('HR_squads') && Schema::hasTable('employees')
            ? "COALESCE(NULLIF(s.full_name, ''), NULLIF(e.full_name, ''), 'Tanpa Nama')"
            : (Schema::hasTable('employees') ? "COALESCE(NULLIF(e.full_name, ''), 'Tanpa Nama')" : "'Tanpa Nama'");
        $nisjExpression = Schema::hasTable('HR_squads') && Schema::hasTable('employees')
            ? "COALESCE(NULLIF(s.nisj, ''), NULLIF(e.nisj, ''), '-')"
            : (Schema::hasTable('employees') ? "COALESCE(NULLIF(e.nisj, ''), '-')" : "'-'");

        return $query
            ->selectRaw('a.id AS attendance_id')
            ->selectRaw("{$nameExpression} AS employee_name")
            ->selectRaw("{$nisjExpression} AS nisj")
            ->selectRaw("COALESCE(NULLIF(o.code, ''), '-') AS outlet_code")
            ->selectRaw("COALESCE(NULLIF(o.name, ''), a.checkin_outlet_name, 'Tanpa Outlet') AS outlet_name")
            ->selectRaw('COALESCE(a.checkin_distance_m, 0) AS distance_m')
            ->selectRaw('COALESCE(a.checkin_radius_m, 0) AS radius_m')
            ->selectRaw('GREATEST(COALESCE(a.checkin_radius_delta_m, COALESCE(a.checkin_distance_m,0) - COALESCE(a.checkin_radius_m,0)), 0) AS outside_by_m')
            ->orderByDesc('outside_by_m')
            ->orderByDesc('a.checkin_distance_m')
            ->limit(3)->get()
            ->map(fn ($row) => [
                'attendance_id' => (string) $row->attendance_id,
                'employee_name' => (string) $row->employee_name,
                'nisj' => (string) $row->nisj,
                'outlet_code' => (string) $row->outlet_code,
                'outlet_name' => (string) $row->outlet_name,
                'distance_m' => (int) $row->distance_m,
                'radius_m' => (int) $row->radius_m,
                'outside_by_m' => (int) $row->outside_by_m,
            ])->values()->all();
    }

    private function leavePending(array $allowedOutletIds): int
    {
        if (! Schema::hasTable('HR_leave_requests') || $allowedOutletIds === []) return 0;
        return DB::table('HR_leave_requests')
            ->whereIn('assignment_outlet_id', $allowedOutletIds)
            ->whereIn('status', ['pending_spv', 'pending_hrd'])
            ->count();
    }

    private function attendanceApprovalPending(Request $request, string $type): int
    {
        if (! Schema::hasTable('HR_attendance_approvals') || ! Schema::hasTable('HR_attendances')) return 0;
        $query = DB::table('HR_attendance_approvals as ap')
            ->join('HR_attendances as a', 'a.id', '=', 'ap.attendance_id')
            ->where('ap.approval_type', $type)
            ->where('ap.final_status', 'pending')
            ->where('a.record_status', '!=', 'cancelled');
        $this->scope->applyAttendanceScope($query, $request);
        return $query->count();
    }

    private function recruitmentSummary(array $allowedOutletIds, array $month): array
    {
        $result = ['applicant_total' => 0, 'applicant_this_month' => 0, 'registration_pending' => 0];
        if ($allowedOutletIds !== [] && Schema::hasTable('HR_applications') && Schema::hasTable('HR_recruitment_positions')) {
            $base = DB::table('HR_applications as a')
                ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
                ->whereIn('p.destination_outlet_id', $allowedOutletIds);
            $result['applicant_total'] = (clone $base)->count();
            $result['applicant_this_month'] = (clone $base)
                ->whereBetween(DB::raw('DATE(COALESCE(a.applied_at, a.created_at))'), $month)
                ->count();
        }
        if (Schema::hasTable('HR_career_registration_requests')) {
            $result['registration_pending'] = DB::table('HR_career_registration_requests')->where('status', 'pending')->count();
        }
        return $result;
    }

    private function payrollRecap(array $allowedOutletIds, array $month): array
    {
        $empty = ['cutoff_count' => 0, 'slip_count' => 0, 'amount' => 0.0];
        if ($allowedOutletIds === [] || ! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('HR_payroll_slips')) return $empty;

        $row = DB::table('HR_payroll_cutoffs as c')
            ->join('HR_payroll_slips as s', 's.cutoff_id', '=', 'c.id')
            ->where('c.status', 'finalized')
            ->where('s.status', 'finalized')
            ->whereIn('s.outlet_id_snapshot', $allowedOutletIds)
            ->whereBetween(DB::raw('COALESCE(DATE(c.finalized_at), c.period_to)'), $month)
            ->selectRaw('COUNT(DISTINCT c.id) AS cutoff_count')
            ->selectRaw('COUNT(s.id) AS slip_count')
            ->selectRaw('COALESCE(SUM(s.total_net), 0) AS amount')
            ->first();

        return [
            'cutoff_count' => (int) ($row->cutoff_count ?? 0),
            'slip_count' => (int) ($row->slip_count ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }

    private function bonusRecap(array $allowedOutletIds, array $month): array
    {
        $empty = ['projection_count' => 0, 'amount' => 0.0];
        if ($allowedOutletIds === [] || ! Schema::hasTable('HR_bonus_projections')) return $empty;

        $row = DB::table('HR_bonus_projections')
            ->where('status', 'finalized')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween(DB::raw('COALESCE(DATE(finalized_at), period_to)'), $month)
            ->selectRaw('COUNT(*) AS projection_count')
            ->selectRaw('COALESCE(SUM(total_payout), 0) AS amount')
            ->first();

        return [
            'projection_count' => (int) ($row->projection_count ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
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
        try {
            if ($user->can($permission)) return true;
        } catch (\Throwable) {
            // Fall through to Access Matrix session snapshot.
        }

        try {
            $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
            if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;
            foreach (data_get($snapshot, 'access.menus', []) as $menu) {
                if (! is_array($menu) || ! ($menu['can_view'] ?? false)) continue;
                if (($menu['permission_view'] ?? null) === $permission) return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
