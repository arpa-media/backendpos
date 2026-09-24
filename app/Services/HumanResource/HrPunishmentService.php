<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\HumanResource\HrContract;
use App\Models\HumanResource\HrContractDocument;
use App\Models\HumanResource\HrContractDocumentTemplate;
use App\Models\HumanResource\HrPunishmentRule;
use App\Models\HumanResource\HrViolation;
use App\Models\HumanResource\HrViolationRecommendation;
use App\Models\HumanResource\HrWarningLetter;
use App\Models\HumanResource\HrWarningLetterApproval;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrPunishmentService
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrPunishmentAutomationService $automation,
        private readonly HrContractDocumentService $contractDocuments,
        private readonly HrDocumentBrandingService $branding,
        private readonly HrDocumentNumberService $numbers,
        private readonly HrDocumentTemplateCatalog $catalog,
        private readonly HrDocumentSignerI14Service $signers,
        private readonly HrSpValiditySettingService $spValidity,
    ) {}

    public function references(Request $request): array
    {
        $outlets = $this->scope->options($request);
        $allowed = collect($outlets)->pluck('id')->all();
        if ($allowed === []) return ['outlets'=>[],'employees'=>[],'rules'=>$this->rules(),'validity_rules'=>$this->spValidity->rules()];

        $rows = DB::table('assignments as a')
            ->join('employees as e','e.id','=','a.employee_id')
            ->join('users as u','u.id','=','e.user_id')
            ->leftJoin('HR_squads as s', function ($join) {
                $join->on(DB::raw('LOWER(TRIM(s.nisj))'),'=',DB::raw('LOWER(TRIM(e.nisj))'))->whereNull('s.deleted_at');
            })
            ->join('outlets as o','o.id','=','a.outlet_id')
            ->whereIn('a.outlet_id',$allowed)->where('u.is_active',true)
            ->where(function($q){$q->whereNull('a.status')->orWhereRaw("LOWER(a.status) NOT IN ('inactive','cancelled')");})
            ->orderByDesc('a.is_primary')->orderBy('e.full_name')
            ->get(['e.id','e.nisj','e.full_name','a.outlet_id','o.name as outlet_name','a.role_title','s.id as squad_id','s.division_name','s.position_name']);

        $spStates = $this->spValidity->currentStatesForEmployees($rows->pluck('id')->unique());
        $employees = $rows->unique('id')->map(function ($r) use ($spStates) {
            $state = $spStates[(string) $r->id] ?? null;
            return [
                'id'=>(string)$r->id,'nisj'=>(string)($r->nisj ?? ''),'full_name'=>(string)($r->full_name ?? '-'),
                'outlet_id'=>(string)$r->outlet_id,'outlet_name'=>(string)$r->outlet_name,
                'position'=>(string)($r->position_name ?: $r->role_title ?: '-'),'division'=>(string)($r->division_name ?? '-'),
                'squad_id'=>$r->squad_id ? (int)$r->squad_id : null,
                'current_sp_level'=>(int)($state['level'] ?? 0),
                'current_sp_validity_days'=>$state['days'] ?? null,
                'current_sp_validity_end'=>$state['end'] ?? null,
            ];
        })->values()->all();

        return ['outlets'=>$outlets,'employees'=>$employees,'rules'=>$this->rules(),'validity_rules'=>$this->spValidity->rules()];
    }

    public function summary(Request $request): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return ['draft_violations'=>0,'pending_recommendations'=>0,'submitted_sp'=>0,'sp3_approved'=>0];
        return [
            'draft_violations'=>HrViolation::query()->whereIn('outlet_id',$allowed)->whereIn('status',['draft','submitted'])->count(),
            'pending_recommendations'=>HrViolationRecommendation::query()->whereIn('outlet_id',$allowed)->where('status','pending')->count(),
            'submitted_sp'=>HrWarningLetter::query()->whereIn('outlet_id',$allowed)->where('status','submitted')->count(),
            'sp3_approved'=>HrWarningLetter::query()->whereIn('outlet_id',$allowed)->where('status','approved')->where('sp_level',3)->count(),
        ];
    }

    public function violations(Request $request, array $filters): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return $this->emptyPage();
        $query = HrViolation::withoutGlobalScope(SoftDeletingScope::class)->from('HR_violations as v')
            ->join('employees as e','e.id','=','v.employee_id')
            ->leftJoin('outlets as o','o.id','=','v.outlet_id')
            ->whereNull('v.deleted_at')->whereIn('v.outlet_id',$allowed)
            ->when($filters['status'] ?? null, fn($q,$v)=>$q->where('v.status',$v))
            ->when($filters['violation_type'] ?? null, fn($q,$v)=>$q->where('v.violation_type',$v))
            ->when($filters['outlet_id'] ?? null, fn($q,$v)=>$q->where('v.outlet_id',$v))
            ->when($filters['employee_id'] ?? null, fn($q,$v)=>$q->where('v.employee_id',$v))
            ->when($filters['from'] ?? null, fn($q,$v)=>$q->whereDate('v.violation_date','>=',$v))
            ->when($filters['to'] ?? null, fn($q,$v)=>$q->whereDate('v.violation_date','<=',$v))
            ->when($filters['search'] ?? null, function($q,$v){ $needle='%'.trim($v).'%'; $q->where(function($x)use($needle){$x->where('e.full_name','like',$needle)->orWhere('e.nisj','like',$needle)->orWhere('v.title','like',$needle);}); })
            ->select(['v.*','e.full_name','e.nisj','o.name as outlet_name']);
        return $this->page($query,$filters,['violation_date','full_name','violation_type','status','late_minutes'],'violation_date','desc');
    }

    public function reportViolation(Request $request, array $data, ?User $actor): array
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $outletId = $data['outlet_id'] ?? $this->primaryOutletId($employee->id);
        if (!$outletId || !$this->scope->isOutletAllowed($request,(string)$outletId)) {
            throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope user atau employee belum memiliki penugasan aktif.']]);
        }
        $squad = $this->squadForNisj($employee->nisj);
        $sourceType = trim((string)($data['source_type'] ?? 'manual')) ?: 'manual';
        $sourceRef = trim((string)($data['source_ref'] ?? '')) ?: null;
        $fingerprint = $sourceRef
            ? hash('sha256',implode('|',[$sourceType,$sourceRef,(string)$employee->id,(string)$data['violation_type']]))
            : hash('sha256','manual|'.(string)Str::ulid());
        $row = HrViolation::query()->firstOrCreate(['fingerprint'=>$fingerprint], [
            'employee_id'=>$employee->id,'squad_id'=>$squad?->id,'outlet_id'=>$outletId,
            'violation_type'=>$data['violation_type'],'violation_date'=>$data['violation_date'],'title'=>trim($data['title']),
            'description'=>trim((string)($data['description'] ?? '')) ?: null,'severity'=>$data['severity'] ?? 'medium',
            'late_minutes'=>(int)($data['late_minutes'] ?? 0),'source_type'=>$sourceType,'source_ref'=>$sourceRef,
            'status'=>'draft','metadata'=>$data['metadata'] ?? null,'created_by_user_id'=>$actor?->id,
        ]);
        return $this->violationPayload($row);
    }

    public function updateViolation(Request $request, string $id, array $data, ?User $actor): array
    {
        $row = HrViolation::query()->findOrFail($id);
        $this->assertViolationScope($request,$row);
        if (!in_array($row->status,['draft','rejected'],true)) throw ValidationException::withMessages(['violation'=>['Hanya draft/rejected yang dapat diedit.']]);
        $row->fill(array_filter([
            'violation_type'=>$data['violation_type'] ?? null,'violation_date'=>$data['violation_date'] ?? null,'title'=>$data['title'] ?? null,
            'description'=>array_key_exists('description',$data)?$data['description']:null,'severity'=>$data['severity'] ?? null,
            'late_minutes'=>array_key_exists('late_minutes',$data)?(int)$data['late_minutes']:null,
        ],fn($v)=>$v!==null))->save();
        return $this->violationPayload($row->fresh());
    }

    public function submitViolation(Request $request, string $id, ?User $actor): array
    {
        $row = HrViolation::query()->findOrFail($id); $this->assertViolationScope($request,$row);
        if (!in_array($row->status,['draft','rejected'],true)) throw ValidationException::withMessages(['violation'=>['Hanya draft/rejected yang dapat diajukan.']]);
        $row->update(['status'=>'submitted','submitted_by_user_id'=>$actor?->id,'submitted_at'=>now(),'rejected_by_user_id'=>null,'rejected_at'=>null,'decision_note'=>null]);
        return $this->violationPayload($row->fresh());
    }

    public function approveViolation(Request $request, string $id, ?User $actor, ?string $note): array
    {
        $row = HrViolation::query()->findOrFail($id); $this->assertViolationScope($request,$row);
        if ($row->status !== 'submitted') throw ValidationException::withMessages(['violation'=>['Pelanggaran harus submitted sebelum approve.']]);
        $row->update(['status'=>'approved','approved_by_user_id'=>$actor?->id,'approved_at'=>now(),'decision_note'=>$note]);
        $this->automation->evaluateEmployee((string)$row->employee_id);
        return $this->violationPayload($row->fresh());
    }

    public function rejectViolation(Request $request, string $id, ?User $actor, string $note): array
    {
        $row = HrViolation::query()->findOrFail($id); $this->assertViolationScope($request,$row);
        if ($row->status !== 'submitted') throw ValidationException::withMessages(['violation'=>['Pelanggaran harus submitted sebelum reject.']]);
        $row->update(['status'=>'rejected','rejected_by_user_id'=>$actor?->id,'rejected_at'=>now(),'decision_note'=>$note]);
        return $this->violationPayload($row->fresh());
    }

    public function deleteViolation(Request $request, string $id): bool
    {
        $row = HrViolation::query()->findOrFail($id); $this->assertViolationScope($request,$row);
        if (!in_array($row->status,['draft','rejected','approved'],true) || $row->recommendation_id) throw ValidationException::withMessages(['violation'=>['Hanya pelanggaran draft/rejected/approved yang belum terhubung rekomendasi dapat dihapus.']]);
        $employeeId = (string) $row->employee_id;
        $deleted = (bool) $row->delete();
        if ($deleted && $employeeId !== '') $this->automation->evaluateEmployee($employeeId);
        return $deleted;
    }

    public function recommendations(Request $request, array $filters): array
    {
        $allowed = $this->scope->allowedOutletIds($request); if ($allowed===[]) return $this->emptyPage();
        $query = HrViolationRecommendation::query()->from('HR_violation_recommendations as r')
            ->join('employees as e','e.id','=','r.employee_id')->leftJoin('outlets as o','o.id','=','r.outlet_id')
            ->leftJoin('HR_punishment_rules as pr','pr.id','=','r.rule_id')->whereIn('r.outlet_id',$allowed)
            ->when($filters['status'] ?? null,fn($q,$v)=>$q->where('r.status',$v))
            ->when($filters['employee_id'] ?? null,fn($q,$v)=>$q->where('r.employee_id',$v))
            ->when($filters['outlet_id'] ?? null,fn($q,$v)=>$q->where('r.outlet_id',$v))
            ->when($filters['search'] ?? null,function($q,$v){$n='%'.trim($v).'%';$q->where(function($x)use($n){$x->where('e.full_name','like',$n)->orWhere('e.nisj','like',$n)->orWhere('r.reason','like',$n);});})
            ->select(['r.*','e.full_name','e.nisj','o.name as outlet_name','pr.name as rule_name']);
        return $this->page($query,$filters,['created_at','full_name','recommended_sp_level','status'],'created_at','desc');
    }

    public function dismissRecommendation(Request $request, string $id, ?User $actor, string $note): array
    {
        $row = HrViolationRecommendation::query()->findOrFail($id); $this->assertRecommendationScope($request,$row);
        if ($row->status !== 'pending') throw ValidationException::withMessages(['recommendation'=>['Hanya rekomendasi pending yang dapat di-dismiss.']]);
        $row->update(['status'=>'dismissed','acted_by_user_id'=>$actor?->id,'acted_at'=>now(),'action_note'=>$note]);
        return $this->recommendationPayload($row->fresh());
    }

    public function convertRecommendation(Request $request, string $id, array $data, ?User $actor): array
    {
        $rec = HrViolationRecommendation::query()->findOrFail($id); $this->assertRecommendationScope($request,$rec);
        if ($rec->status !== 'pending') throw ValidationException::withMessages(['recommendation'=>['Rekomendasi sudah diproses.']]);
        return DB::transaction(function() use($rec,$data,$actor) {
            $locked = HrViolationRecommendation::query()->whereKey($rec->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') throw ValidationException::withMessages(['recommendation'=>['Rekomendasi sudah diproses.']]);
            $letter = $this->createWarningLetterCore((string)$locked->employee_id, (int)$locked->recommended_sp_level, [
                'issue_date'=>$data['issue_date'],'effective_date'=>$data['effective_date'],'reason'=>$data['reason'] ?? $locked->reason,
                'recommendation_id'=>$locked->id,'outlet_id'=>$locked->outlet_id,
                'company_code'=>$data['company_code'] ?? null,'template_key'=>$data['template_key'] ?? null,
            ], $actor);
            $locked->update(['status'=>'converted','warning_letter_id'=>$letter->id,'acted_by_user_id'=>$actor?->id,'acted_at'=>now(),'action_note'=>'Dikonversi menjadi '.$letter->letter_no]);
            return $this->warningLetterPayload($letter->fresh());
        });
    }

    public function warningLetters(Request $request, array $filters): array
    {
        $allowed = $this->scope->allowedOutletIds($request); if ($allowed===[]) return $this->emptyPage();
        $query = HrWarningLetter::withoutGlobalScope(SoftDeletingScope::class)->from('HR_warning_letters as w')
            ->join('employees as e','e.id','=','w.employee_id')->leftJoin('outlets as o','o.id','=','w.outlet_id')->leftJoin('users as creator','creator.id','=','w.created_by_user_id')->leftJoin('users as approver','approver.id','=','w.approved_by_user_id')
            ->whereNull('w.deleted_at')->whereIn('w.outlet_id',$allowed)
            ->when($filters['status'] ?? null,fn($q,$v)=>$q->where('w.status',$v))
            ->when($filters['sp_level'] ?? null,fn($q,$v)=>$q->where('w.sp_level',(int)$v))
            ->when($filters['employee_id'] ?? null,fn($q,$v)=>$q->where('w.employee_id',$v))
            ->when($filters['outlet_id'] ?? null,fn($q,$v)=>$q->where('w.outlet_id',$v))
            ->when($filters['search'] ?? null,function($q,$v){$n='%'.trim($v).'%';$q->where(function($x)use($n){$x->where('e.full_name','like',$n)->orWhere('e.nisj','like',$n)->orWhere('w.letter_no','like',$n);});})
            ->select(['w.*','e.full_name','e.nisj','o.name as outlet_name','creator.name as created_by_name','approver.name as approved_by_name']);
        $page = $this->page($query,$filters,['issue_date','full_name','sp_level','status','letter_no'],'issue_date','desc');
        $page['items'] = collect($page['items'] ?? [])->map(function (array $row): array {
            $startValue = $row['effective_date'] ?? $row['issue_date'] ?? null;
            $validity = $startValue ? $this->spValidity->validityWindow($startValue, (int) ($row['sp_level'] ?? 1)) : null;
            $isActive = false;
            if (($row['status'] ?? null) === 'approved' && $startValue) {
                $asObject = (object) [
                    'status' => $row['status'],
                    'effective_date' => $row['effective_date'] ?? null,
                    'issue_date' => $row['issue_date'] ?? null,
                    'sp_level' => (int) ($row['sp_level'] ?? 1),
                ];
                $isActive = $this->spValidity->isActiveLetter($asObject);
            }
            $row['validity_days'] = $validity['days'] ?? $this->spValidity->daysForLevel((int) ($row['sp_level'] ?? 1));
            $row['validity_start'] = $validity ? $validity['start']->toDateString() : null;
            $row['validity_end'] = $validity ? $validity['end']->toDateString() : null;
            $row['validity_status'] = ($row['status'] ?? null) === 'approved' ? ($isActive ? 'ACTIVE' : 'EXPIRED') : strtoupper((string) ($row['status'] ?? ''));
            return $row;
        })->values()->all();
        return $page;
    }

    public function createWarningLetter(Request $request, array $data, ?User $actor): array
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $outletId = $data['outlet_id'] ?? $this->primaryOutletId($employee->id);
        if (!$outletId || !$this->scope->isOutletAllowed($request,(string)$outletId)) throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope user.']]);
        $effectiveForSequence = $data['effective_date'] ?? $data['issue_date'] ?? now('Asia/Jakarta')->toDateString();
        $current = $this->currentSpLevel((string)$employee->id, $effectiveForSequence);
        $level = isset($data['sp_level']) ? (int)$data['sp_level'] : $current+1;
        return $this->warningLetterPayload($this->createWarningLetterCore((string)$employee->id,$level,array_merge($data,['outlet_id'=>$outletId]),$actor));
    }

    public function submitWarningLetter(Request $request, string $id, ?User $actor): array
    {
        $row = HrWarningLetter::query()->findOrFail($id); $this->assertWarningLetterScope($request,$row);
        if (!in_array($row->status,['draft','rejected'],true)) throw ValidationException::withMessages(['warning_letter'=>['Hanya draft/rejected yang dapat diajukan.']]);
        DB::transaction(function() use($row,$actor) {
            $row->update(['status'=>'submitted','submitted_by_user_id'=>$actor?->id,'submitted_at'=>now(),'rejected_by_user_id'=>null,'rejected_at'=>null,'decision_note'=>null]);
            HrWarningLetterApproval::query()->updateOrCreate(['warning_letter_id'=>$row->id,'step_number'=>1],[
                'status'=>'pending','requested_by_user_id'=>$actor?->id,'requested_at'=>now(),'approver_user_id'=>null,'decided_at'=>null,'note'=>null,
            ]);
        });
        return $this->warningLetterPayload($row->fresh());
    }

    public function approveWarningLetter(Request $request, string $id, ?User $actor, ?string $note): array
    {
        $row = HrWarningLetter::query()->findOrFail($id); $this->assertWarningLetterScope($request,$row);
        return DB::transaction(function() use($row,$actor,$note) {
            $locked = HrWarningLetter::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted') throw ValidationException::withMessages(['warning_letter'=>['SP harus submitted sebelum approve.']]);
            $sequenceDate = $locked->effective_date?->toDateString() ?: ($locked->issue_date?->toDateString() ?: now('Asia/Jakarta')->toDateString());
            $current = $this->currentSpLevel((string) $locked->employee_id, $sequenceDate);
            if ((int)$locked->sp_level !== $current+1) throw ValidationException::withMessages(['sp_level'=>['Urutan SP tidak valid berdasarkan validity aktif. Level berikutnya seharusnya SP-'.($current+1).'.']]);
            $locked->update(['status'=>'approved','approved_by_user_id'=>$actor?->id,'approved_at'=>now(),'decision_note'=>$note]);
            HrWarningLetterApproval::query()->where('warning_letter_id',$locked->id)->where('step_number',1)->update([
                'status'=>'approved','approver_user_id'=>$actor?->id,'decided_at'=>now(),'note'=>$note,
            ]);
            if ((int)$locked->sp_level === 3) $this->applySp3Termination($locked,$actor);
            return $this->warningLetterPayload($locked->fresh());
        });
    }

    public function rejectWarningLetter(Request $request, string $id, ?User $actor, string $note): array
    {
        $row = HrWarningLetter::query()->findOrFail($id); $this->assertWarningLetterScope($request,$row);
        if ($row->status !== 'submitted') throw ValidationException::withMessages(['warning_letter'=>['SP harus submitted sebelum reject.']]);
        DB::transaction(function() use($row,$actor,$note) {
            $row->update(['status'=>'rejected','rejected_by_user_id'=>$actor?->id,'rejected_at'=>now(),'decision_note'=>$note]);
            HrWarningLetterApproval::query()->where('warning_letter_id',$row->id)->where('step_number',1)->update([
                'status'=>'rejected','approver_user_id'=>$actor?->id,'decided_at'=>now(),'note'=>$note,
            ]);
        });
        return $this->warningLetterPayload($row->fresh());
    }

    public function rules(): array
    {
        return HrPunishmentRule::query()->orderBy('event_type')->orderBy('code')->get()->map(fn($r)=>[
            'id'=>(string)$r->id,'code'=>$r->code,'name'=>$r->name,'event_type'=>$r->event_type,'threshold_count'=>(int)$r->threshold_count,
            'window_days'=>(int)$r->window_days,'grace_minutes'=>(int)$r->grace_minutes,'auto_create_violation'=>(bool)$r->auto_create_violation,
            'auto_recommend_sp'=>(bool)$r->auto_recommend_sp,'is_active'=>(bool)$r->is_active,'config'=>$r->config,
        ])->values()->all();
    }

    public function saveRule(?string $id, array $data, ?User $actor): array
    {
        $row = $id ? HrPunishmentRule::query()->findOrFail($id) : new HrPunishmentRule();
        $row->fill($data); if (!$id) $row->created_by_user_id=$actor?->id; $row->updated_by_user_id=$actor?->id; $row->save();
        return collect($this->rules())->firstWhere('id',(string)$row->id) ?? [];
    }

    public function automationSweep(array $data): array
    {
        return $this->automation->sweep($data['from'] ?? null,$data['to'] ?? null,(int)($data['limit'] ?? 500));
    }

    private function createWarningLetterCore(string $employeeId, int $level, array $data, ?User $actor): HrWarningLetter
    {
        if ($level < 1 || $level > 3) throw ValidationException::withMessages(['sp_level'=>['SP hanya tersedia dari level 1 sampai 3.']]);
        $issueForSequence = $data['issue_date'] ?? now('Asia/Jakarta')->toDateString();
        $effectiveForSequence = $data['effective_date'] ?? $issueForSequence;
        $current = $this->currentSpLevel($employeeId, $effectiveForSequence);
        if ($level !== $current+1) throw ValidationException::withMessages(['sp_level'=>['Level SP berikutnya berdasarkan validity aktif harus SP-'.($current+1).'.']]);
        if (HrWarningLetter::query()->where('employee_id',$employeeId)->whereIn('status',['draft','submitted'])->exists()) {
            throw ValidationException::withMessages(['employee_id'=>['Masih ada draft/submitted SP yang belum diselesaikan untuk employee ini.']]);
        }

        $employee = Employee::query()->with('user')->findOrFail($employeeId);
        $squad = $this->squadForNisj($employee->nisj);
        $outletId = $data['outlet_id'] ?? $this->primaryOutletId($employeeId);
        $outlet = $outletId ? DB::table('outlets')->where('id',$outletId)->first() : null;
        $assignment = Assignment::query()->where('employee_id',$employeeId)->where('is_primary',true)->orderByDesc('start_date')->first();
        $contract = HrContract::query()->where('employee_id',$employeeId)->whereNotIn('status',['terminated','cancelled'])->orderByDesc('start_date')->first();
        $issue = $data['issue_date'] ?? now()->toDateString();
        $effective = $data['effective_date'] ?? $issue;
        $reason = trim((string)($data['reason'] ?? ''));

        $companyCode = $this->branding->resolveCompanyCode($data['company_code'] ?? null, $outletId);
        $templateKey = trim((string)($data['template_key'] ?? '')) ?: $this->catalog->keyFor('warning', $companyCode);
        $definition = $this->catalog->definitionFor($templateKey);
        if (($definition['business_type'] ?? null) !== 'warning' || (($definition['company_code'] ?? null) && $definition['company_code'] !== $companyCode)) {
            throw ValidationException::withMessages(['template_key'=>['Template Surat Peringatan tidak sesuai PT yang dipilih.']]);
        }
        $brandingSnapshot = $this->signers->snapshotFor($templateKey, $companyCode, $this->branding->snapshot($companyCode));
        $template = HrContractDocumentTemplate::query()
            ->where('document_type', $templateKey)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
        if (! $template) {
            throw ValidationException::withMessages(['template_key'=>['Template Surat Peringatan canonical belum tersedia. Jalankan migration Iterasi 02.']]);
        }

        $values = [
            'full_name'=>$employee->full_name ?: '-',
            'nisj'=>$employee->nisj ?: '-',
            'position'=>$squad?->position_name ?: $assignment?->role_title ?: '-',
            'division'=>$squad?->division_name ?: '-',
            'outlet_id'=>$outletId,
            'outlet_name'=>$outlet?->name ?: '-',
            'current_outlet_name'=>$outlet?->name ?: '-',
            'current_outlet_address'=>$outlet?->address ?: '-',
            'sp_level'=>$level,
            'reason'=>$reason ?: '-',
            'issue_date'=>Carbon::parse($issue)->locale('id')->translatedFormat('d F Y'),
            'effective_date'=>Carbon::parse($effective)->locale('id')->translatedFormat('d F Y'),
            'company_code'=>$companyCode,
            'company_name'=>$brandingSnapshot['company_name'],
            'company_address'=>$brandingSnapshot['address'],
            'company_phone'=>$brandingSnapshot['phone'],
            'company_email'=>$brandingSnapshot['email'],
            'signatory_name'=>$brandingSnapshot['signatory_name'],
            'signatory_role'=>$brandingSnapshot['signatory_role'],
            'created_by_user_id'=>$actor?->id,
            'created_by_name'=>$actor?->name,
            'generated_at'=>now()->toIso8601String(),
        ];
        $title = $this->catalog->render((string)$template->title_template, $values);
        $body = $this->catalog->render((string)$template->body_template, $values);
        $letterNo = $this->numbers->allocate('SP', $companyCode, $issue);

        return HrWarningLetter::query()->create([
            'employee_id'=>$employeeId,
            'squad_id'=>$squad?->id,
            'outlet_id'=>$outletId,
            'contract_id'=>$contract?->id,
            'recommendation_id'=>$data['recommendation_id'] ?? null,
            'template_key'=>$templateKey,
            'company_code'=>$companyCode,
            'letter_code'=>$definition['letter_code'],
            'sp_level'=>$level,
            'letter_no'=>$letterNo,
            'issue_date'=>$issue,
            'effective_date'=>$effective,
            'title'=>$title,
            'reason'=>$reason,
            'body_snapshot'=>$body,
            'employee_snapshot'=>$values,
            'branding_snapshot'=>$brandingSnapshot,
            'status'=>'draft',
            'created_by_user_id'=>$actor?->id,
        ]);
    }

    private function applySp3Termination(HrWarningLetter $letter, ?User $actor): void
    {
        $employee = Employee::query()->whereKey($letter->employee_id)->lockForUpdate()->firstOrFail();
        $effective = $letter->effective_date?->toDateString() ?: now()->toDateString();
        $assignments = Assignment::query()->where('employee_id',$employee->id)->where('is_primary',true)->get();
        foreach ($assignments as $assignment) {
            $assignment->end_date = $effective; $assignment->is_primary=false; $assignment->status='inactive'; $assignment->save();
        }
        $employee->assignment_id=null; $employee->employment_status='inactive'; $employee->save();

        $squad = $letter->squad_id ? DB::table('HR_squads')->where('id',$letter->squad_id)->first() : $this->squadForNisj($employee->nisj);
        if ($squad) DB::table('HR_squads')->where('id',$squad->id)->update(['status'=>'inactive','contract_end_date'=>$effective,'updated_at'=>now()]);
        $userIds = collect([$employee->user_id,$squad?->user_id])->filter()->unique()->values();
        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id',$userIds)->update(['is_active'=>false,'updated_at'=>now()]);
            if (Schema::hasTable('personal_access_tokens')) DB::table('personal_access_tokens')->where('tokenable_type',User::class)->whereIn('tokenable_id',$userIds)->delete();
        }

        $contract = $letter->contract_id ? HrContract::query()->find($letter->contract_id) : null;
        if (!$contract) {
            $outletId = $letter->outlet_id ?: ($squad?->assignment ? DB::table('outlets')->where('name',$squad->assignment)->value('id') : null);
            $contract = HrContract::query()->create([
                'squad_id'=>$squad?->id,'employee_id'=>$employee->id,'assignment_id'=>null,'contract_no'=>null,
                'contract_type'=>$squad?->contract_type ?: 'UNMAPPED','status'=>'active','tmt_date'=>$squad?->contract_start_date ?: $effective,
                'first_sk_date'=>$squad?->contract_start_date ?: $effective,'start_date'=>$squad?->contract_start_date ?: $effective,
                'end_date'=>null,'outlet_id'=>$outletId,'assignment_label'=>$squad?->assignment,'division_name'=>$squad?->division_name,
                'position_name'=>$squad?->position_name,'notes'=>'Recovery contract dibuat otomatis saat SP-3 agar draft SK pemutusan dapat melalui approval Contract.',
                'source'=>'punishment-sp3-recovery','created_by_user_id'=>$actor?->id,'updated_by_user_id'=>$actor?->id,
            ]);
            $letter->contract_id=$contract->id; $letter->save();
        }
        if (!$letter->termination_contract_document_id) {
            $this->contractDocuments->generate((string)$contract->id,[
                'document_type'=>'termination','company_code'=>$letter->company_code,'issue_date'=>now()->toDateString(),'effective_date'=>$effective,
                'reason'=>'Pemutusan kerja berdasarkan SP-3 '.$letter->letter_no.'. '.$letter->reason,
            ],$actor);
            $doc = HrContractDocument::query()->where('contract_id',$contract->id)->where('document_type','termination')->where('status','draft')->latest('created_at')->first();
            if (!$doc) throw ValidationException::withMessages(['contract'=>['Draft SK pemutusan gagal dibuat. Approval SP-3 dibatalkan.']]);
            $payload = is_array($doc->payload_snapshot) ? $doc->payload_snapshot : [];
            $payload['source_warning_letter_id']=(string)$letter->id; $payload['source_warning_letter_no']=$letter->letter_no;
            $doc->payload_snapshot=$payload; $doc->save();
            $letter->termination_contract_document_id=$doc->id; $letter->save();
        }
    }

    private function currentSpLevel(string $employeeId, mixed $asOf = null): int
    {
        return $this->spValidity->currentLevelForEmployee($employeeId, $asOf);
    }

    private function primaryOutletId(string $employeeId): ?string
    {
        $id = Assignment::query()->where('employee_id',$employeeId)->where('is_primary',true)
            ->where(function($q){$q->whereNull('status')->orWhereNotIn('status',['inactive','cancelled']);})->orderByDesc('start_date')->value('outlet_id');
        return $id ? (string)$id : null;
    }

    private function squadForNisj(mixed $nisj): ?object
    {
        $value=trim((string)($nisj ?? '')); if ($value==='' || !Schema::hasTable('HR_squads')) return null;
        return DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower($value)])->first();
    }

    private function assertViolationScope(Request $request, HrViolation $row): void
    {
        if (!$row->outlet_id || !$this->scope->isOutletAllowed($request,(string)$row->outlet_id)) abort(403,'Pelanggaran berada di luar scope outlet user.');
    }
    private function assertRecommendationScope(Request $request, HrViolationRecommendation $row): void
    {
        if (!$row->outlet_id || !$this->scope->isOutletAllowed($request,(string)$row->outlet_id)) abort(403,'Rekomendasi berada di luar scope outlet user.');
    }
    private function assertWarningLetterScope(Request $request, HrWarningLetter $row): void
    {
        if (!$row->outlet_id || !$this->scope->isOutletAllowed($request,(string)$row->outlet_id)) abort(403,'SP berada di luar scope outlet user.');
    }

    private function violationPayload(HrViolation $row): array
    {
        $employee=Employee::query()->find($row->employee_id); $outlet=$row->outlet_id?DB::table('outlets')->where('id',$row->outlet_id)->first():null;
        return ['id'=>(string)$row->id,'employee_id'=>(string)$row->employee_id,'full_name'=>$employee?->full_name,'nisj'=>$employee?->nisj,'outlet_id'=>$row->outlet_id,'outlet_name'=>$outlet?->name,
            'violation_type'=>$row->violation_type,'violation_date'=>$row->violation_date?->format('Y-m-d'),'title'=>$row->title,'description'=>$row->description,'severity'=>$row->severity,
            'late_minutes'=>(int)$row->late_minutes,'source_type'=>$row->source_type,'source_ref'=>$row->source_ref,'status'=>$row->status,'recommendation_id'=>$row->recommendation_id,
            'metadata'=>$row->metadata,'decision_note'=>$row->decision_note,'created_at'=>$row->created_at?->toIso8601String()];
    }
    private function recommendationPayload(HrViolationRecommendation $row): array
    {
        $employee=Employee::query()->find($row->employee_id); return ['id'=>(string)$row->id,'employee_id'=>(string)$row->employee_id,'full_name'=>$employee?->full_name,'nisj'=>$employee?->nisj,
            'outlet_id'=>$row->outlet_id,'recommended_sp_level'=>(int)$row->recommended_sp_level,'window_from'=>$row->window_from?->format('Y-m-d'),'window_to'=>$row->window_to?->format('Y-m-d'),
            'qualifying_count'=>(int)$row->qualifying_count,'reason'=>$row->reason,'status'=>$row->status,'warning_letter_id'=>$row->warning_letter_id,'action_note'=>$row->action_note];
    }
    private function warningLetterPayload(HrWarningLetter $row): array
    {
        $employee=Employee::query()->find($row->employee_id);
        $outlet=$row->outlet_id?DB::table('outlets')->where('id',$row->outlet_id)->first():null;
        $startValue = $row->effective_date?->toDateString() ?: ($row->issue_date?->toDateString() ?: null);
        $validity = $startValue ? $this->spValidity->validityWindow($startValue, (int) $row->sp_level) : null;
        $isValidityActive = $row->status === 'approved' && $this->spValidity->isActiveLetter($row);

        return ['id'=>(string)$row->id,'employee_id'=>(string)$row->employee_id,'full_name'=>$employee?->full_name,'nisj'=>$employee?->nisj,'outlet_id'=>$row->outlet_id,'outlet_name'=>$outlet?->name,
            'sp_level'=>(int)$row->sp_level,'letter_no'=>$row->letter_no,'issue_date'=>$row->issue_date?->format('Y-m-d'),'effective_date'=>$row->effective_date?->format('Y-m-d'),'title'=>$row->title,
            'reason'=>$row->reason,'body_snapshot'=>$row->body_snapshot,'employee_snapshot'=>$row->employee_snapshot,'status'=>$row->status,'recommendation_id'=>$row->recommendation_id,
            'template_key'=>$row->template_key,'company_code'=>$row->company_code,'letter_code'=>$row->letter_code,'branding_snapshot'=>$row->branding_snapshot,
            'contract_id'=>$row->contract_id,'termination_contract_document_id'=>$row->termination_contract_document_id,'decision_note'=>$row->decision_note,'created_by_user_id'=>$row->created_by_user_id,'created_by_name'=>$row->creator?->name ?? data_get($row->employee_snapshot,'created_by_name'), 'approved_by_user_id'=>$row->approved_by_user_id,
            'submitted_at'=>$row->submitted_at?->toIso8601String(),'approved_at'=>$row->approved_at?->toIso8601String(),
            'validity_days'=>$validity['days'] ?? $this->spValidity->daysForLevel((int)$row->sp_level),
            'validity_start'=>$validity ? $validity['start']->toDateString() : null,
            'validity_end'=>$validity ? $validity['end']->toDateString() : null,
            'validity_status'=>$row->status === 'approved' ? ($isValidityActive ? 'ACTIVE' : 'EXPIRED') : strtoupper((string)$row->status),
        ];
    }

    private function page($query, array $filters, array $allowedSort, string $defaultSort, string $defaultDir): array
    {
        $sort=in_array($filters['sort_by'] ?? '',$allowedSort,true)?$filters['sort_by']:$defaultSort;
        $dir=strtolower((string)($filters['sort_direction'] ?? $defaultDir))==='asc'?'asc':'desc';
        $map=['full_name'=>'e.full_name','created_at'=>str_contains((string)$query->toSql(),'HR_violation_recommendations')?'r.created_at':'created_at'];
        $column=$map[$sort] ?? $sort;
        $per=max(10,min(200,(int)($filters['per_page'] ?? 25)));
        $p=$query->orderBy($column,$dir)->paginate($per);
        return ['items'=>collect($p->items())->map(fn($r)=>method_exists($r,'toArray')?$r->toArray():(array)$r)->values()->all(),'pagination'=>['page'=>$p->currentPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'last_page'=>$p->lastPage(),'from'=>$p->firstItem(),'to'=>$p->lastItem()]];
    }
    private function emptyPage(): array { return ['items'=>[],'pagination'=>['page'=>1,'per_page'=>25,'total'=>0,'last_page'=>1,'from'=>null,'to'=>null]]; }
}
