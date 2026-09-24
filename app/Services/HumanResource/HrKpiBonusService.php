<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class HrKpiBonusService
{
    private const KPI_RULE_VERSION = 'KPI_COMPOSITE_V2_DRAFT_ZERO';

    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    public function references(Request $request): array
    {
        return [
            'outlets'=>$this->scope->options($request),
            'kpi_rule'=>$this->kpiRule(),
            'bonus_policy'=>$this->activePolicy(),
        ];
    }

    public function listKpiPeriods(Request $request, array $filters): array
    {
        $allowed=$this->scope->allowedOutletIds($request); if($allowed===[]) return ['items'=>[]];
        $q=DB::table('HR_kpi_periods as p')->join('outlets as o','o.id','=','p.outlet_id')
            ->whereIn('p.outlet_id',$allowed)
            ->when($filters['outlet_id']??null,fn($q,$v)=>$q->where('p.outlet_id',$v))
            ->when($filters['from']??null,fn($q,$v)=>$q->whereDate('p.period_to','>=',$v))
            ->when($filters['to']??null,fn($q,$v)=>$q->whereDate('p.period_from','<=',$v))
            ->orderByDesc('p.period_to')->orderBy('o.name')
            ->get(['p.*','o.code as outlet_code','o.name as outlet_name']);
        $counts=DB::table('HR_kpi_period_scores')->whereIn('period_id',$q->pluck('id'))->selectRaw('period_id,COUNT(*) employees,AVG(kpi_total) avg_kpi,AVG(grooming_component_40) avg_grooming,AVG(discipline_component_60) avg_discipline')->groupBy('period_id')->get()->keyBy('period_id');
        return ['items'=>$q->map(function($r)use($counts){$c=$counts->get($r->id);return $this->shapeKpiPeriod($r,$c);})->values()->all()];
    }

    public function recalculateKpi(Request $request, array $data, ?User $actor): array
    {
        $outletId=(string)$data['outlet_id'];$from=(string)$data['period_from'];$to=(string)$data['period_to'];
        $this->assertOutletScope($request,$outletId);$this->assertPeriod($from,$to);
        return DB::transaction(function()use($outletId,$from,$to,$actor):array{
            $period=DB::table('HR_kpi_periods')->where('outlet_id',$outletId)->where('period_from',$from)->where('period_to',$to)->lockForUpdate()->first();
            if($period && $period->status==='locked') throw ValidationException::withMessages(['period'=>['KPI period sudah locked karena dipakai Cutoff Bonus. Buat periode baru atau buka melalui audit database dengan prosedur reversal.']]);
            $rows=$this->calculateKpiRows($outletId,$from,$to); if($rows===[]) throw ValidationException::withMessages(['period'=>['Tidak ada Mapping Schedule shift pada outlet/periode tersebut.']]);
            $rule=$this->kpiRule();$rule['daily_review_status_summary']=$this->dailyReviewStatusSummary($outletId,$from,$to);$hash=hash('sha256',json_encode([$rule,$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$id=(string)($period->id??Str::ulid());$revision=(int)($period->revision??0)+1;
            $payload=['status'=>'draft','revision'=>$revision,'rule_version'=>self::KPI_RULE_VERSION,'rule_snapshot'=>json_encode($rule,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'source_hash'=>$hash,'calculated_by_user_id'=>$actor?->id,'calculated_at'=>now(),'locked_by_user_id'=>null,'locked_at'=>null,'updated_at'=>now()];
            if($period) DB::table('HR_kpi_periods')->where('id',$id)->update($payload); else DB::table('HR_kpi_periods')->insert($payload+['id'=>$id,'outlet_id'=>$outletId,'period_from'=>$from,'period_to'=>$to,'created_at'=>now()]);
            DB::table('HR_kpi_period_scores')->where('period_id',$id)->delete();
            foreach($rows as$row)DB::table('HR_kpi_period_scores')->insert($row+['id'=>(string)Str::ulid(),'period_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
            return $this->kpiPeriodById($id);
        });
    }

    public function kpiPeriod(Request $request,string $id): array
    {
        $period=DB::table('HR_kpi_periods')->where('id',$id)->first();if(!$period)throw ValidationException::withMessages(['period'=>['KPI period tidak ditemukan.']]);$this->assertOutletScope($request,(string)$period->outlet_id);return$this->kpiPeriodById($id);
    }

    public function exportKpi(Request $request,string $id): Response
    {
        $data=$this->kpiPeriod($request,$id);$rows=[['OUTLET','FROM','TO','NISJ','NAMA','DIVISI','GROOMING RAW','GROOMING MAX','GROOMING 40','SHIFT TERJADWAL','SHIFT HADIR','TERLAMBAT','% LATE','KEDISIPLINAN 60','KPI','GRADE']];
        foreach($data['scores'] as$r)$rows[]=[$data['outlet_code'],$data['period_from'],$data['period_to'],$r['nisj'],$r['full_name'],$r['division'],$r['grooming_raw'],$r['grooming_workbook_max'],$r['grooming_component_40'],$r['scheduled_shifts'],$r['attendance_shifts'],$r['late_count'],$r['late_percent'],$r['discipline_component_60'],$r['kpi_total'],$r['grade']];
        return$this->xlsx->downloadWorkbook('HR_MAPPING_KPI_'.$data['outlet_code'].'_'.$data['period_from'].'_'.$data['period_to'].'.xlsx',[[ 'name'=>'KPI','rows'=>$rows ]]);
    }

    public function listBonus(Request $request,array $filters): array
    {
        $allowed=$this->scope->allowedOutletIds($request);if($allowed===[])return['items'=>[]];
        $rows=DB::table('HR_bonus_projections as b')->join('outlets as o','o.id','=','b.outlet_id')->leftJoin('finance_payroll_posting_inbox as f','f.id','=','b.finance_posting_id')
            ->whereIn('b.outlet_id',$allowed)->when($filters['outlet_id']??null,fn($q,$v)=>$q->where('b.outlet_id',$v))->when($filters['status']??null,fn($q,$v)=>$q->where('b.status',$v))
            ->orderByDesc('b.period_to')->get(['b.*','o.code as outlet_code','o.name as outlet_name','f.status as finance_status','f.payroll_batch_id']);
        return['items'=>$rows->map(fn($r)=>$this->shapeProjection($r))->all()];
    }

    public function createProjection(Request $request,string $kpiPeriodId,?User $actor): array
    {
        $period=DB::table('HR_kpi_periods')->where('id',$kpiPeriodId)->first();if(!$period)throw ValidationException::withMessages(['kpi_period_id'=>['KPI period tidak ditemukan.']]);$this->assertOutletScope($request,(string)$period->outlet_id);
        return DB::transaction(function()use($period,$actor):array{
            $old=DB::table('HR_bonus_projections')->where('kpi_period_id',$period->id)->lockForUpdate()->first();if($old&&$old->status!=='draft')return$this->projectionById((string)$old->id);
            $policy=$this->activePolicy();$revenue=$this->revenue((string)$period->outlet_id,(string)$period->period_from,(string)$period->period_to);$totalShifts=(int)DB::table('HR_kpi_period_scores')->where('period_id',$period->id)->sum('scheduled_shifts');
            $id=(string)($old->id??Str::ulid());$payload=['outlet_id'=>$period->outlet_id,'period_from'=>$period->period_from,'period_to'=>$period->period_to,'status'=>'draft','policy_code'=>$policy['code'],'policy_version'=>$policy['version'],'policy_snapshot'=>json_encode($policy['config'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'revenue_source'=>'report_daily_sales_summaries.grand_sales','revenue_snapshot'=>$revenue,'total_shifts_snapshot'=>$totalShifts,'finance_budget_percentage'=>null,'bonus_budget'=>0,'bonus_per_shift'=>0,'total_payout'=>0,'remaining_budget'=>0,'finance_posting_id'=>null,'calculation_hash'=>null,'finalized_snapshot'=>null,'updated_at'=>now()];
            if($old)DB::table('HR_bonus_projections')->where('id',$id)->update($payload);else DB::table('HR_bonus_projections')->insert($payload+['id'=>$id,'kpi_period_id'=>$period->id,'created_by_user_id'=>$actor?->id,'created_at'=>now()]);
            $this->seedProjectionLines($id,(string)$period->id,$policy['config'],(string)$period->period_to);$this->event($id,'projection_created_or_recalculated',$old?->status,null,$actor?->id,['revenue'=>$revenue,'total_shifts'=>$totalShifts]);return$this->projectionById($id);
        });
    }

    public function recalculateProjection(Request $request,string $id,?User $actor): array
    {
        $row=DB::table('HR_bonus_projections')->where('id',$id)->first();if(!$row)throw ValidationException::withMessages(['projection'=>['Proyeksi bonus tidak ditemukan.']]);$this->assertOutletScope($request,(string)$row->outlet_id);if($row->status!=='draft')throw ValidationException::withMessages(['status'=>['Hanya proyeksi Draft yang dapat dihitung ulang.']]);return$this->createProjection($request,(string)$row->kpi_period_id,$actor);
    }

    public function projection(Request $request,string $id): array
    {
        $row=DB::table('HR_bonus_projections')->where('id',$id)->first();if(!$row)throw ValidationException::withMessages(['projection'=>['Proyeksi bonus tidak ditemukan.']]);$this->assertOutletScope($request,(string)$row->outlet_id);return$this->projectionById($id);
    }

    public function submitProjection(Request $request,string $id,?User $actor): array
    {
        $this->projection($request,$id);
        return DB::transaction(function()use($id,$actor):array{
            $p=DB::table('HR_bonus_projections')->where('id',$id)->lockForUpdate()->first();if(in_array($p->status,['submitted','finance_processing','finalized'],true))return$this->projectionById($id);if($p->status!=='draft')throw ValidationException::withMessages(['status'=>['Hanya Draft yang dapat diajukan.']]);
            if((float)$p->revenue_snapshot<=0)throw ValidationException::withMessages(['revenue'=>['Revenue periode masih 0. Pastikan report daily sales sudah tersinkron.']]);if((int)$p->total_shifts_snapshot<=0)throw ValidationException::withMessages(['shifts'=>['Total shift periode masih 0. Lengkapi Mapping Schedule.']]);
            $company=DB::table('finance_outlet_company_mappings')->where('outlet_id',$p->outlet_id)->where('is_active',true)->value('company_code');if(!$company)throw ValidationException::withMessages(['outlet_id'=>['Outlet belum dipetakan ke PT pada Finance → COA → Mapping PT Outlet.']]);
            $posting=DB::table('finance_payroll_posting_inbox')->where('hr_bonus_projection_id',$id)->lockForUpdate()->first();$postingId=(string)($posting->id??Str::ulid());$batch='BONUS-'.str_replace('-','',(string)$p->period_to).'-'.strtoupper(substr($postingId,-6));$payload=['contract_version'=>'FIN-PAYROLL-V1','external_request_key'=>'HR-BONUS-PROJECTION:'.$id,'source_system'=>'HR_BACKOFFICE','payroll_batch_id'=>$batch,'request_type'=>'BONUS','reference_no'=>'BONUS-'.$p->period_from.'-'.$p->period_to,'description'=>'Cutoff Bonus HR · budget menunggu % Finance','company_code'=>(string)$company,'outlet_id'=>$p->outlet_id,'marking'=>'MARKING','period_from'=>$p->period_from,'period_to'=>$p->period_to,'business_date'=>$p->period_to,'currency'=>'IDR','gross_pay'=>0,'deductions'=>0,'net_pay'=>0,'payable'=>0,'employee_count'=>DB::table('HR_bonus_projection_lines')->where('projection_id',$id)->count(),'status'=>'SUBMITTED','source_fingerprint'=>hash('sha256','HR-BONUS:'.$id.':'.$p->calculation_hash),'payload'=>json_encode(['origin'=>'HR_BONUS','hr_bonus_projection_id'=>$id,'revenue'=>(float)$p->revenue_snapshot,'total_shifts'=>(int)$p->total_shifts_snapshot],JSON_UNESCAPED_SLASHES),'received_at'=>$posting->received_at??now(),'submitted_at'=>$posting->submitted_at??now(),'submitted_by_user_id'=>$actor?->id,'hr_bonus_projection_id'=>$id,'bonus_budget_percentage'=>null,'bonus_budget_amount'=>0,'bonus_remaining_budget'=>0,'paid_total'=>0,'balance_due'=>0,'updated_at'=>now()];
            if($posting)DB::table('finance_payroll_posting_inbox')->where('id',$postingId)->update($payload);else DB::table('finance_payroll_posting_inbox')->insert($payload+['id'=>$postingId,'received_by_user_id'=>$actor?->id,'created_at'=>now()]);
            DB::table('HR_kpi_periods')->where('id',$p->kpi_period_id)->update(['status'=>'locked','locked_by_user_id'=>$actor?->id,'locked_at'=>now(),'updated_at'=>now()]);
            DB::table('HR_bonus_projections')->where('id',$id)->update(['status'=>'submitted','finance_posting_id'=>$postingId,'submitted_by_user_id'=>$actor?->id,'submitted_at'=>now(),'updated_at'=>now()]);$this->event($id,'submitted','draft','submitted',$actor?->id,['finance_posting_id'=>$postingId]);return$this->projectionById($id);
        });
    }

    public function financeBudget(string $postingId): array
    {
        $p=DB::table('finance_payroll_posting_inbox as f')->join('HR_bonus_projections as b','b.id','=','f.hr_bonus_projection_id')->join('outlets as o','o.id','=','b.outlet_id')->where('f.id',$postingId)->where('f.request_type','BONUS')->first(['f.id as finance_posting_id','f.status as finance_status','f.bonus_budget_percentage','f.bonus_budget_amount','f.bonus_remaining_budget','b.*','o.code as outlet_code','o.name as outlet_name']);if(!$p)throw ValidationException::withMessages(['posting'=>['Finance posting bukan Cutoff Bonus HR.']]);return$this->shapeProjection($p)+['finance_posting_id'=>$postingId,'finance_status'=>$p->finance_status];
    }

    public function applyFinanceBudget(string $postingId,float $percentage,?User $actor): array
    {
        if($percentage<=0||$percentage>100)throw ValidationException::withMessages(['budget_percentage'=>['% anggaran bonus harus >0 dan ≤100.']]);
        return DB::transaction(function()use($postingId,$percentage,$actor):array{
            $f=DB::table('finance_payroll_posting_inbox')->where('id',$postingId)->lockForUpdate()->first();if(!$f||$f->request_type!=='BONUS'||!$f->hr_bonus_projection_id)throw ValidationException::withMessages(['posting'=>['Finance posting bukan Cutoff Bonus HR.']]);if($f->status!=='SUBMITTED')throw ValidationException::withMessages(['status'=>['Budget hanya dapat diinput saat Finance Posting SUBMITTED.']]);
            $p=DB::table('HR_bonus_projections')->where('id',$f->hr_bonus_projection_id)->lockForUpdate()->first();if(!$p||$p->status==='finalized')throw ValidationException::withMessages(['projection'=>['Proyeksi bonus tidak tersedia atau sudah finalized.']]);$budget=round((float)$p->revenue_snapshot*$percentage/100,2);if($budget<=0)throw ValidationException::withMessages(['budget_percentage'=>['Budget hasil kalkulasi harus lebih besar dari 0.']]);$totalShifts=(int)$p->total_shifts_snapshot;if($totalShifts<=0)throw ValidationException::withMessages(['shifts'=>['Total shift harus lebih besar dari 0.']]);$perShift=$budget/$totalShifts;
            $policy=json_decode((string)$p->policy_snapshot,true)?:[];$lines=DB::table('HR_bonus_projection_lines')->where('projection_id',$p->id)->lockForUpdate()->get();$total=0;
            foreach($lines as$l){$base=round((int)$l->personal_shifts*$perShift,2);$payout=round($base*(float)$l->grade_multiplier*(float)$l->contract_multiplier*(float)$l->sp_multiplier,2);$forfeit=max(0,round($base-$payout,2));DB::table('HR_bonus_projection_lines')->where('id',$l->id)->update(['personal_base_budget'=>$base,'bonus_payout'=>$payout,'forfeited_budget'=>$forfeit,'updated_at'=>now()]);$total+=$payout;}
            $total=round($total,2);if($total<=0)throw ValidationException::withMessages(['budget_percentage'=>['Tidak ada Squad yang berhak menerima bonus setelah multiplier KPI/contract/SP diterapkan.']]);$remaining=max(0,round($budget-$total,2));$hash=hash('sha256',json_encode([$p->id,$percentage,$budget,$perShift,$total,$remaining,$policy],JSON_UNESCAPED_SLASHES));
            DB::table('HR_bonus_projections')->where('id',$p->id)->update(['status'=>'finance_processing','finance_budget_percentage'=>$percentage,'bonus_budget'=>$budget,'bonus_per_shift'=>$perShift,'total_payout'=>$total,'remaining_budget'=>$remaining,'calculation_hash'=>$hash,'updated_at'=>now()]);
            DB::table('finance_payroll_posting_inbox')->where('id',$postingId)->update(['gross_pay'=>$total,'deductions'=>0,'net_pay'=>$total,'payable'=>$total,'paid_total'=>0,'balance_due'=>$total,'bonus_budget_percentage'=>$percentage,'bonus_budget_amount'=>$budget,'bonus_remaining_budget'=>$remaining,'source_fingerprint'=>$hash,'payload'=>json_encode(['origin'=>'HR_BONUS','hr_bonus_projection_id'=>$p->id,'revenue'=>(float)$p->revenue_snapshot,'budget_percentage'=>$percentage,'bonus_budget'=>$budget,'total_shifts'=>$totalShifts,'bonus_per_shift'=>$perShift,'total_payout'=>$total,'remaining_budget'=>$remaining],JSON_UNESCAPED_SLASHES),'updated_at'=>now()]);$this->event((string)$p->id,'finance_budget_applied',$p->status,'finance_processing',$actor?->id,['percentage'=>$percentage,'budget'=>$budget,'payout'=>$total,'remaining'=>$remaining]);return$this->financeBudget($postingId);
        });
    }

    public function finalizeFromFinance(string $postingId,?User $actor): void
    {
        DB::transaction(function()use($postingId,$actor):void{$f=DB::table('finance_payroll_posting_inbox')->where('id',$postingId)->lockForUpdate()->first();if(!$f||$f->request_type!=='BONUS'||!$f->hr_bonus_projection_id)return;$p=DB::table('HR_bonus_projections')->where('id',$f->hr_bonus_projection_id)->lockForUpdate()->first();if(!$p||$p->status==='finalized')return;if($p->finance_budget_percentage===null||(float)$p->total_payout<=0)throw ValidationException::withMessages(['bonus_budget'=>['Finance wajib input % anggaran bonus sebelum approve.']]);$snapshot=$this->projectionById((string)$p->id);DB::table('HR_bonus_projections')->where('id',$p->id)->update(['status'=>'finalized','finalized_by_user_id'=>$actor?->id,'finalized_at'=>now(),'finalized_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>now()]);$this->event((string)$p->id,'finance_approved',$p->status,'finalized',$actor?->id,['finance_posting_id'=>$postingId]);});
    }

    public function exportBonus(Request $request,string $id): Response
    {
        $p=$this->projection($request,$id);$summary=[['OUTLET','FROM','TO','STATUS','REVENUE','BUDGET %','BUDGET BONUS','TOTAL SHIFT','BONUS/SHIFT','TOTAL CAIR','SISA BUDGET'],[$p['outlet_name'],$p['period_from'],$p['period_to'],$p['status'],$p['revenue'],$p['budget_percentage'],$p['bonus_budget'],$p['total_shifts'],$p['bonus_per_shift'],$p['total_payout'],$p['remaining_budget']]];$rows=[['NISJ','NAMA','SHIFT','KPI','GRADE','GRADE %','HAK KONTRAK','KONTRAK %','SP','SP %','BUDGET PERSONAL','BONUS DICAIRKAN','BUDGET HANGUS']];foreach($p['lines']as$l)$rows[]=[$l['nisj'],$l['full_name'],$l['personal_shifts'],$l['kpi_score'],$l['grade'],$l['grade_multiplier'],$l['contract_entitlement'],$l['contract_multiplier'],$l['sp_level'],$l['sp_multiplier'],$l['personal_base_budget'],$l['bonus_payout'],$l['forfeited_budget']];return$this->xlsx->downloadWorkbook('HR_CUTOFF_BONUS_'.$p['outlet_code'].'_'.$p['period_from'].'_'.$p['period_to'].'.xlsx',[[ 'name'=>'SUMMARY','rows'=>$summary ],[ 'name'=>'DETAIL','rows'=>$rows ]]);
    }

    private function calculateKpiRows(string $outletId,string $from,string $to): array
    {
        $schedules=DB::table('HR_shift_schedules as sh')->join('employees as e','e.id','=','sh.employee_id')->leftJoin('HR_squads as sq',function($j){$j->on(DB::raw('LOWER(TRIM(sq.nisj))'),'=',DB::raw('LOWER(TRIM(e.nisj))'))->whereNull('sq.deleted_at');})->where('sh.outlet_id',$outletId)->whereBetween('sh.work_date',[$from,$to])->where('sh.schedule_type','shift')->orderBy('sh.work_date')->get(['sh.employee_id','sh.work_date','sh.start_time_snapshot','sh.outlet_timezone_snapshot','e.nisj','e.full_name','sq.id as squad_id','sq.division_name']);
        $employeeIds=$schedules->pluck('employee_id')->unique()->values()->all();if($employeeIds===[])return[];
        $att=DB::table('HR_attendances')->whereIn('employee_id',$employeeIds)->whereBetween('business_date',[$from,$to])->where('calculation_eligible',true)->where('record_status','<>','cancelled')->where(function($q){$q->where('approval_required',false)->orWhereIn('approval_status',['not_required','approved']);})->get(['employee_id','business_date','checkin_at'])->keyBy(fn($r)=>(string)$r->employee_id.'|'.(string)$r->business_date);

        // I05 rule: Daily KPI yang masih DRAFT tetap dibaca sebagai bagian audit periode,
        // tetapi nilai KPI 1 / grooming raw-nya dipaksa 0 pada Mapping KPI. Nilai asli
        // HR_kpi_daily_entries TIDAK diubah. Review LOCKED dihitung apa adanya.
        $groom=DB::table('HR_kpi_daily_entries as e')
            ->join('HR_kpi_daily_reviews as r','r.id','=','e.review_id')
            ->where('r.outlet_id',$outletId)
            ->whereBetween('r.review_date',[$from,$to])
            ->whereIn('r.status',['draft','locked'])
            ->whereIn('e.employee_id',$employeeIds)
            ->selectRaw("e.employee_id,
                SUM(CASE WHEN r.status='locked' THEN e.raw_score ELSE 0 END) raw_score,
                MAX(e.max_score) max_day,
                COUNT(DISTINCT r.review_date) reviewed_days,
                COUNT(DISTINCT CASE WHEN r.status='locked' THEN r.review_date END) locked_days,
                COUNT(DISTINCT CASE WHEN r.status='draft' THEN r.review_date END) draft_days")
            ->groupBy('e.employee_id')->get()->keyBy('employee_id');

        $setting=DB::table('HR_kpi_settings')->where('code','GROOMING_TARGET_DAYS')->value('value_json');$targetDays=(int)((json_decode((string)($setting?:'{"days":25}'),true)['days']??25));$groups=$schedules->groupBy('employee_id');$rows=[];
        foreach($groups as$empId=>$group){
            $first=$group->first();$scheduled=$group->count();$scheduledDays=$group->pluck('work_date')->map(fn($v)=>(string)$v)->unique()->count();$attendanceShifts=0;$late=0;
            foreach($group as$sh){$a=$att->get((string)$empId.'|'.(string)$sh->work_date);if(!$a||!$a->checkin_at)continue;$attendanceShifts++;if($sh->start_time_snapshot){$tz=(string)($sh->outlet_timezone_snapshot?:'Asia/Jakarta');$scheduledAt=Carbon::parse((string)$sh->work_date.' '.(string)$sh->start_time_snapshot,$tz);$checkin=Carbon::parse((string)$a->checkin_at,'UTC')->setTimezone($tz);if($checkin->greaterThan($scheduledAt))$late++;}}
            $g=$groom->get($empId);$raw=(float)($g->raw_score??0);$maxDay=(float)($g->max_day??0);$reviewedDays=(int)($g->reviewed_days??0);$lockedDays=(int)($g->locked_days??0);$draftDays=(int)($g->draft_days??0);$missingDays=max(0,$scheduledDays-$reviewedDays);
            $workbookMax=$maxDay*$targetDays;$g40=$workbookMax>0?min(40,($raw/$workbookMax)*40):0;$latePct=$attendanceShifts>0?($late/$attendanceShifts)*100:100;$d60=$attendanceShifts>0?max(0,(1-$late/$attendanceShifts)*60):0;$kpi=min(100,$g40+$d60);
            $rows[]=['employee_id'=>(string)$empId,'squad_id'=>$first->squad_id?(int)$first->squad_id:null,'nisj_snapshot'=>$first->nisj,'name_snapshot'=>(string)$first->full_name,'division_snapshot'=>$first->division_name,'grooming_raw'=>round($raw,2),'grooming_workbook_max'=>round($workbookMax,2),'grooming_component_40'=>round($g40,4),'scheduled_shifts'=>$scheduled,'attendance_shifts'=>$attendanceShifts,'late_count'=>$late,'late_percent'=>round($latePct,4),'discipline_component_60'=>round($d60,4),'kpi_total'=>round($kpi,4),'grade_snapshot'=>$this->grade($kpi)['grade'],'source_snapshot'=>json_encode(['reviewed_days'=>$reviewedDays,'locked_days'=>$lockedDays,'draft_days'=>$draftDays,'missing_scheduled_days'=>$missingDays,'draft_kpi_1_effective_score'=>0,'daily_kpi_rule'=>'locked=actual,draft=0,missing=0','discipline_denominator'=>'attendance_eligible_shifts','scheduled_shifts'=>$scheduled,'attendance_shifts'=>$attendanceShifts,'late_count'=>$late],JSON_UNESCAPED_SLASHES)];
        }
        return$rows;
    }

    private function dailyReviewStatusSummary(string $outletId,string $from,string $to): array
    {
        $rows=DB::table('HR_kpi_daily_reviews')->where('outlet_id',$outletId)->whereBetween('review_date',[$from,$to])->get(['review_date','status']);
        $locked=$rows->where('status','locked')->count();$draft=$rows->where('status','draft')->count();$calendarDays=Carbon::parse($from)->diffInDays(Carbon::parse($to))+1;$reviewDays=$rows->pluck('review_date')->map(fn($v)=>(string)$v)->unique()->count();$missing=max(0,$calendarDays-$reviewDays);
        return['calendar_days'=>$calendarDays,'review_days'=>$reviewDays,'locked_days'=>$locked,'draft_days'=>$draft,'missing_days'=>$missing,'draft_kpi_1_effective_score'=>0,'rule'=>'LOCKED dihitung apa adanya; DRAFT KPI 1 = 0; MISSING KPI 1 = 0; source Daily KPI tidak dimutasi.'];
    }

    private function seedProjectionLines(string $projectionId,string $periodId,array $policy,string $asOf): void
    {
        DB::table('HR_bonus_projection_lines')->where('projection_id',$projectionId)->delete();$scores=DB::table('HR_kpi_period_scores')->where('period_id',$periodId)->get();foreach($scores as$s){$grade=$this->grade((float)$s->kpi_total,$policy);$contract=$this->contractEntitlement((string)$s->employee_id,$policy,$asOf);$sp=$this->spRule((string)$s->employee_id,$policy,$asOf);DB::table('HR_bonus_projection_lines')->insert(['id'=>(string)Str::ulid(),'projection_id'=>$projectionId,'employee_id'=>$s->employee_id,'squad_id'=>$s->squad_id,'nisj_snapshot'=>$s->nisj_snapshot,'name_snapshot'=>$s->name_snapshot,'personal_shifts'=>(int)$s->scheduled_shifts,'kpi_score'=>(float)$s->kpi_total,'grade'=>$grade['grade'],'grade_multiplier'=>$grade['multiplier'],'contract_entitlement'=>$contract['code'],'contract_multiplier'=>$contract['multiplier'],'sp_level'=>$sp['level'],'sp_multiplier'=>$sp['multiplier'],'personal_base_budget'=>0,'bonus_payout'=>0,'forfeited_budget'=>0,'source_snapshot'=>json_encode(['contract'=>$contract,'sp'=>$sp,'kpi_period_score_id'=>$s->id],JSON_UNESCAPED_SLASHES),'created_at'=>now(),'updated_at'=>now()]);}}

    private function contractEntitlement(string $employeeId,array $policy,string $asOf): array
    {
        $contract=DB::table('HR_contracts')->where('employee_id',$employeeId)->whereNull('deleted_at')->where('status','active')->where(function($q)use($asOf){$q->whereNull('start_date')->orWhereDate('start_date','<=',$asOf);})->where(function($q)use($asOf){$q->whereNull('end_date')->orWhereDate('end_date','>=',$asOf);})->orderByDesc('start_date')->first();$type=strtoupper(trim((string)($contract->contract_type??'UNSPECIFIED')));foreach($policy['contract_rules']??[]as$r){foreach($r['patterns']??[]as$pattern){if($pattern==='*'||str_contains($type,strtoupper((string)$pattern)))return['code'=>(string)$r['code'],'multiplier'=>(float)$r['multiplier'],'contract_type'=>$type,'contract_id'=>$contract?->id];}}return['code'=>'UNMAPPED','multiplier'=>0.0,'contract_type'=>$type,'contract_id'=>$contract?->id];
    }

    private function spRule(string $employeeId,array $policy,string $asOf): array
    {
        $level=(int)(DB::table('HR_warning_letters')->where('employee_id',$employeeId)->where('status','approved')->where(function($q)use($asOf){$q->whereNull('effective_date')->orWhereDate('effective_date','<=',$asOf);})->max('sp_level')??0);$level=max(0,min(3,$level));$mult=(float)($policy['sp_rules'][(string)$level]??($level===0?1:0));return['level'=>$level,'multiplier'=>$mult];
    }

    private function grade(float $score,?array $policy=null): array
    {
        $policy??=$this->activePolicy()['config'];foreach($policy['grade_bands']??[]as$b){if($score>=(float)$b['min']&&$score<=(float)$b['max'])return['grade'=>(string)$b['grade'],'multiplier'=>(float)$b['multiplier']];}return['grade'=>'F','multiplier'=>0.0];
    }

    private function activePolicy(): array
    {
        $row=DB::table('HR_bonus_policies')->where('code','BONUS_WORKBOOK_V1')->where('is_active',true)->orderByDesc('version')->first();if(!$row)throw new \RuntimeException('Bonus policy BONUS_WORKBOOK_V1 belum tersedia.');return['id'=>(string)$row->id,'code'=>(string)$row->code,'version'=>(int)$row->version,'config'=>json_decode((string)$row->config_json,true)?:[],'description'=>$row->description];
    }

    private function kpiRule(): array{return['version'=>self::KPI_RULE_VERSION,'source'=>'KPI APRIL 2026.xlsx','grooming_weight'=>40,'discipline_weight'=>60,'grooming_target_days'=>(int)(json_decode((string)(DB::table('HR_kpi_settings')->where('code','GROOMING_TARGET_DAYS')->value('value_json')?:'{"days":25}'),true)['days']??25),'daily_kpi_1_status_rule'=>'locked=actual,draft=0,missing=0','daily_kpi_source_mutation'=>false,'discipline_denominator'=>'attendance_eligible_shifts','late_definition'=>'checkin_at > shift.start_time_snapshot','missing_attendance_component'=>0];}
    private function revenue(string $outletId,string $from,string $to): float{if(!Schema::hasTable('report_daily_sales_summaries'))return 0;return round((float)DB::table('report_daily_sales_summaries')->where('outlet_id',$outletId)->whereBetween('business_date',[$from,$to])->sum('grand_sales'),2);}
    private function assertOutletScope(Request $request,string $outletId): void{if(!in_array($outletId,$this->scope->allowedOutletIds($request),true))throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope Human Resource user.']]);}
    private function assertPeriod(string $from,string $to): void{$a=Carbon::parse($from);$b=Carbon::parse($to);if($b->lt($a))throw ValidationException::withMessages(['period_to'=>['Tanggal akhir harus setelah tanggal awal.']]);if($a->diffInDays($b)>62)throw ValidationException::withMessages(['period_to'=>['Periode KPI maksimal 63 hari per kalkulasi.']]);}

    private function kpiPeriodById(string $id): array
    {
        $p=DB::table('HR_kpi_periods as p')->join('outlets as o','o.id','=','p.outlet_id')->where('p.id',$id)->first(['p.*','o.code as outlet_code','o.name as outlet_name']);if(!$p)throw ValidationException::withMessages(['period'=>['KPI period tidak ditemukan.']]);$scores=DB::table('HR_kpi_period_scores')->where('period_id',$id)->orderByDesc('kpi_total')->orderBy('name_snapshot')->get()->map(fn($s)=>['id'=>(string)$s->id,'employee_id'=>(string)$s->employee_id,'nisj'=>$s->nisj_snapshot,'full_name'=>(string)$s->name_snapshot,'division'=>$s->division_snapshot,'grooming_raw'=>(float)$s->grooming_raw,'grooming_workbook_max'=>(float)$s->grooming_workbook_max,'grooming_component_40'=>(float)$s->grooming_component_40,'scheduled_shifts'=>(int)$s->scheduled_shifts,'attendance_shifts'=>(int)$s->attendance_shifts,'late_count'=>(int)$s->late_count,'late_percent'=>(float)$s->late_percent,'discipline_component_60'=>(float)$s->discipline_component_60,'kpi_total'=>(float)$s->kpi_total,'grade'=>(string)$s->grade_snapshot])->all();return$this->shapeKpiPeriod($p,(object)['employees'=>count($scores),'avg_kpi'=>collect($scores)->avg('kpi_total')?:0,'avg_grooming'=>collect($scores)->avg('grooming_component_40')?:0,'avg_discipline'=>collect($scores)->avg('discipline_component_60')?:0])+['scores'=>$scores,'rule_snapshot'=>json_decode((string)$p->rule_snapshot,true)?:[]];
    }

    private function shapeKpiPeriod(object $p,?object $c=null): array{return['id'=>(string)$p->id,'outlet_id'=>(string)$p->outlet_id,'outlet_code'=>(string)($p->outlet_code??''),'outlet_name'=>(string)($p->outlet_name??''),'period_from'=>(string)$p->period_from,'period_to'=>(string)$p->period_to,'status'=>(string)$p->status,'revision'=>(int)$p->revision,'rule_version'=>(string)$p->rule_version,'employees'=>(int)($c->employees??0),'avg_kpi'=>round((float)($c->avg_kpi??0),4),'avg_grooming'=>round((float)($c->avg_grooming??0),4),'avg_discipline'=>round((float)($c->avg_discipline??0),4),'calculated_at'=>$p->calculated_at,'locked_at'=>$p->locked_at];}

    private function projectionById(string $id): array
    {
        $p=DB::table('HR_bonus_projections as b')->join('outlets as o','o.id','=','b.outlet_id')->leftJoin('finance_payroll_posting_inbox as f','f.id','=','b.finance_posting_id')->where('b.id',$id)->first(['b.*','o.code as outlet_code','o.name as outlet_name','f.status as finance_status','f.payroll_batch_id']);if(!$p)throw ValidationException::withMessages(['projection'=>['Proyeksi bonus tidak ditemukan.']]);$lines=DB::table('HR_bonus_projection_lines')->where('projection_id',$id)->orderByDesc('bonus_payout')->orderBy('name_snapshot')->get()->map(fn($l)=>['id'=>(string)$l->id,'employee_id'=>(string)$l->employee_id,'nisj'=>$l->nisj_snapshot,'full_name'=>(string)$l->name_snapshot,'personal_shifts'=>(int)$l->personal_shifts,'kpi_score'=>(float)$l->kpi_score,'grade'=>(string)$l->grade,'grade_multiplier'=>(float)$l->grade_multiplier,'contract_entitlement'=>(string)$l->contract_entitlement,'contract_multiplier'=>(float)$l->contract_multiplier,'sp_level'=>(int)$l->sp_level,'sp_multiplier'=>(float)$l->sp_multiplier,'personal_base_budget'=>(float)$l->personal_base_budget,'bonus_payout'=>(float)$l->bonus_payout,'forfeited_budget'=>(float)$l->forfeited_budget])->all();return$this->shapeProjection($p)+['lines'=>$lines,'policy_snapshot'=>json_decode((string)$p->policy_snapshot,true)?:[]];
    }

    private function shapeProjection(object $p): array{return['id'=>(string)$p->id,'kpi_period_id'=>(string)$p->kpi_period_id,'outlet_id'=>(string)$p->outlet_id,'outlet_code'=>(string)($p->outlet_code??''),'outlet_name'=>(string)($p->outlet_name??''),'period_from'=>(string)$p->period_from,'period_to'=>(string)$p->period_to,'status'=>(string)$p->status,'revenue'=>(float)$p->revenue_snapshot,'total_shifts'=>(int)$p->total_shifts_snapshot,'budget_percentage'=>$p->finance_budget_percentage===null?null:(float)$p->finance_budget_percentage,'bonus_budget'=>(float)$p->bonus_budget,'bonus_per_shift'=>(float)$p->bonus_per_shift,'total_payout'=>(float)$p->total_payout,'remaining_budget'=>(float)$p->remaining_budget,'finance_posting_id'=>$p->finance_posting_id?(string)$p->finance_posting_id:null,'finance_status'=>$p->finance_status??null,'payroll_batch_id'=>$p->payroll_batch_id??null,'policy_code'=>(string)$p->policy_code,'policy_version'=>(int)$p->policy_version,'submitted_at'=>$p->submitted_at,'finalized_at'=>$p->finalized_at];}
    private function event(string $projectionId,string $event,?string $from,?string $to,?string $actor,array $metadata=[]): void{DB::table('HR_bonus_projection_events')->insert(['id'=>(string)Str::ulid(),'projection_id'=>$projectionId,'event'=>$event,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$actor,'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES):null,'created_at'=>now()]);}
}
