<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrPayrollService
{
    public function __construct(
        private readonly HrAttendanceReportService $attendanceReports,
        private readonly HrUniformPayrollDeductionI11Service $uniformDeductions,
        private readonly HrPayrollDeductionBreakdownI03Service $deductionBreakdown,
    ) {}

    public function options(Request $request): array
    {
        return $this->attendanceReports->options($request);
    }

    public function projection(Request $request): array
    {
        $rows = $this->allRecapRows($request);
        if ($rows->isEmpty()) return ['items' => [], 'summary' => $this->summary(collect()), 'meta' => $this->meta($request)];

        $nisjs = $rows->pluck('nisj')->filter()->map(fn ($v) => $this->normalizeNisj($v))->unique()->values();
        $squads = $this->squadSalaryProfiles($nisjs->all());
        $fieldDuty = $this->fieldDutyCounts($request, $rows);

        $items = $rows->map(function (array $row) use ($squads, $fieldDuty) {
            $nisjKey = $this->normalizeNisj($row['nisj'] ?? null);
            $salary = $squads->get($nisjKey);
            $dailyRate = $this->dailyRate($salary);
            $workDays = (int) ($row['work_days'] ?? 0);
            $lateMinutes = (int) ($row['late_minutes'] ?? 0);
            $fieldDutyDays = (int) ($fieldDuty->get(($row['employee_id'] ?? '').'|'.($row['outlet_id'] ?? ''), 0));

            return $this->calculate(array_merge($row, [
                'salary_tier' => (string) ($salary?->salary_tier_name ?? ''),
                'basic_salary' => (float) ($salary?->basic_salary ?? 0),
                'daily_rate' => $dailyRate,
                'minute_deduction' => (float) ($salary?->minute_deduction ?? 0),
                'overtime_rate' => (float) ($salary?->hourly_overtime ?? 0),
                'bonus_amount' => (float) ($salary?->bonus ?? 0),
                'family_allowance' => (float) ($salary?->family_allowance ?? 0),
                'position_allowance' => (float) ($salary?->position_allowance ?? 0),
                'cashbon' => (float) ($salary?->cashbon ?? 0),
                'other_deduction' => (float) ($salary?->other ?? 0),
                'field_duty_days' => $fieldDutyDays,
                'field_duty_bonus' => 0.0,
                'manual_adjustment' => 0.0,
                'overtime_minutes' => 0,
                'overtime_hours_override' => null,
                'work_days' => $workDays,
                'late_minutes' => $lateMinutes,
            ]));
        })->values();

        $sortBy = (string) $request->query('sort_by', 'full_name');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['full_name','nisj','company_code','outlet_name','position','work_days','work_minutes','late_minutes','alpha_days','field_duty_days','total_net'];
        if (! in_array($sortBy, $allowedSort, true)) $sortBy = 'full_name';
        $items = $items->sortBy(fn ($r) => is_numeric(data_get($r, $sortBy)) ? (float) data_get($r, $sortBy) : mb_strtolower((string) data_get($r, $sortBy, '')), SORT_NATURAL | SORT_FLAG_CASE, $sortDir === 'desc')->values();

        $search = mb_strtolower(trim((string) $request->query('search', '')));
        if ($search !== '') {
            $items = $items->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', [
                $r['full_name'] ?? '', $r['nisj'] ?? '', $r['outlet_name'] ?? '', $r['company_code'] ?? '', $r['position'] ?? '',
            ])), $search))->values();
        }

        $summary = $this->summary($items);
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [10,25,50,100,500], true)) $perPage = 25;
        $total = $items->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query('page', 1), $last));
        $pageItems = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pageItems,
            'summary' => $summary,
            'pagination' => ['page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>$last],
            'meta' => $this->meta($request),
        ];
    }

    public function listCutoffs(Request $request): array
    {
        $query = HrPayrollCutoff::query()->with(['outlet:id,name']);
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('company_code')) $query->where('company_code', strtoupper((string) $request->query('company_code')));
        if ($request->filled('year')) $query->whereYear('period_from', (int) $request->query('year'));
        $rows = $query->orderByDesc('period_from')->orderByDesc('created_at')->get()->map(fn ($c) => $this->cutoffPayload($c));
        return ['items' => $rows, 'years' => $rows->pluck('period_from')->filter()->map(fn ($d) => (int) substr((string)$d,0,4))->unique()->sortDesc()->values()];
    }

    public function createCutoff(Request $request, User $actor): HrPayrollCutoff
    {
        $projectionRequest = Request::create('/internal-payroll-projection', 'GET', [
            'from' => (string) $request->input('period_from'),
            'to' => (string) $request->input('period_to'),
            'company_code' => (string) $request->input('company_code', ''),
            'outlet_id' => (string) $request->input('outlet_id', ''),
            'per_page' => 500,
        ]);
        $projectionRequest->setUserResolver(fn () => $actor);
        foreach ($request->headers->all() as $key => $values) foreach ($values as $value) $projectionRequest->headers->set($key, $value);

        $all = collect();
        $page = 1;
        do {
            $projectionRequest->query->set('page', $page);
            $payload = $this->projection($projectionRequest);
            $all = $all->concat($payload['items'] ?? []);
            $last = (int) data_get($payload, 'pagination.last_page', 1);
            $page++;
        } while ($page <= $last);

        if ($all->isEmpty()) throw ValidationException::withMessages(['period_from' => ['Tidak ada data payroll untuk filter/periode ini.']]);

        return DB::transaction(function () use ($request, $actor, $all) {
            $code = trim((string) $request->input('code', '')) ?: $this->nextCode((string) $request->input('period_from'));
            if (HrPayrollCutoff::where('code', $code)->exists()) throw ValidationException::withMessages(['code' => ['Kode cutoff sudah digunakan.']]);

            $cutoff = HrPayrollCutoff::create([
                'code' => $code,
                'period_from' => $request->input('period_from'),
                'period_to' => $request->input('period_to'),
                'company_code' => filled($request->input('company_code')) ? strtoupper((string) $request->input('company_code')) : null,
                'outlet_id' => $request->input('outlet_id') ?: null,
                'description' => $request->input('description'),
                'status' => 'draft',
                'created_by' => (string) $actor->id,
                'snapshot_count' => $all->count(),
                'snapshot_total_net' => round((float) $all->sum('total_net'), 2),
            ]);

            foreach ($all as $row) $this->createSlipSnapshot($cutoff, $row);
            $this->uniformDeductions->attachToCutoff($cutoff);
            foreach ($cutoff->slips()->get() as $slip) $this->recalculateAndSaveSlip($slip);
            $this->refreshCutoffSummary($cutoff);
            return $cutoff->fresh();
        });
    }

    public function cutoffDetail(HrPayrollCutoff $cutoff, Request $request): array
    {
        $cutoff->loadMissing('outlet');
        $slips = $cutoff->slips()->get();
        $breakdowns = $this->deductionBreakdown->forSlipIds($slips->pluck('id')->map(fn ($id) => (string) $id)->all(), $slips->keyBy(fn (HrPayrollSlip $s) => (string) $s->id));
        $rows = $slips->map(fn (HrPayrollSlip $s) => $this->slipPayload($s, false, $breakdowns[(string) $s->id] ?? null));
        $sortBy = (string) $request->query('sort_by', 'full_name_snapshot');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowed = ['full_name_snapshot','nisj_snapshot','company_code_snapshot','outlet_name_snapshot','position_snapshot','work_days','late_minutes','field_duty_days','total_net'];
        if (! in_array($sortBy, $allowed, true)) $sortBy = 'full_name_snapshot';
        $rows = $rows->sortBy(fn ($r) => is_numeric(data_get($r,$sortBy)) ? (float)data_get($r,$sortBy) : mb_strtolower((string)data_get($r,$sortBy,'')), SORT_NATURAL | SORT_FLAG_CASE, $sortDir === 'desc')->values();
        $events = Schema::hasTable('HR_payroll_cutoff_events')
            ? DB::table('HR_payroll_cutoff_events')->where('cutoff_id',$cutoff->id)->orderByDesc('created_at')->limit(30)
                ->get(['id','event','from_status','to_status','actor_user_id','finance_posting_id','metadata','created_at'])->map(function($r){$a=(array)$r;$a['metadata']=is_string($a['metadata']??null)?(json_decode($a['metadata'],true)?:[]):($a['metadata']??[]);return $a;})->all()
            : [];
        return ['cutoff' => $this->cutoffPayload($cutoff), 'items' => $rows, 'summary' => $this->summaryFromSlipPayload($rows), 'events' => $events];
    }

    public function updateSlip(HrPayrollCutoff $cutoff, HrPayrollSlip $slip, array $data): HrPayrollSlip
    {
        if ($cutoff->status !== 'draft') throw ValidationException::withMessages(['cutoff' => ['Cutoff final tidak dapat diubah.']]);
        if ((string)$slip->cutoff_id !== (string)$cutoff->id) abort(404);
        $slip->fill([
            'overtime_hours_override' => array_key_exists('overtime_hours_override', $data) ? $data['overtime_hours_override'] : $slip->overtime_hours_override,
            'bonus_amount' => $data['bonus_amount'] ?? $slip->bonus_amount,
            'cashbon' => $data['cashbon'] ?? $slip->cashbon,
            'other_deduction' => array_key_exists('other_deduction', $data) ? $this->safeOtherDeduction($slip, (float)$data['other_deduction']) : $slip->other_deduction,
            'field_duty_bonus' => $data['field_duty_bonus'] ?? $slip->field_duty_bonus,
            'manual_adjustment' => $data['manual_adjustment'] ?? $slip->manual_adjustment,
            'manual_note' => array_key_exists('manual_note', $data) ? $data['manual_note'] : $slip->manual_note,
        ]);
        $this->recalculateSlip($slip);
        $slip->save();
        $this->refreshCutoffSummary($cutoff);
        return $slip->fresh();
    }

    public function finalize(HrPayrollCutoff $cutoff, User $actor): HrPayrollCutoff
    {
        if ($cutoff->status === 'finalized') return $cutoff;
        throw ValidationException::withMessages([
            'cutoff' => ['Cutoff tidak lagi difinalisasi langsung. Gunakan Ajukan ke Finance lalu approval pada Payroll Posting.'],
        ]);
    }

    public function deleteDraft(HrPayrollCutoff $cutoff): void
    {
        if ($cutoff->status !== 'draft') throw ValidationException::withMessages(['cutoff' => ['Cutoff final tidak dapat dihapus.']]);
        $this->uniformDeductions->releaseCutoff((string)$cutoff->id);
        $cutoff->delete();
    }

    public function selfSlips(User $user): array
    {
        $employeeId = DB::table('employees')->where('user_id', (string)$user->id)->value('id');
        if (! $employeeId) return ['items' => []];
        $slips = HrPayrollSlip::query()->with('cutoff')->where('employee_id', (string)$employeeId)
            ->where('status', 'finalized')->whereHas('cutoff', fn ($q) => $q->where('status','finalized'))
            ->latest()->get();
        $breakdowns = $this->deductionBreakdown->forSlipIds($slips->pluck('id')->map(fn ($id) => (string) $id)->all(), $slips->keyBy(fn (HrPayrollSlip $s) => (string) $s->id));
        return ['items' => $slips->map(fn (HrPayrollSlip $s) => $this->slipPayload($s, true, $breakdowns[(string) $s->id] ?? null))->values()];
    }

    public function selfSlip(User $user, string $id): array
    {
        $employeeId = DB::table('employees')->where('user_id', (string)$user->id)->value('id');
        $slip = HrPayrollSlip::query()->with('cutoff')->where('id',$id)->where('employee_id',(string)$employeeId)->where('status','finalized')->firstOrFail();
        return $this->slipPayload($slip, true, $this->deductionBreakdown->forSlip($slip));
    }

    private function allRecapRows(Request $request): Collection
    {
        $query = [
            'from' => $request->query('from'), 'to' => $request->query('to'),
            'company_code' => $request->query('company_code'), 'outlet_id' => $request->query('outlet_id'),
            'name' => null, 'nisj' => null, 'per_page' => 500, 'page' => 1,
        ];
        $all = collect(); $page = 1; $last = 1;
        do {
            $query['page'] = $page;
            $sub = Request::create('/internal-recap','GET',$query);
            $sub->setUserResolver(fn () => $request->user());
            foreach ($request->headers->all() as $key => $values) foreach ($values as $value) $sub->headers->set($key, $value);
            $result = $this->attendanceReports->recap($sub);
            $all = $all->concat($result['items'] ?? []);
            $last = (int) data_get($result, 'pagination.last_page', 1);
            $page++;
        } while ($page <= $last);
        return $all->values();
    }

    private function squadSalaryProfiles(array $nisjs): Collection
    {
        if (! Schema::hasTable('HR_squads') || $nisjs === []) return collect();
        $rows = DB::table('HR_squads')->whereIn(DB::raw('LOWER(TRIM(nisj))'), $nisjs);
        if (Schema::hasColumn('HR_squads','deleted_at')) $rows->whereNull('deleted_at');
        return $rows->get()->keyBy(fn ($r) => $this->normalizeNisj($r->nisj ?? null));
    }

    private function fieldDutyCounts(Request $request, Collection $recapRows): Collection
    {
        if (! Schema::hasTable('HR_attendances')) return collect();
        $employeeIds = $recapRows->pluck('employee_id')->filter()->unique()->values();
        if ($employeeIds->isEmpty()) return collect();
        $rows = DB::table('HR_attendances')->whereBetween('business_date', [(string)$request->query('from'), (string)$request->query('to')])
            ->whereIn('employee_id', $employeeIds)->where('calculation_eligible', true)
            ->where(function ($q) { $q->where('checkin_mode','field_duty')->orWhere('checkout_mode','field_duty'); })
            ->get(['employee_id','assignment_outlet_id','checkin_outlet_id']);
        return $rows->groupBy(fn ($r) => (string)$r->employee_id.'|'.(string)($r->assignment_outlet_id ?: $r->checkin_outlet_id ?: ''))
            ->map(fn ($g) => $g->count());
    }

    private function dailyRate(?object $salary): float
    {
        $daily = (float) ($salary?->daily_salary ?? 0);
        return $daily > 0 ? $daily : (float) ($salary?->basic_salary ?? 0);
    }

    private function calculate(array $row): array
    {
        $workDays = (int) ($row['work_days'] ?? 0);
        $dailyRate = (float) ($row['daily_rate'] ?? 0);
        $overtimeHours = $row['overtime_hours_override'] !== null
            ? max(0, (float)$row['overtime_hours_override'])
            : max(0, (int)($row['overtime_minutes'] ?? 0)) / 60;
        $grossWage = $workDays * $dailyRate;
        $overtimePay = $overtimeHours * (float)($row['overtime_rate'] ?? 0);
        $lateDeduction = max(0, (int)($row['late_minutes'] ?? 0)) * (float)($row['minute_deduction'] ?? 0);
        $totalNonWage = $overtimePay + (float)($row['bonus_amount'] ?? 0) + (float)($row['family_allowance'] ?? 0) + (float)($row['field_duty_bonus'] ?? 0);
        $bpjsTotal = (float)($row['bpjs_total'] ?? ((float)($row['bpjs_health'] ?? 0) + (float)($row['bpjs_employment'] ?? 0) + (float)($row['bpjs_other'] ?? 0)));
        $totalDeduction = $lateDeduction + (float)($row['cashbon'] ?? 0) + (float)($row['other_deduction'] ?? 0) + $bpjsTotal;
        $net = $grossWage + (float)($row['position_allowance'] ?? 0) + $totalNonWage - $totalDeduction + (float)($row['manual_adjustment'] ?? 0);
        return array_merge($row, [
            'overtime_hours' => round($overtimeHours, 2), 'gross_wage' => round($grossWage,2),
            'overtime_pay' => round($overtimePay,2), 'late_deduction' => round($lateDeduction,2),
            'total_non_wage' => round($totalNonWage,2), 'total_deduction' => round($totalDeduction,2),
            'total_net' => round($net,2),
        ]);
    }

    private function summary(Collection $rows): array
    {
        return [
            'employees' => $rows->pluck('employee_id')->unique()->count(),
            'rows' => $rows->count(), 'work_days' => (int)$rows->sum('work_days'),
            'work_minutes' => (int)$rows->sum('work_minutes'), 'late_minutes' => (int)$rows->sum('late_minutes'),
            'alpha_days' => (int)$rows->sum('alpha_days'), 'field_duty_days' => (int)$rows->sum('field_duty_days'),
            'gross_wage' => round((float)$rows->sum('gross_wage'),2), 'total_non_wage' => round((float)$rows->sum('total_non_wage'),2),
            'total_deduction' => round((float)$rows->sum('total_deduction'),2), 'total_net' => round((float)$rows->sum('total_net'),2),
        ];
    }

    private function meta(Request $request): array
    {
        return ['from'=>(string)$request->query('from'),'to'=>(string)$request->query('to'),'company_code'=>$request->query('company_code'),'outlet_id'=>$request->query('outlet_id')];
    }

    private function createSlipSnapshot(HrPayrollCutoff $cutoff, array $row): void
    {
        $calculated = $this->calculate($row);
        $employee = DB::table('employees')->where('id',(string)$row['employee_id'])->first(['id','user_id']);
        HrPayrollSlip::create([
            'cutoff_id'=>$cutoff->id,'employee_id'=>$row['employee_id'],'user_id'=>$employee?->user_id,
            'nisj_snapshot'=>$row['nisj'] ?? null,'full_name_snapshot'=>$row['full_name'] ?? '-',
            'company_code_snapshot'=>$row['company_code'] ?? null,'outlet_id_snapshot'=>$row['outlet_id'] ?? null,
            'outlet_name_snapshot'=>$row['outlet_name'] ?? '-','position_snapshot'=>$row['position'] ?? '-',
            'salary_tier_snapshot'=>$row['salary_tier'] ?? null,'daily_rate'=>$row['daily_rate'] ?? 0,
            'basic_salary_snapshot'=>$row['basic_salary'] ?? 0,'minute_deduction'=>$row['minute_deduction'] ?? 0,
            'overtime_rate'=>$row['overtime_rate'] ?? 0,'bonus_amount'=>$row['bonus_amount'] ?? 0,
            'family_allowance'=>$row['family_allowance'] ?? 0,'position_allowance'=>$row['position_allowance'] ?? 0,
            'cashbon'=>$row['cashbon'] ?? 0,'other_deduction'=>$row['other_deduction'] ?? 0,
            'field_duty_bonus'=>0,'manual_adjustment'=>0,'bpjs_health'=>0,'bpjs_employment'=>0,'bpjs_other'=>0,'bpjs_total'=>0,'work_days'=>$row['work_days'] ?? 0,
            'work_minutes'=>$row['work_minutes'] ?? 0,'late_minutes'=>$row['late_minutes'] ?? 0,
            'alpha_days'=>$row['alpha_days'] ?? 0,'unmapped_days'=>$row['unmapped_attendance_days'] ?? 0,
            'pending_exception_days'=>$row['pending_exception_days'] ?? 0,'rejected_exception_days'=>$row['rejected_exception_days'] ?? 0,
            'incomplete_days'=>$row['incomplete_days'] ?? 0,'field_duty_days'=>$row['field_duty_days'] ?? 0,
            'overtime_minutes'=>0,'overtime_hours_override'=>null,'gross_wage'=>$calculated['gross_wage'],
            'overtime_pay'=>$calculated['overtime_pay'],'late_deduction'=>$calculated['late_deduction'],
            'total_non_wage'=>$calculated['total_non_wage'],'total_deduction'=>$calculated['total_deduction'],
            'total_net'=>$calculated['total_net'],'status'=>'draft',
        ]);
    }

    private function recalculateSlip(HrPayrollSlip $slip): void
    {
        $calculated = $this->calculate($slip->toArray());
        $slip->gross_wage = $calculated['gross_wage']; $slip->overtime_pay = $calculated['overtime_pay'];
        $slip->late_deduction = $calculated['late_deduction']; $slip->total_non_wage = $calculated['total_non_wage'];
        $slip->total_deduction = $calculated['total_deduction']; $slip->total_net = $calculated['total_net'];
    }



    public function setOtherDeductionSafely(HrPayrollSlip $slip, float $amount): void
    {
        $slip->other_deduction = $this->safeOtherDeduction($slip, $amount);
    }

    private function safeOtherDeduction(HrPayrollSlip $slip, float $amount): float
    {
        $amount = max(0, round($amount, 2));
        $this->uniformDeductions->assertOtherDeductionNotBelowLocked($slip, $amount);
        return $amount;
    }

    public function recalculateAndSaveSlip(HrPayrollSlip $slip): HrPayrollSlip
    {
        $slip->bpjs_total = round((float)$slip->bpjs_health + (float)$slip->bpjs_employment + (float)$slip->bpjs_other, 2);
        $this->recalculateSlip($slip);
        $slip->save();
        return $slip->fresh();
    }

    public function refreshSummary(HrPayrollCutoff $cutoff): void
    {
        $this->refreshCutoffSummary($cutoff);
    }
    private function refreshCutoffSummary(HrPayrollCutoff $cutoff): void
    {
        $cutoff->snapshot_count = $cutoff->slips()->count();
        $cutoff->snapshot_total_net = $cutoff->slips()->sum('total_net');
        $cutoff->save();
    }

    private function cutoffPayload(HrPayrollCutoff $cutoff): array
    {
        $finance = $this->financeState($cutoff->finance_posting_id ? (string)$cutoff->finance_posting_id : null);
        $canReopen = in_array((string)$cutoff->status, ['submitted','finance_processing','finalized'], true)
            && !($finance['has_active_accrual'] ?? false)
            && (int)($finance['active_payment_count'] ?? 0) === 0;

        return [
            'id'=>$cutoff->id,'code'=>$cutoff->code,'period_from'=>optional($cutoff->period_from)->format('Y-m-d'),
            'period_to'=>optional($cutoff->period_to)->format('Y-m-d'),'company_code'=>$cutoff->company_code,
            'outlet_id'=>$cutoff->outlet_id,'outlet_name'=>$cutoff->outlet?->name,'description'=>$cutoff->description,
            'status'=>$cutoff->status,'snapshot_count'=>(int)$cutoff->snapshot_count,'snapshot_total_net'=>(float)$cutoff->snapshot_total_net,
            'submitted_at'=>$cutoff->submitted_at?->toIso8601String(),'finance_processing_at'=>$cutoff->finance_processing_at?->toIso8601String(),
            'finance_posting_id'=>$cutoff->finance_posting_id,'finance_status'=>$finance['status'] ?? null,
            'finance_has_active_accrual'=>(bool)($finance['has_active_accrual'] ?? false),'finance_active_payment_count'=>(int)($finance['active_payment_count'] ?? 0),
            'finance_accrual_journal_id'=>$finance['accrual_journal_id'] ?? null,'finance_accrual_reversal_journal_id'=>$finance['accrual_reversal_journal_id'] ?? null,
            'can_reopen'=>$canReopen,
            'reopened_at'=>$cutoff->reopened_at?->toIso8601String(),'reopen_reason'=>$cutoff->reopen_reason,
            'finalized_at'=>$cutoff->finalized_at?->toIso8601String(),'created_at'=>$cutoff->created_at?->toIso8601String(),
        ];
    }

    private function financeState(?string $postingId): array
    {
        if (!$postingId || !Schema::hasTable('finance_payroll_posting_inbox')) return [];
        $posting = DB::table('finance_payroll_posting_inbox')->where('id',$postingId)->first([
            'id','status','accrual_journal_id',
            ...(Schema::hasColumn('finance_payroll_posting_inbox','accrual_reversal_journal_id') ? ['accrual_reversal_journal_id'] : []),
        ]);
        if(!$posting)return [];

        $activeAccrual=false;
        if(!empty($posting->accrual_journal_id) && Schema::hasTable('finance_journal_entries')){
            $journal=DB::table('finance_journal_entries')->where('id',(string)$posting->accrual_journal_id)->first(['status','reversal_journal_id']);
            $activeAccrual=$journal && (string)$journal->status==='POSTED' && empty($journal->reversal_journal_id);
        }
        $activePayments=Schema::hasTable('finance_payroll_payments')
            ? (int)DB::table('finance_payroll_payments')->where('payroll_posting_id',$postingId)->where('status','POSTED')->count()
            : 0;

        return [
            'status'=>(string)$posting->status,
            'accrual_journal_id'=>$posting->accrual_journal_id ? (string)$posting->accrual_journal_id : null,
            'accrual_reversal_journal_id'=>isset($posting->accrual_reversal_journal_id) && $posting->accrual_reversal_journal_id ? (string)$posting->accrual_reversal_journal_id : null,
            'has_active_accrual'=>$activeAccrual,
            'active_payment_count'=>$activePayments,
        ];
    }

    private function slipPayload(HrPayrollSlip $s, bool $includeCutoff = false, ?array $deductionBreakdown = null): array
    {
        $calculated = $this->calculate($s->toArray());
        $breakdown = $deductionBreakdown ?? $this->deductionBreakdown->forSlip($s);
        $data = array_merge($s->toArray(), $calculated, [
            'id'=>$s->id,'daily_rate'=>(float)$s->daily_rate,'minute_deduction'=>(float)$s->minute_deduction,
            'overtime_rate'=>(float)$s->overtime_rate,'bonus_amount'=>(float)$s->bonus_amount,
            'family_allowance'=>(float)$s->family_allowance,'position_allowance'=>(float)$s->position_allowance,
            'cashbon'=>(float)$s->cashbon,'other_deduction'=>(float)$s->other_deduction,'field_duty_bonus'=>(float)$s->field_duty_bonus,
            'manual_adjustment'=>(float)$s->manual_adjustment,'bpjs_health'=>(float)($s->bpjs_health ?? 0),
            'bpjs_employment'=>(float)($s->bpjs_employment ?? 0),'bpjs_other'=>(float)($s->bpjs_other ?? 0),'bpjs_total'=>(float)($s->bpjs_total ?? 0),
            'other_deduction_breakdown'=>$breakdown,
            'total_net'=>(float)$calculated['total_net'],
        ]);
        if ($includeCutoff && $s->cutoff) $data['cutoff'] = $this->cutoffPayload($s->cutoff);
        return $data;
    }

    private function summaryFromSlipPayload(Collection $rows): array
    {
        return ['rows'=>$rows->count(),'work_days'=>(int)$rows->sum('work_days'),'late_minutes'=>(int)$rows->sum('late_minutes'),
            'field_duty_days'=>(int)$rows->sum('field_duty_days'),'total_net'=>round((float)$rows->sum('total_net'),2)];
    }

    private function nextCode(string $periodFrom): string
    {
        $ym = CarbonImmutable::parse($periodFrom)->format('Ym');
        $prefix = "PAY-{$ym}-";
        $last = HrPayrollCutoff::where('code','like',$prefix.'%')->orderByDesc('code')->value('code');
        $seq = $last && preg_match('/(\d+)$/',$last,$m) ? ((int)$m[1] + 1) : 1;
        return $prefix.str_pad((string)$seq,3,'0',STR_PAD_LEFT);
    }

    private function normalizeNisj(mixed $value): string { return Str::lower(trim((string)$value)); }
}
