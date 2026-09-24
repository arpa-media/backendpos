<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrDevelopmentService
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrDevelopmentScoringService $scoring,
        private readonly HrDevelopmentCertificateTemplateService $templates,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    public function references(Request $request): array
    {
        // Iteration 24 intentionally no longer hydrates the full employee directory here.
        // Participant lookup is served by employeeOptions() with server-side debounce.
        return [
            'outlets'=>$this->scope->options($request),
            'employees'=>[],
            'field_types'=>['text','textarea','number','date','boolean','select'],
            'scoring_modes'=>['bands','linear'],
            'merge_fields'=>['{{name}}','{{nisj}}','{{development}}','{{batch}}','{{result}}','{{score}}','{{completed_date}}','{{certificate_no}}'],
        ];
    }

    public function employeeOptions(Request $request,string $search='',?string $batchId=null,int $limit=40): array
    {
        $allowed=$this->scope->allowedOutletIds($request); if($allowed===[])return[];
        $limit=max(10,min(100,$limit));$search=trim($search);
        $q=DB::table('assignments as a')->join('employees as e','e.id','=','a.employee_id')->leftJoin('users as u','u.id','=','e.user_id')->join('outlets as o','o.id','=','a.outlet_id')
            ->whereIn('a.outlet_id',$allowed)->where('a.is_primary',true)
            ->where(function($x){$x->whereNull('a.status')->orWhereRaw("LOWER(a.status) NOT IN ('inactive','cancelled')");})
            ->where(function($x){$x->whereNull('e.employment_status')->orWhereRaw("LOWER(e.employment_status)='active'");})
            ->where(function($x){$x->whereNull('u.id')->orWhere('u.is_active',true);});
        if($batchId){$q->whereNotExists(function($sub)use($batchId){$sub->selectRaw('1')->from('HR_development_participants as ep')->whereColumn('ep.employee_id','e.id')->where('ep.batch_id',$batchId);});}
        if($search!==''){$like='%'.$search.'%';$q->where(function($x)use($like){$x->where('e.full_name','like',$like)->orWhere('e.nisj','like',$like)->orWhere('a.role_title','like',$like)->orWhere('o.name','like',$like);});}
        return$q->orderBy('e.full_name')->limit($limit)->get(['e.id','e.nisj','e.full_name','a.outlet_id','o.name as outlet_name','a.role_title'])
            ->unique('id')->map(fn($r)=>['id'=>(string)$r->id,'nisj'=>(string)($r->nisj??''),'full_name'=>(string)($r->full_name??'-'),'outlet_id'=>(string)$r->outlet_id,'outlet_name'=>(string)$r->outlet_name,'position'=>(string)($r->role_title??'')])->values()->all();
    }

    public function index(Request $request, array $filters): array
    {
        $allowed=$this->scope->allowedOutletIds($request); if($allowed===[]) return $this->emptyPage();
        $query=DB::table('HR_developments as d')->whereNull('d.deleted_at')
            ->when($filters['status']??null,fn($q,$v)=>$q->where('d.status',$v))
            ->when($filters['search']??null,function($q,$v){$n='%'.trim($v).'%';$q->where(fn($x)=>$x->where('d.name','like',$n)->orWhere('d.code','like',$n)->orWhere('d.description','like',$n));})
            ->select(['d.*'])
            ->selectSub(fn($q)=>$q->from('HR_development_batches as b')->whereColumn('b.development_id','d.id')->whereNull('b.deleted_at')->selectRaw('COUNT(*)'),'batches_count')
            ->selectSub(fn($q)=>$q->from('HR_development_participants as p')->whereColumn('p.development_id','d.id')->whereIn('p.outlet_id',$allowed)->selectRaw('COUNT(*)'),'participants_count')
            ->selectSub(fn($q)=>$q->from('HR_development_participants as p')->whereColumn('p.development_id','d.id')->whereIn('p.outlet_id',$allowed)->whereNotNull('p.result_published_at')->selectRaw('COUNT(*)'),'published_results_count');
        $sort=in_array($filters['sort_by']??'created_at',['created_at','name','code','status'],true)?($filters['sort_by']??'created_at'):'created_at';
        $dir=strtolower($filters['sort_direction']??'desc')==='asc'?'asc':'desc';
        $p=$query->orderBy("d.$sort",$dir)->paginate(min(100,max(10,(int)($filters['per_page']??25))));
        return ['items'=>collect($p->items())->map(fn($r)=>$this->programPayload($r))->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function show(Request $request,string $id): array
    {
        $d=$this->findProgram($id); $allowed=$this->scope->allowedOutletIds($request);
        $batches=DB::table('HR_development_batches')->where('development_id',$id)->whereNull('deleted_at')->orderBy('start_date')->orderBy('name')->get()->map(fn($r)=>$this->batchPayload($r))->all();
        $fields=DB::table('HR_development_fields')->where('development_id',$id)->orderBy('kind')->orderBy('sort_order')->get()->map(fn($r)=>$this->fieldPayload($r))->all();
        $policies=DB::table('HR_development_scoring_policies')->where('development_id',$id)->orderByDesc('version')->get()->map(fn($r)=>$this->policyPayload($r))->all();
        $counts=DB::table('HR_development_participants')->where('development_id',$id)->whereIn('outlet_id',$allowed)->selectRaw('COUNT(*) total')->selectRaw('SUM(result_published_at IS NOT NULL) published')->selectRaw("SUM(status='completed') completed")->first();
        return array_merge($this->programPayload($d),['batches'=>$batches,'fields'=>$fields,'scoring_policies'=>$policies,'active_template'=>$this->templates->active($id),'scope_counts'=>['participants'=>(int)($counts->total??0),'published'=>(int)($counts->published??0),'completed'=>(int)($counts->completed??0)]]);
    }

    public function saveProgram(?string $id,array $data,?User $actor): array
    {
        return DB::transaction(function() use($id,$data,$actor){
            $now=now(); $existing=$id?DB::table('HR_developments')->where('id',$id)->whereNull('deleted_at')->lockForUpdate()->first():null;
            if($id && !$existing) abort(404,'Development tidak ditemukan.');
            $devId=$id ?: (string)Str::ulid();
            $code=strtoupper(trim((string)$data['code']));
            $duplicate=DB::table('HR_developments')->whereRaw('UPPER(code)=?',[strtoupper($code)])->whereNull('deleted_at')->when($id,fn($q)=>$q->where('id','!=',$id))->exists();
            if($duplicate) throw ValidationException::withMessages(['code'=>['Kode development sudah digunakan.']]);
            $badgeName=trim((string)($data['badge_name']??''))?:null;$certificateTitle=trim((string)($data['certificate_title']??''))?:null;
            $syncOutputs=$existing && ((string)$existing->code!==$code||(string)$existing->name!==trim((string)$data['name'])||(bool)$existing->output_badge!==(bool)($data['output_badge']??false)||(string)($existing->badge_name??'')!==(string)($badgeName??'')||(bool)$existing->output_certificate!==(bool)($data['output_certificate']??false)||(string)($existing->certificate_title??'')!==(string)($certificateTitle??''));
            $payload=['code'=>$code,'name'=>trim($data['name']),'description'=>$data['description']??null,'objective'=>$data['objective']??null,'has_test'=>(bool)($data['has_test']??false),'output_badge'=>(bool)($data['output_badge']??false),'badge_name'=>$badgeName,'badge_description'=>$data['badge_description']??null,'output_certificate'=>(bool)($data['output_certificate']??false),'certificate_title'=>$certificateTitle,'updated_by_user_id'=>$actor?->id,'updated_at'=>$now];
            if($existing) DB::table('HR_developments')->where('id',$devId)->update($payload);
            else DB::table('HR_developments')->insert(array_merge(['id'=>$devId,'status'=>'draft','created_by_user_id'=>$actor?->id,'created_at'=>$now],$payload));
            if(array_key_exists('fields',$data)) $this->syncFields($devId,(array)$data['fields']);
            if((bool)($data['has_test']??false) && isset($data['scoring_policy'])) $this->syncScoringPolicy($devId,(array)$data['scoring_policy'],$actor?->id);
            if(!(bool)($data['has_test']??false)) DB::table('HR_developments')->where('id',$devId)->update(['active_scoring_policy_id'=>null]);
            // ITERATION 04: when badge/certificate output is enabled after participants have
            // already completed the program, publish their completion outputs immediately.
            // This keeps Dashboard Development consistent with completed_at instead of
            // requiring a second result-publish action solely to make the badge visible.
            if($syncOutputs)$this->syncCompletedOutputsForDevelopment($devId,$actor?->id);
            return $this->programPayload(DB::table('HR_developments')->where('id',$devId)->first());
        });
    }

    public function publishProgram(string $id,?User $actor): array
    {
        $d=$this->findProgram($id);
        if((bool)$d->has_test && !$this->scoring->activePolicy($id)) throw ValidationException::withMessages(['scoring_policy'=>['Development dengan test wajib memiliki scoring policy aktif.']]);
        if(!DB::table('HR_development_batches')->where('development_id',$id)->whereNull('deleted_at')->exists()) throw ValidationException::withMessages(['batches'=>['Minimal satu batch wajib dibuat sebelum publish.']]);
        return DB::transaction(function()use($d,$actor){
            DB::table('HR_developments')->where('id',$d->id)->update(['status'=>'published','published_by_user_id'=>$actor?->id,'published_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            $this->logProgramEvent((string)$d->id,'published',(string)$d->status,'published',$actor?->id,[]);
            return $this->programPayload(DB::table('HR_developments')->where('id',$d->id)->first());
        });
    }

    public function draftProgram(string $id,?User $actor): array
    {
        $d=$this->findProgram($id);
        if((string)$d->status==='draft')return$this->programPayload($d);
        return DB::transaction(function()use($d,$actor){
            DB::table('HR_developments')->where('id',$d->id)->update(['status'=>'draft','published_by_user_id'=>null,'published_at'=>null,'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            $this->logProgramEvent((string)$d->id,'unpublished',(string)$d->status,'draft',$actor?->id,['participants'=>(int)DB::table('HR_development_participants')->where('development_id',$d->id)->count()]);
            return$this->programPayload(DB::table('HR_developments')->where('id',$d->id)->first());
        });
    }

    public function deleteProgram(string $id,?User $actor): void
    {
        $d=$this->findProgram($id);
        if((string)$d->status!=='draft')throw ValidationException::withMessages(['development'=>['Development Published harus dikembalikan menjadi Draft sebelum dihapus.']]);
        DB::transaction(function()use($d,$actor){
            $participantCount=(int)DB::table('HR_development_participants')->where('development_id',$d->id)->count();
            $publishedCount=(int)DB::table('HR_development_participants')->where('development_id',$d->id)->whereNotNull('result_published_at')->count();
            DB::table('HR_development_achievements')->where('development_id',$d->id)->whereNull('revoked_at')->update(['revoked_at'=>now(),'revoked_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            DB::table('HR_developments')->where('id',$d->id)->update(['deleted_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            $this->logProgramEvent((string)$d->id,'deleted','draft','deleted',$actor?->id,['participants'=>$participantCount,'published_results'=>$publishedCount,'mode'=>'soft_delete_history_preserved']);
        });
    }

    public function saveBatch(string $developmentId,?string $batchId,array $data): array
    {
        $this->findProgram($developmentId); if($data['end_date']<$data['start_date']) throw ValidationException::withMessages(['end_date'=>['Tanggal selesai tidak boleh sebelum tanggal mulai.']]);
        $now=now(); $existing=$batchId?DB::table('HR_development_batches')->where('id',$batchId)->where('development_id',$developmentId)->whereNull('deleted_at')->first():null;
        if($batchId&&!$existing)abort(404,'Batch tidak ditemukan.');
        $dup=DB::table('HR_development_batches')->where('development_id',$developmentId)->where('batch_code',strtoupper(trim($data['batch_code'])))->whereNull('deleted_at')->when($batchId,fn($q)=>$q->where('id','!=',$batchId))->exists();
        if($dup)throw ValidationException::withMessages(['batch_code'=>['Kode batch sudah digunakan pada development ini.']]);
        $payload=['batch_code'=>strtoupper(trim($data['batch_code'])),'name'=>trim($data['name']),'start_date'=>$data['start_date'],'end_date'=>$data['end_date'],'status'=>$data['status']??'planned','capacity'=>$data['capacity']??null,'notes'=>$data['notes']??null,'updated_at'=>$now];
        $id=$batchId?: (string)Str::ulid(); if($existing)DB::table('HR_development_batches')->where('id',$id)->update($payload);else DB::table('HR_development_batches')->insert(array_merge(['id'=>$id,'development_id'=>$developmentId,'created_at'=>$now],$payload));
        return $this->batchPayload(DB::table('HR_development_batches')->where('id',$id)->first());
    }

    public function deleteBatch(string $developmentId,string $batchId): void
    {
        if(DB::table('HR_development_participants')->where('batch_id',$batchId)->exists())throw ValidationException::withMessages(['batch'=>['Batch yang sudah memiliki peserta tidak dapat dihapus.']]);
        DB::table('HR_development_batches')->where('id',$batchId)->where('development_id',$developmentId)->update(['deleted_at'=>now(),'updated_at'=>now()]);
    }

    public function participants(Request $request,string $developmentId,array $filters): array
    {
        $this->findProgram($developmentId); $allowed=$this->scope->allowedOutletIds($request); if($allowed===[])return$this->emptyPage();
        $q=DB::table('HR_development_participants as p')->join('employees as e','e.id','=','p.employee_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->leftJoin('outlets as o','o.id','=','p.outlet_id')
            ->where('p.development_id',$developmentId)->whereIn('p.outlet_id',$allowed)
            ->when($filters['batch_id']??null,fn($x,$v)=>$x->where('p.batch_id',$v))->when($filters['status']??null,fn($x,$v)=>$x->where('p.status',$v))
            ->when($filters['search']??null,function($x,$v){$n='%'.trim($v).'%';$x->where(fn($z)=>$z->where('e.full_name','like',$n)->orWhere('e.nisj','like',$n));})
            ->select(['p.*','e.full_name','e.nisj','b.name as batch_name','b.batch_code','o.name as outlet_name'])->orderBy('e.full_name');
        $p=$q->paginate(min(200,max(10,(int)($filters['per_page']??50))));
        $ids=collect($p->items())->pluck('id')->all(); $values=$this->valuesForParticipants($ids);
        return ['items'=>collect($p->items())->map(fn($r)=>$this->participantPayload($r,$values[(string)$r->id]??[]))->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function addParticipants(Request $request,string $developmentId,array $data,?User $actor): array
    {
        $this->findProgram($developmentId); $batch=DB::table('HR_development_batches')->where('id',$data['batch_id'])->where('development_id',$developmentId)->whereNull('deleted_at')->first(); if(!$batch)abort(404,'Batch tidak ditemukan.');
        $employeeIds=collect($data['employee_ids'])->filter()->unique()->values(); if($employeeIds->isEmpty())return['created'=>0,'skipped'=>0];
        $employees=DB::table('assignments as a')->join('employees as e','e.id','=','a.employee_id')->where('a.is_primary',true)->whereIn('e.id',$employeeIds)->get(['e.id','a.outlet_id'])->unique('id');
        $created=0;$skipped=0;
        DB::transaction(function()use($employees,$request,$developmentId,$batch,$actor,&$created,&$skipped){
            foreach($employees as $e){if(!$e->outlet_id||!$this->scope->isOutletAllowed($request,(string)$e->outlet_id)){ $skipped++;continue; }
                $exists=DB::table('HR_development_participants')->where('batch_id',$batch->id)->where('employee_id',$e->id)->exists();if($exists){$skipped++;continue;}
                if($batch->capacity!==null){$count=DB::table('HR_development_participants')->where('batch_id',$batch->id)->count();if($count>=(int)$batch->capacity)throw ValidationException::withMessages(['employee_ids'=>['Kapasitas batch sudah penuh.']]);}
                DB::table('HR_development_participants')->insert(['id'=>(string)Str::ulid(),'development_id'=>$developmentId,'batch_id'=>$batch->id,'employee_id'=>$e->id,'outlet_id'=>$e->outlet_id,'assigned_via'=>'manual','status'=>'assigned','joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);$created++;
            }
        }); return ['created'=>$created,'skipped'=>$skipped];
    }

    public function updateParticipant(Request $request,string $developmentId,string $participantId,array $data,?User $actor): array
    {
        $p=$this->participantInScope($request,$developmentId,$participantId);
        if($p->result_published_at) throw ValidationException::withMessages(['participant'=>['Hasil sudah dipublish dan tidak dapat diubah.']]);
        $updates=[];
        foreach(['status','notes'] as $key)if(array_key_exists($key,$data))$updates[$key]=$data[$key];
        if(($data['status']??null)==='completed'&&!$p->completed_at)$updates['completed_at']=now(); $updates['updated_at']=now();
        DB::transaction(function()use($participantId,$developmentId,$data,$updates,$actor){DB::table('HR_development_participants')->where('id',$participantId)->update($updates);if(array_key_exists('values',$data))$this->syncParticipantValues($developmentId,$participantId,(array)$data['values'],$actor?->id);});
        $this->ensureCompletedOutputs($developmentId,$participantId,$actor?->id);
        return $this->participantDetail($request,$developmentId,$participantId);
    }

    public function scoreParticipant(Request $request,string $developmentId,string $participantId,array $data,?User $actor): array
    {
        $p=$this->participantInScope($request,$developmentId,$participantId); $d=$this->findProgram($developmentId);
        if($p->result_published_at) throw ValidationException::withMessages(['participant'=>['Hasil sudah dipublish dan tidak dapat dihitung ulang.']]);
        if((bool)$d->has_test && (!array_key_exists('score',$data) || $data['score']===null || $data['score']==='')) throw ValidationException::withMessages(['score'=>['Score wajib diisi untuk development dengan test.']]);
        if((bool)$d->has_test){$calc=$this->scoring->calculate($d,$this->scoring->activePolicy($developmentId),(float)$data['score']);$update=['score_raw'=>$calc['raw_score'],'score_final'=>$calc['final_score'],'result_label'=>$calc['label'],'result_passed'=>$calc['passed'],'scoring_policy_id'=>$calc['policy_id'],'result_snapshot'=>json_encode($calc['snapshot'],JSON_UNESCAPED_UNICODE),'status'=>'completed','completed_at'=>$p->completed_at?:now(),'updated_at'=>now()];}
        else{$label=trim((string)($data['result_label']??''));if($label==='')throw ValidationException::withMessages(['result_label'=>['Result wajib diisi untuk development tanpa test.']]);$update=['score_raw'=>null,'score_final'=>null,'result_label'=>$label,'result_passed'=>(bool)($data['passed']??true),'scoring_policy_id'=>null,'result_snapshot'=>json_encode(['mode'=>'manual','recorded_at'=>now()->toIso8601String()],JSON_UNESCAPED_UNICODE),'status'=>'completed','completed_at'=>$p->completed_at?:now(),'updated_at'=>now()];}
        DB::table('HR_development_participants')->where('id',$participantId)->update($update);
        $this->ensureCompletedOutputs($developmentId,$participantId,$actor?->id);
        return $this->participantDetail($request,$developmentId,$participantId);
    }

    public function publishResult(Request $request,string $developmentId,string $participantId,?User $actor): array
    {
        $this->participantInScope($request,$developmentId,$participantId); $d=$this->findProgram($developmentId);
        return DB::transaction(function()use($developmentId,$participantId,$actor,$d,$request){
            $p=DB::table('HR_development_participants')->where('id',$participantId)->lockForUpdate()->firstOrFail();
            if(!$p->result_label)throw ValidationException::withMessages(['result'=>['Hasil participant belum dihitung/diisi.']]);
            if((string)$p->status!=='completed'||!$p->completed_at)throw ValidationException::withMessages(['participant'=>['Participant harus berstatus completed dan memiliki completed_at sebelum badge/e-certificate diterbitkan.']]);
            DB::table('HR_development_participants')->where('id',$participantId)->update(['result_published_at'=>$p->result_published_at?:now(),'result_published_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            // Re-issue/update the completion outputs so the snapshot contains the final
            // published score/result while preserving the original achievement issued_at.
            $this->ensureCompletedOutputs($developmentId,$participantId,$actor?->id);
            return $this->participantDetail($request,$developmentId,$participantId);
        });
    }

    public function participantDetail(Request $request,string $developmentId,string $participantId): array
    {
        $this->participantInScope($request,$developmentId,$participantId);$r=DB::table('HR_development_participants as p')->join('employees as e','e.id','=','p.employee_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->leftJoin('outlets as o','o.id','=','p.outlet_id')->where('p.id',$participantId)->select(['p.*','e.full_name','e.nisj','b.name as batch_name','b.batch_code','o.name as outlet_name'])->first();
        $values=$this->valuesForParticipants([$participantId]);$ach=DB::table('HR_development_achievements')->where('participant_id',$participantId)->whereNull('revoked_at')->orderBy('type')->get()->map(fn($x)=>$this->achievementPayload($x))->all();
        return array_merge($this->participantPayload($r,$values[$participantId]??[]),['achievements'=>$ach]);
    }

    public function removeParticipant(Request $request,string $developmentId,string $participantId): void
    {
        $p=$this->participantInScope($request,$developmentId,$participantId);if($p->result_published_at)throw ValidationException::withMessages(['participant'=>['Participant dengan hasil published tidak dapat dihapus.']]);DB::table('HR_development_participants')->where('id',$participantId)->delete();
    }

    public function userTracking(Request $request,array $filters): array
    {
        $allowed=$this->scope->allowedOutletIds($request);if($allowed===[])return$this->emptyPage();
        $q=DB::table('employees as e')->join('assignments as a',fn($j)=>$j->on('a.employee_id','=','e.id')->where('a.is_primary',true))->join('outlets as o','o.id','=','a.outlet_id')->whereIn('a.outlet_id',$allowed)
            ->when($filters['outlet_id']??null,fn($x,$v)=>$x->where('a.outlet_id',$v))->when($filters['search']??null,function($x,$v){$n='%'.trim($v).'%';$x->where(fn($z)=>$z->where('e.full_name','like',$n)->orWhere('e.nisj','like',$n));})
            ->select(['e.id','e.nisj','e.full_name','o.name as outlet_name'])->distinct()->orderBy('e.full_name');
        $p=$q->paginate(min(100,max(10,(int)($filters['per_page']??30))));$ids=collect($p->items())->pluck('id')->all();
        $history=DB::table('HR_development_participants as p')->join('HR_developments as d','d.id','=','p.development_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->whereIn('p.employee_id',$ids)->orderByDesc('b.start_date')->get(['p.employee_id','p.id as participant_id','d.code','d.name','b.name as batch_name','b.start_date','b.end_date','p.status','p.score_final','p.result_label','p.result_passed','p.result_published_at'])->groupBy('employee_id');
        return ['items'=>collect($p->items())->map(fn($e)=>['employee_id'=>(string)$e->id,'nisj'=>$e->nisj,'full_name'=>$e->full_name,'outlet_name'=>$e->outlet_name,'developments'=>collect($history[(string)$e->id]??[])->map(fn($r)=>(array)$r)->values()->all()])->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function self(User $user): array
    {
        $employee=DB::table('employees')->where('user_id',$user->id)->first();if(!$employee)return['items'=>[],'badges'=>[]];
        $items=DB::table('HR_development_participants as p')->join('HR_developments as d','d.id','=','p.development_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->where('p.employee_id',$employee->id)->whereNull('d.deleted_at')
            ->select(['p.id','p.status','p.score_final','p.result_label','p.result_passed','p.result_published_at','p.completed_at','d.id as development_id','d.code','d.name','d.description','d.output_badge','d.badge_name','d.badge_logo_path','d.output_certificate','d.certificate_title','b.name as batch_name','b.start_date','b.end_date'])
            ->selectSub(fn($q)=>$q->from('HR_development_achievements as a')->whereColumn('a.participant_id','p.id')->where('a.type','badge')->whereNull('a.revoked_at')->selectRaw('COUNT(*)'),'badge_count')
            ->selectSub(fn($q)=>$q->from('HR_development_achievements as a')->whereColumn('a.participant_id','p.id')->where('a.type','certificate')->whereNull('a.revoked_at')->selectRaw('COUNT(*)'),'certificate_count')
            ->orderByDesc('b.start_date')->get()->map(function($r){$a=(array)$r;$a['has_badge']=(int)($r->badge_count??0)>0;$a['has_certificate']=(int)($r->certificate_count??0)>0;return$a;})->all();
        $badges=DB::table('HR_development_achievements as a')->join('HR_developments as d','d.id','=','a.development_id')->where('a.employee_id',$employee->id)->where('a.type','badge')->whereNull('a.revoked_at')->whereNull('d.deleted_at')->orderByDesc('a.issued_at')->get(['a.id','a.title','a.achievement_code','a.snapshot','a.issued_at','d.name as development_name','d.badge_logo_path'])->map(fn($r)=>$this->achievementPayload($r))->all();
        return ['items'=>$items,'badges'=>$badges];
    }

    public function certificatePayload(User $user,string $participantId,bool $selfOnly=true): array
    {
        $q=DB::table('HR_development_participants as p')->join('employees as e','e.id','=','p.employee_id')->join('HR_developments as d','d.id','=','p.development_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->where('p.id',$participantId);
        if($selfOnly)$q->where('e.user_id',$user->id);$p=$q->select(['p.*','e.full_name','e.nisj','d.name as development_name','d.certificate_title','b.name as batch_name'])->first();if(!$p)abort(404,'Certificate tidak ditemukan.');
        $a=DB::table('HR_development_achievements')->where('participant_id',$participantId)->where('type','certificate')->whereNull('revoked_at')->first();if(!$a)throw ValidationException::withMessages(['certificate'=>['Certificate belum diterbitkan.']]);
        $achievement=$this->achievementPayload($a);$snapshot=$achievement['snapshot']??[];
        return ['participant_id'=>$participantId,'full_name'=>$p->full_name,'nisj'=>$p->nisj,'development_name'=>$p->development_name,'batch_name'=>$p->batch_name,'score'=>$p->score_final??($snapshot['score']??null),'result'=>$p->result_label?:($snapshot['result']??'Completed'),'completed_at'=>$p->completed_at,'certificate'=>$achievement,'template'=>$this->templates->active((string)$p->development_id)];
    }

    public function certificateSvg(User $user,string $participantId): array
    {
        $p=$this->certificatePayload($user,$participantId,true);$s=$p['certificate']['snapshot']??[];$template=$s['template']??($p['template']??null);$background='';
        if(is_array($template)&&!empty($template['id'])&&str_starts_with((string)($template['mime_type']??''),'image/')){
            try{$row=DB::table('HR_development_certificate_templates')->where('id',(string)$template['id'])->first();if($row){$binary=$this->templates->read($row);$background='<image href="data:'.htmlspecialchars((string)$row->mime_type,ENT_QUOTES,'UTF-8').';base64,'.base64_encode($binary).'" x="0" y="0" width="1122" height="794" preserveAspectRatio="xMidYMid slice"/>';}}catch(\Throwable){$background='';}
        }
        $esc=fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_XML1,'UTF-8');$title=$esc($p['certificate']['title']??'Certificate of Completion');$name=$esc($p['full_name']??'-');$dev=$esc($p['development_name']??'-');$batch=$esc($p['batch_name']??'-');$result=$esc($p['result']??'-');$score=$p['score']!==null?' · Score '.$esc($p['score']):'';$number=$esc($s['certificate_no']??($p['certificate']['achievement_code']??''));
        $panel=$background===''?'<rect x="70" y="70" width="982" height="654" rx="8" fill="white" stroke="#0f172a" stroke-width="4"/>':'<rect x="120" y="160" width="882" height="470" rx="18" fill="white" fill-opacity="0.84"/>';
        $svg='<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="1122" height="794" viewBox="0 0 1122 794">'.$background.$panel.'<g text-anchor="middle" fill="#0f172a"><text x="561" y="230" font-family="Arial" font-size="18" font-weight="700" letter-spacing="5">TOKO KOPI JAYA · HUMAN RESOURCE</text><text x="561" y="315" font-family="Georgia" font-size="50" font-weight="700">'.$title.'</text><text x="561" y="365" font-family="Arial" font-size="18">Diberikan kepada</text><text x="561" y="430" font-family="Georgia" font-size="42" font-weight="700">'.$name.'</text><text x="561" y="485" font-family="Arial" font-size="18">atas penyelesaian '.$dev.' · Batch '.$batch.'</text><text x="561" y="535" font-family="Arial" font-size="20" font-weight="700">'.$result.$score.'</text><text x="561" y="620" font-family="Arial" font-size="13" fill="#64748b">'.$number.'</text></g></svg>';
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim((string)($p['nisj']??'certificate'))).'_'.$participantId.'.svg';return['filename'=>$safe,'content'=>$svg];
    }

    public function selfCertificateTemplate(User $user,string $participantId): array
    {
        $payload=$this->certificatePayload($user,$participantId,true);
        $template=$payload['certificate']['snapshot']['template']??($payload['template']??null);
        $templateId=is_array($template)?($template['id']??null):null;
        if(!$templateId) abort(404,'Template certificate tidak tersedia.');
        $developmentId=DB::table('HR_development_participants')->where('id',$participantId)->value('development_id');
        $row=DB::table('HR_development_certificate_templates')->where('id',(string)$templateId)->where('development_id',(string)$developmentId)->first();
        if(!$row) abort(404,'Template certificate tidak tersedia.');
        return ['row'=>$row,'binary'=>$this->templates->read($row)];
    }

    public function storeBadgeLogo(string $developmentId,UploadedFile $file,?User $actor): array
    {
        $d=$this->findProgram($developmentId);if(!(bool)$d->output_badge)throw ValidationException::withMessages(['logo'=>['Aktifkan output badge terlebih dahulu.']]);
        $path=$file->store('hr/development/badges','public');
        if($d->badge_logo_path)Storage::disk('public')->delete((string)$d->badge_logo_path);
        DB::table('HR_developments')->where('id',$developmentId)->update(['badge_logo_path'=>$path,'badge_logo_mime'=>$file->getMimeType(),'badge_logo_original_name'=>$file->getClientOriginalName(),'badge_logo_size_bytes'=>$file->getSize(),'badge_logo_updated_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
        $this->logProgramEvent($developmentId,'badge_logo_updated',(string)$d->status,(string)$d->status,$actor?->id,['mime'=>$file->getMimeType(),'size'=>(int)$file->getSize()]);
        return$this->programPayload(DB::table('HR_developments')->where('id',$developmentId)->first());
    }
    public function deleteBadgeLogo(string $developmentId,?User $actor): array
    {
        $d=$this->findProgram($developmentId);if($d->badge_logo_path)Storage::disk('public')->delete((string)$d->badge_logo_path);
        DB::table('HR_developments')->where('id',$developmentId)->update(['badge_logo_path'=>null,'badge_logo_mime'=>null,'badge_logo_original_name'=>null,'badge_logo_size_bytes'=>null,'badge_logo_updated_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
        $this->logProgramEvent($developmentId,'badge_logo_deleted',(string)$d->status,(string)$d->status,$actor?->id,[]);return$this->programPayload(DB::table('HR_developments')->where('id',$developmentId)->first());
    }

    public function uploadTemplate(string $developmentId,UploadedFile $file,?User $actor,?string $name): array { $d=$this->findProgram($developmentId);if(!(bool)$d->output_certificate)throw ValidationException::withMessages(['template'=>['Aktifkan output e-certificate terlebih dahulu.']]);return$this->templates->store($developmentId,$file,$actor?->id,$name); }
    public function templateRow(string $developmentId,string $templateId): object { $this->findProgram($developmentId);$r=DB::table('HR_development_certificate_templates')->where('id',$templateId)->where('development_id',$developmentId)->first();if(!$r)abort(404,'Template tidak ditemukan.');return$r; }
    public function templateBinary(object $row): string { return$this->templates->read($row); }

    public function export(Request $request,string $type,?string $developmentId=null)
    {
        $type=strtolower($type);$allowed=$this->scope->allowedOutletIds($request);
        if($type==='program'){
            $devs=DB::table('HR_developments')->whereNull('deleted_at')->when($developmentId,fn($q,$v)=>$q->where('id',$v))->orderBy('code')->get();$ids=$devs->pluck('id')->all();
            $program=[['CODE','NAME','DESCRIPTION','OBJECTIVE','STATUS','HAS_TEST','OUTPUT_BADGE','BADGE_NAME','OUTPUT_CERTIFICATE','CERTIFICATE_TITLE']];foreach($devs as$d)$program[]=[$d->code,$d->name,$d->description,$d->objective,$d->status,(int)$d->has_test,(int)$d->output_badge,$d->badge_name,(int)$d->output_certificate,$d->certificate_title];
            $batch=[['DEVELOPMENT_CODE','BATCH_CODE','NAME','START_DATE','END_DATE','STATUS','CAPACITY','NOTES']];foreach(DB::table('HR_development_batches as b')->join('HR_developments as d','d.id','=','b.development_id')->whereIn('b.development_id',$ids)->whereNull('b.deleted_at')->orderBy('d.code')->orderBy('b.start_date')->get(['d.code as development_code','b.*']) as$b)$batch[]=[$b->development_code,$b->batch_code,$b->name,$b->start_date,$b->end_date,$b->status,$b->capacity,$b->notes];
            $fields=[['DEVELOPMENT_CODE','KIND','CODE','LABEL','FIELD_TYPE','REQUIRED','SORT_ORDER','OPTIONS_JSON']];foreach(DB::table('HR_development_fields as f')->join('HR_developments as d','d.id','=','f.development_id')->whereIn('f.development_id',$ids)->where('f.is_active',true)->orderBy('d.code')->orderBy('f.sort_order')->get(['d.code as development_code','f.*'])as$f)$fields[]=[$f->development_code,$f->kind,$f->code,$f->label,$f->field_type,(int)$f->is_required,$f->sort_order,$f->options];
            $score=[['DEVELOPMENT_CODE','VERSION','ACTIVE','NAME','MODE','CONFIG_JSON']];foreach(DB::table('HR_development_scoring_policies as s')->join('HR_developments as d','d.id','=','s.development_id')->whereIn('s.development_id',$ids)->orderBy('d.code')->orderBy('s.version')->get(['d.code as development_code','s.*'])as$s)$score[]=[$s->development_code,$s->version,(int)$s->is_active,$s->name,$s->mode,$s->config];
            return$this->xlsx->downloadWorkbook('HR_DEVELOPMENT_PROGRAM_'.now()->format('Ymd_His').'.xlsx',[['name'=>'PROGRAM','rows'=>$program],['name'=>'BATCH','rows'=>$batch],['name'=>'FIELD','rows'=>$fields],['name'=>'SCORING','rows'=>$score]]);
        }
        $q=DB::table('HR_development_participants as p')->join('HR_developments as d','d.id','=','p.development_id')->join('HR_development_batches as b','b.id','=','p.batch_id')->join('employees as e','e.id','=','p.employee_id')->leftJoin('outlets as o','o.id','=','p.outlet_id')->whereIn('p.outlet_id',$allowed)->when($developmentId,fn($x,$v)=>$x->where('p.development_id',$v))->orderBy('d.code')->orderBy('e.full_name')->get(['p.*','d.code as development_code','d.has_test','b.batch_code','e.nisj','e.full_name','o.name as outlet_name']);
        if($type==='score'){$rows=[['DEVELOPMENT_CODE','BATCH_CODE','NISJ','FULL_NAME','SCORE','RESULT_LABEL','PASSED','PUBLISHED_AT']];foreach($q as$r)$rows[]=[$r->development_code,$r->batch_code,$r->nisj,$r->full_name,$r->score_raw,$r->result_label,$r->result_passed===null?'':(int)$r->result_passed,$r->result_published_at];return$this->xlsx->download('HR_DEVELOPMENT_SCORE_'.now()->format('Ymd_His').'.xlsx','SCORE',$rows);}
        if($type==='result'){$rows=[['DEVELOPMENT_CODE','BATCH_CODE','NISJ','FULL_NAME','RESULT_LABEL','PASSED','FINAL_SCORE','PUBLISHED_AT']];foreach($q as$r)$rows[]=[$r->development_code,$r->batch_code,$r->nisj,$r->full_name,$r->result_label,$r->result_passed===null?'':(int)$r->result_passed,$r->score_final,$r->result_published_at];return$this->xlsx->download('HR_DEVELOPMENT_RESULT_'.now()->format('Ymd_His').'.xlsx','RESULT',$rows);}
        $rows=[['DEVELOPMENT_CODE','BATCH_CODE','NISJ','FULL_NAME','OUTLET','STATUS','NOTES']];foreach($q as$r)$rows[]=[$r->development_code,$r->batch_code,$r->nisj,$r->full_name,$r->outlet_name,$r->status,$r->notes];return$this->xlsx->download('HR_DEVELOPMENT_PARTICIPANT_'.now()->format('Ymd_His').'.xlsx','PARTICIPANT',$rows);
    }

    public function import(Request $request,string $type,UploadedFile $file,?User $actor): array
    {
        $sheets=$this->xlsx->readWorksheets($file);$map=[];foreach($sheets as$s)$map[strtoupper(trim($s['name']))]=$s['rows'];$type=strtolower($type);
        if($type==='participant')return$this->importParticipants($request,$map['PARTICIPANT']??($sheets[0]['rows']??[]),$actor);
        if($type==='score')return$this->importScores($request,$map['SCORE']??($sheets[0]['rows']??[]),$actor);
        if($type==='result')return$this->importManualResults($request,$map['RESULT']??($sheets[0]['rows']??[]),$actor);
        if($type==='program')return$this->importPrograms($map,$actor);
        throw ValidationException::withMessages(['type'=>['Type import harus program, participant, score, atau result.']]);
    }

    private function importParticipants(Request $request,array $rows,?User $actor): array
    {
        [$headers,$body]=$this->tableRows($rows);$errors=[];$prepared=[];
        foreach($body as$i=>$row){$r=$this->assoc($headers,$row);$dev=DB::table('HR_developments')->where('code',strtoupper(trim($r['DEVELOPMENT_CODE']??'')))->whereNull('deleted_at')->first();$batch=$dev?DB::table('HR_development_batches')->where('development_id',$dev->id)->where('batch_code',strtoupper(trim($r['BATCH_CODE']??'')))->whereNull('deleted_at')->first():null;$emp=DB::table('employees')->whereRaw('LOWER(TRIM(nisj))=?',[strtolower(trim($r['NISJ']??''))])->first();$outlet=$emp?DB::table('assignments')->where('employee_id',$emp->id)->where('is_primary',true)->orderByDesc('start_date')->value('outlet_id'):null;
            if(!$dev||!$batch||!$emp||!$outlet||!$this->scope->isOutletAllowed($request,(string)$outlet)){$errors[]='Baris '.($i+2).': development/batch/NISJ tidak valid atau di luar scope.';continue;}$prepared[]=[$dev,$batch,$emp,(string)$outlet,$r];}
        if($errors)throw ValidationException::withMessages(['file'=>$errors]);$created=0;$skipped=0;DB::transaction(function()use($prepared,&$created,&$skipped){foreach($prepared as[$d,$b,$e,$out,$r]){$exists=DB::table('HR_development_participants')->where('batch_id',$b->id)->where('employee_id',$e->id)->exists();if($exists){$skipped++;continue;}DB::table('HR_development_participants')->insert(['id'=>(string)Str::ulid(),'development_id'=>$d->id,'batch_id'=>$b->id,'employee_id'=>$e->id,'outlet_id'=>$out,'assigned_via'=>'import','status'=>$r['STATUS']??'assigned','notes'=>$r['NOTES']??null,'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);$created++;}});return compact('created','skipped');
    }

    private function importScores(Request $request,array $rows,?User $actor): array
    {
        [$headers,$body]=$this->tableRows($rows);$prepared=[];$errors=[];foreach($body as$i=>$row){$r=$this->assoc($headers,$row);$p=$this->resolveImportParticipant($request,$r);$d=$p?$this->findProgram((string)$p->development_id):null;if(!$p||!$d||!(bool)$d->has_test||$p->result_published_at||!is_numeric($r['SCORE']??null)){$errors[]='Baris '.($i+2).': participant/SCORE tidak valid, development tanpa test, atau hasil sudah published.';continue;}$prepared[]=[$p,(float)$r['SCORE']];}if($errors)throw ValidationException::withMessages(['file'=>$errors]);
        DB::transaction(function()use($prepared,$actor){foreach($prepared as[$p,$score]){$d=$this->findProgram((string)$p->development_id);$calc=$this->scoring->calculate($d,$this->scoring->activePolicy((string)$d->id),$score);DB::table('HR_development_participants')->where('id',$p->id)->update(['score_raw'=>$calc['raw_score'],'score_final'=>$calc['final_score'],'result_label'=>$calc['label'],'result_passed'=>$calc['passed'],'scoring_policy_id'=>$calc['policy_id'],'result_snapshot'=>json_encode($calc['snapshot'],JSON_UNESCAPED_UNICODE),'status'=>'completed','completed_at'=>$p->completed_at?:now(),'updated_at'=>now()]);}});return['updated'=>count($prepared)];
    }

    private function importManualResults(Request $request,array $rows,?User $actor): array
    {
        [$headers,$body]=$this->tableRows($rows);$prepared=[];$errors=[];foreach($body as$i=>$row){$r=$this->assoc($headers,$row);$p=$this->resolveImportParticipant($request,$r);$d=$p?$this->findProgram((string)$p->development_id):null;if(!$p||!$d||(bool)$d->has_test||$p->result_published_at||trim((string)($r['RESULT_LABEL']??''))===''){$errors[]='Baris '.($i+2).': participant tidak valid, development memakai test, hasil sudah published, atau RESULT_LABEL kosong.';continue;}$prepared[]=[$p,$r];}if($errors)throw ValidationException::withMessages(['file'=>$errors]);DB::transaction(function()use($prepared){foreach($prepared as[$p,$r])DB::table('HR_development_participants')->where('id',$p->id)->update(['result_label'=>trim($r['RESULT_LABEL']),'result_passed'=>$this->truthy($r['PASSED']??'1'),'status'=>'completed','completed_at'=>$p->completed_at?:now(),'result_snapshot'=>json_encode(['mode'=>'manual_import','recorded_at'=>now()->toIso8601String()]),'updated_at'=>now()]);});return['updated'=>count($prepared)];
    }

    private function importPrograms(array $map,?User $actor): array
    {
        [$programHeaders,$programBody]=$this->tableRows($map['PROGRAM']??[]);
        $errors=[];$programs=[];
        foreach($programBody as $i=>$row){
            $r=$this->assoc($programHeaders,$row);
            $code=strtoupper(trim((string)($r['CODE']??'')));$name=trim((string)($r['NAME']??''));
            if($code===''||$name===''){$errors[]='PROGRAM baris '.($i+2).': CODE/NAME wajib.';continue;}
            $programs[]=$r;
        }

        $batchRows=[]; if(isset($map['BATCH'])){[$h,$body]=$this->tableRows($map['BATCH']);foreach($body as$i=>$row){$r=$this->assoc($h,$row);if(trim((string)($r['DEVELOPMENT_CODE']??''))===''||trim((string)($r['BATCH_CODE']??''))===''||trim((string)($r['NAME']??''))===''||!strtotime((string)($r['START_DATE']??''))||!strtotime((string)($r['END_DATE']??''))){$errors[]='BATCH baris '.($i+2).': kolom wajib/tanggal tidak valid.';continue;}$batchRows[]=$r;}}
        $fieldRows=[]; if(isset($map['FIELD'])){[$h,$body]=$this->tableRows($map['FIELD']);foreach($body as$i=>$row){$r=$this->assoc($h,$row);if(trim((string)($r['DEVELOPMENT_CODE']??''))===''||trim((string)($r['CODE']??''))===''||trim((string)($r['LABEL']??''))===''){$errors[]='FIELD baris '.($i+2).': DEVELOPMENT_CODE/CODE/LABEL wajib.';continue;}$fieldRows[]=$r;}}
        $scoreRows=[]; if(isset($map['SCORING'])){[$h,$body]=$this->tableRows($map['SCORING']);foreach($body as$i=>$row){$r=$this->assoc($h,$row);$cfg=json_decode((string)($r['CONFIG_JSON']??''),true);if(trim((string)($r['DEVELOPMENT_CODE']??''))===''||!is_array($cfg)){$errors[]='SCORING baris '.($i+2).': DEVELOPMENT_CODE/CONFIG_JSON tidak valid.';continue;}$r['_config']=$cfg;$scoreRows[]=$r;}}
        if($errors)throw ValidationException::withMessages(['file'=>$errors]);

        $summary=['upserted_programs'=>0,'upserted_batches'=>0,'upserted_fields'=>0,'created_scoring_versions'=>0];
        DB::transaction(function()use($programs,$batchRows,$fieldRows,$scoreRows,$actor,&$summary){
            foreach($programs as$r){
                $code=strtoupper(trim($r['CODE']));$existing=DB::table('HR_developments')->where('code',$code)->whereNull('deleted_at')->first();
                $this->saveProgram($existing?->id,['code'=>$code,'name'=>$r['NAME'],'description'=>$r['DESCRIPTION']??null,'objective'=>$r['OBJECTIVE']??null,'has_test'=>$this->truthy($r['HAS_TEST']??'0'),'output_badge'=>$this->truthy($r['OUTPUT_BADGE']??'0'),'badge_name'=>$r['BADGE_NAME']??null,'output_certificate'=>$this->truthy($r['OUTPUT_CERTIFICATE']??'0'),'certificate_title'=>$r['CERTIFICATE_TITLE']??null],$actor);$summary['upserted_programs']++;
            }
            foreach($batchRows as$r){$d=DB::table('HR_developments')->where('code',strtoupper(trim($r['DEVELOPMENT_CODE'])))->whereNull('deleted_at')->first();if(!$d)throw ValidationException::withMessages(['file'=>["BATCH {$r['BATCH_CODE']}: development tidak ditemukan."]]);$existing=DB::table('HR_development_batches')->where('development_id',$d->id)->where('batch_code',strtoupper(trim($r['BATCH_CODE'])))->whereNull('deleted_at')->first();$this->saveBatch((string)$d->id,$existing?->id,['batch_code'=>$r['BATCH_CODE'],'name'=>$r['NAME'],'start_date'=>date('Y-m-d',strtotime($r['START_DATE'])),'end_date'=>date('Y-m-d',strtotime($r['END_DATE'])),'status'=>in_array(strtolower((string)($r['STATUS']??'')),['planned','open','ongoing','completed','cancelled'],true)?strtolower($r['STATUS']):'planned','capacity'=>trim((string)($r['CAPACITY']??''))===''?null:(int)$r['CAPACITY'],'notes'=>$r['NOTES']??null]);$summary['upserted_batches']++;}
            $grouped=collect($fieldRows)->groupBy(fn($r)=>strtoupper(trim($r['DEVELOPMENT_CODE'])));foreach($grouped as$code=>$items){$d=DB::table('HR_developments')->where('code',$code)->whereNull('deleted_at')->first();if(!$d)throw ValidationException::withMessages(['file'=>["FIELD: development {$code} tidak ditemukan."]]);$fields=[];foreach($items as$r){$opts=json_decode((string)($r['OPTIONS_JSON']??''),true);$fields[]=['kind'=>in_array(strtolower((string)($r['KIND']??'')),['input','output'],true)?strtolower($r['KIND']):'input','code'=>$r['CODE'],'label'=>$r['LABEL'],'field_type'=>in_array(strtolower((string)($r['FIELD_TYPE']??'')),['text','textarea','number','date','boolean','select'],true)?strtolower($r['FIELD_TYPE']):'text','is_required'=>$this->truthy($r['REQUIRED']??'0'),'sort_order'=>(int)($r['SORT_ORDER']??0),'options'=>is_array($opts)?$opts:[]];}$this->syncFields((string)$d->id,$fields);$summary['upserted_fields']+=count($fields);}
            foreach($scoreRows as$r){$d=DB::table('HR_developments')->where('code',strtoupper(trim($r['DEVELOPMENT_CODE'])))->whereNull('deleted_at')->first();if(!$d)throw ValidationException::withMessages(['file'=>["SCORING: development {$r['DEVELOPMENT_CODE']} tidak ditemukan."]]);$before=(int)(DB::table('HR_development_scoring_policies')->where('development_id',$d->id)->max('version')??0);$this->syncScoringPolicy((string)$d->id,['name'=>$r['NAME']??'Scoring Policy','mode'=>strtolower((string)($r['MODE']??'bands')),'config'=>$r['_config']],$actor?->id);$after=(int)(DB::table('HR_development_scoring_policies')->where('development_id',$d->id)->max('version')??0);if($after>$before)$summary['created_scoring_versions']++;}
        });
        return $summary;
    }

    private function syncFields(string $devId,array $fields): void
    {
        DB::table('HR_development_fields')->where('development_id',$devId)->update(['is_active'=>false,'updated_at'=>now()]);
        foreach($fields as$i=>$f){$code=strtoupper(trim((string)($f['code']??'')));$label=trim((string)($f['label']??''));$kind=strtolower(trim((string)($f['kind']??'input')));$type=strtolower(trim((string)($f['field_type']??'text')));if($code===''||$label===''||!in_array($kind,['input','output'],true)||!in_array($type,['text','textarea','number','date','boolean','select'],true))throw ValidationException::withMessages(["fields.$i"=>['Field development tidak valid.']]);
            $existing=DB::table('HR_development_fields')->where('development_id',$devId)->where('code',$code)->first();$payload=['kind'=>$kind,'label'=>$label,'field_type'=>$type,'options'=>isset($f['options'])?json_encode($f['options'],JSON_UNESCAPED_UNICODE):null,'is_required'=>(bool)($f['is_required']??false),'sort_order'=>(int)($f['sort_order']??$i),'is_active'=>true,'updated_at'=>now()];if($existing)DB::table('HR_development_fields')->where('id',$existing->id)->update($payload);else DB::table('HR_development_fields')->insert(array_merge(['id'=>(string)Str::ulid(),'development_id'=>$devId,'code'=>$code,'created_at'=>now()],$payload));}
    }

    private function syncScoringPolicy(string $devId,array $policy,?string $userId): void
    {
        $mode=strtolower(trim((string)($policy['mode']??'bands')));$config=$this->scoring->validateConfig($mode,(array)($policy['config']??[]));$name=trim((string)($policy['name']??'Scoring Policy'))?:'Scoring Policy';$current=$this->scoring->activePolicy($devId);$signature=hash('sha256',json_encode([$name,$mode,$config],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));$currentSignature=$current?hash('sha256',json_encode([$current->name,$current->mode,json_decode((string)$current->config,true)],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)):null;if($signature===$currentSignature)return;
        $version=(int)(DB::table('HR_development_scoring_policies')->where('development_id',$devId)->max('version')??0)+1;$id=(string)Str::ulid();DB::table('HR_development_scoring_policies')->where('development_id',$devId)->update(['is_active'=>false,'updated_at'=>now()]);DB::table('HR_development_scoring_policies')->insert(['id'=>$id,'development_id'=>$devId,'version'=>$version,'name'=>$name,'mode'=>$mode,'config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),'is_active'=>true,'created_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);DB::table('HR_developments')->where('id',$devId)->update(['active_scoring_policy_id'=>$id,'updated_at'=>now()]);
    }

    private function syncParticipantValues(string $devId,string $participantId,array $values,?string $userId): void
    {
        $fields=DB::table('HR_development_fields')->where('development_id',$devId)->where('is_active',true)->get()->keyBy(fn($r)=>(string)$r->id);
        foreach($values as$key=>$value){$field=$fields[(string)$key]??$fields->first(fn($r)=>strtoupper((string)$r->code)===strtoupper((string)$key));if(!$field)continue;$this->validateFieldValue($field,$value);$existing=DB::table('HR_development_field_values')->where('participant_id',$participantId)->where('field_id',$field->id)->first();$json=is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):null;$text=is_array($value)?null:(is_bool($value)?($value?'1':'0'):(string)$value);$payload=['value_text'=>$text,'value_json'=>$json,'updated_by_user_id'=>$userId,'updated_at'=>now()];if($existing)DB::table('HR_development_field_values')->where('id',$existing->id)->update($payload);else DB::table('HR_development_field_values')->insert(array_merge(['id'=>(string)Str::ulid(),'participant_id'=>$participantId,'field_id'=>$field->id,'created_at'=>now()],$payload));}
        foreach($fields->where('is_required',true) as$field){$row=DB::table('HR_development_field_values')->where('participant_id',$participantId)->where('field_id',$field->id)->first();if(!$row||($row->value_text===null&&$row->value_json===null)||trim((string)$row->value_text)==='')throw ValidationException::withMessages(['values.'.strtolower($field->code)=>["{$field->label} wajib diisi."]]);}
    }

    private function validateFieldValue(object $field,mixed $value): void
    {
        if($value===null||$value===''){if($field->is_required)throw ValidationException::withMessages(['values.'.strtolower($field->code)=>["{$field->label} wajib diisi."]]);return;}
        if($field->field_type==='number'&&!is_numeric($value))throw ValidationException::withMessages(['values.'.strtolower($field->code)=>['Harus berupa angka.']]);if($field->field_type==='date'&&!strtotime((string)$value))throw ValidationException::withMessages(['values.'.strtolower($field->code)=>['Tanggal tidak valid.']]);if($field->field_type==='select'){$opts=$this->decode($field->options);if($opts!==[]&&!in_array((string)$value,array_map('strval',$opts),true))throw ValidationException::withMessages(['values.'.strtolower($field->code)=>['Pilihan tidak valid.']]);}
    }

    private function syncCompletedOutputsForDevelopment(string $developmentId,?string $userId=null): void
    {
        $ids=DB::table('HR_development_participants')->where('development_id',$developmentId)->where('status','completed')->whereNotNull('completed_at')->pluck('id');
        foreach($ids as $participantId)$this->ensureCompletedOutputs($developmentId,(string)$participantId,$userId);
    }

    private function ensureCompletedOutputs(string $developmentId,string $participantId,?string $userId=null): void
    {
        $d=DB::table('HR_developments')->where('id',$developmentId)->whereNull('deleted_at')->first();
        $p=DB::table('HR_development_participants')->where('id',$participantId)->where('development_id',$developmentId)->first();
        if(!$d||!$p||(string)$p->status!=='completed'||!$p->completed_at)return;
        if(!(bool)$d->output_badge&&!(bool)$d->output_certificate)return;

        $employee=DB::table('employees')->where('id',$p->employee_id)->first();
        $batch=DB::table('HR_development_batches')->where('id',$p->batch_id)->first();
        $snapshot=[
            'name'=>$employee?->full_name,
            'nisj'=>$employee?->nisj,
            'development'=>$d->name,
            'development_code'=>$d->code,
            'batch'=>$batch?->name,
            'result'=>$p->result_label?:'Completed',
            'score'=>$p->score_final,
            'completed_date'=>(string)$p->completed_at,
            'published_at'=>$p->result_published_at?(string)$p->result_published_at:null,
        ];
        if((bool)$d->output_badge){
            $this->issueAchievement($p,$d,'badge',$d->badge_name?:$d->name.' Badge',$snapshot,$userId);
        }
        if((bool)$d->output_certificate){
            $existing=DB::table('HR_development_achievements')->where('participant_id',$p->id)->where('type','certificate')->first();
            $certNo=(string)($existing?->achievement_code?:$this->certificateNumber($d,$employee,$p));
            $snap=array_merge($snapshot,['certificate_no'=>$certNo,'template'=>$this->templates->active($developmentId)]);
            $this->issueAchievement($p,$d,'certificate',$d->certificate_title?:'Certificate of Completion',$snap,$userId,$certNo);
        }
    }

    private function issueAchievement(object $p,object $d,string $type,string $title,array $snapshot,?string $userId,?string $code=null): void
    {
        $existing=DB::table('HR_development_achievements')->where('participant_id',$p->id)->where('type',$type)->first();if($existing?->revoked_at)return;$safeDev=substr(preg_replace('/[^A-Z0-9]/','',strtoupper((string)$d->code)),0,40);$safeEmp=substr(preg_replace('/[^A-Z0-9]/','',strtoupper((string)$p->employee_id)),-12);$generated=strtoupper($type).'-'.$safeDev.'-'.$safeEmp;$achievementCode=substr((string)($code?:$generated),0,100);$payload=['type'=>$type,'development_id'=>$d->id,'employee_id'=>$p->employee_id,'achievement_code'=>$achievementCode,'title'=>$title,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'issued_at'=>$existing?->issued_at?:($p->completed_at?:now()),'issued_by_user_id'=>$existing?->issued_by_user_id?:$userId,'revoked_at'=>null,'revoked_by_user_id'=>null,'updated_at'=>now()];if($existing)DB::table('HR_development_achievements')->where('id',$existing->id)->update($payload);else DB::table('HR_development_achievements')->insert(array_merge(['id'=>(string)Str::ulid(),'participant_id'=>$p->id,'created_at'=>now()],$payload));
    }

    private function logProgramEvent(string $developmentId,string $event,string $from,string $to,?string $actorId,array $snapshot): void
    {
        if(!DB::getSchemaBuilder()->hasTable('HR_development_program_events'))return;
        DB::table('HR_development_program_events')->insert(['id'=>(string)Str::ulid(),'development_id'=>$developmentId,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'snapshot'=>$snapshot===[]?null:json_encode($snapshot,JSON_UNESCAPED_UNICODE),'actor_user_id'=>$actorId,'created_at'=>now()]);
    }

    private function certificateNumber(object $d,?object $e,object $p): string { $year=$p->completed_at?date('Y',strtotime((string)$p->completed_at)):now()->format('Y');$dev=substr(preg_replace('/[^A-Z0-9]/','',strtoupper((string)$d->code)),0,36);$person=substr(preg_replace('/[^A-Z0-9]/','',strtoupper((string)($e?->nisj?:substr((string)$p->employee_id,-12)))),0,24);return substr('CERT/'.$year.'/'.$dev.'/'.$person,0,100); }
    private function participantInScope(Request $request,string $devId,string $participantId): object { $p=DB::table('HR_development_participants')->where('id',$participantId)->where('development_id',$devId)->first();if(!$p)abort(404,'Participant tidak ditemukan.');if(!$p->outlet_id||!$this->scope->isOutletAllowed($request,(string)$p->outlet_id))abort(403,'Participant berada di luar scope outlet user.');return$p; }
    private function findProgram(string $id): object { $r=DB::table('HR_developments')->where('id',$id)->whereNull('deleted_at')->first();if(!$r)abort(404,'Development tidak ditemukan.');return$r; }
    private function valuesForParticipants(array $ids): array { if($ids===[])return[];$rows=DB::table('HR_development_field_values as v')->join('HR_development_fields as f','f.id','=','v.field_id')->whereIn('v.participant_id',$ids)->get(['v.participant_id','f.id as field_id','f.code','f.label','f.kind','f.field_type','v.value_text','v.value_json']);return$rows->groupBy('participant_id')->map(fn($items)=>$items->mapWithKeys(fn($r)=>[(string)$r->field_id=>['field_id'=>(string)$r->field_id,'code'=>(string)$r->code,'label'=>(string)$r->label,'kind'=>(string)$r->kind,'field_type'=>(string)$r->field_type,'value'=>$r->value_json!==null?$this->decode($r->value_json):$r->value_text]])->all())->all(); }
    private function resolveImportParticipant(Request $request,array $r): ?object { $dev=DB::table('HR_developments')->where('code',strtoupper(trim($r['DEVELOPMENT_CODE']??'')))->whereNull('deleted_at')->first();$batch=$dev?DB::table('HR_development_batches')->where('development_id',$dev->id)->where('batch_code',strtoupper(trim($r['BATCH_CODE']??'')))->first():null;$emp=DB::table('employees')->whereRaw('LOWER(TRIM(nisj))=?',[strtolower(trim($r['NISJ']??''))])->first();$p=($batch&&$emp)?DB::table('HR_development_participants')->where('batch_id',$batch->id)->where('employee_id',$emp->id)->first():null;if(!$p||!$p->outlet_id||!$this->scope->isOutletAllowed($request,(string)$p->outlet_id))return null;return$p; }
    private function tableRows(array $rows): array { if($rows===[])return[[],[]];$headers=array_map(fn($v)=>strtoupper(trim((string)$v)),array_shift($rows));return[$headers,array_values(array_filter($rows,fn($r)=>collect($r)->contains(fn($v)=>trim((string)$v)!=='')))]; }
    private function assoc(array $h,array $r): array { $out=[];foreach($h as$i=>$k)if($k!=='')$out[$k]=$r[$i]??'';return$out; }
    private function truthy(mixed $v): bool { return in_array(strtolower(trim((string)$v)),['1','true','yes','y','ya','iya','lulus'],true); }
    private function decode(mixed $v): array { if(is_array($v))return$v;$x=json_decode((string)$v,true);return is_array($x)?$x:[]; }
    private function programPayload(object $r): array { return ['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'description'=>$r->description,'objective'=>$r->objective,'status'=>(string)$r->status,'has_test'=>(bool)$r->has_test,'output_badge'=>(bool)$r->output_badge,'badge_name'=>$r->badge_name,'badge_description'=>$r->badge_description,'badge_logo_path'=>$r->badge_logo_path??null,'badge_logo_mime'=>$r->badge_logo_mime??null,'badge_logo_original_name'=>$r->badge_logo_original_name??null,'badge_logo_size_bytes'=>isset($r->badge_logo_size_bytes)?(int)$r->badge_logo_size_bytes:null,'output_certificate'=>(bool)$r->output_certificate,'certificate_title'=>$r->certificate_title,'active_scoring_policy_id'=>$r->active_scoring_policy_id,'published_at'=>$r->published_at,'completed_at'=>$r->completed_at,'batches_count'=>(int)($r->batches_count??0),'participants_count'=>(int)($r->participants_count??0),'published_results_count'=>(int)($r->published_results_count??0)]; }
    private function batchPayload(object $r): array { return ['id'=>(string)$r->id,'development_id'=>(string)$r->development_id,'batch_code'=>(string)$r->batch_code,'name'=>(string)$r->name,'start_date'=>(string)$r->start_date,'end_date'=>(string)$r->end_date,'status'=>(string)$r->status,'capacity'=>$r->capacity===null?null:(int)$r->capacity,'notes'=>$r->notes]; }
    private function fieldPayload(object $r): array { return ['id'=>(string)$r->id,'kind'=>(string)$r->kind,'code'=>(string)$r->code,'label'=>(string)$r->label,'field_type'=>(string)$r->field_type,'options'=>$this->decode($r->options),'is_required'=>(bool)$r->is_required,'sort_order'=>(int)$r->sort_order,'is_active'=>(bool)$r->is_active]; }
    private function policyPayload(object $r): array { return ['id'=>(string)$r->id,'version'=>(int)$r->version,'name'=>(string)$r->name,'mode'=>(string)$r->mode,'config'=>$this->decode($r->config),'is_active'=>(bool)$r->is_active,'created_at'=>$r->created_at]; }
    private function participantPayload(object $r,array $values=[]): array { return ['id'=>(string)$r->id,'development_id'=>(string)$r->development_id,'batch_id'=>(string)$r->batch_id,'batch_name'=>$r->batch_name??null,'batch_code'=>$r->batch_code??null,'employee_id'=>(string)$r->employee_id,'nisj'=>$r->nisj??null,'full_name'=>$r->full_name??null,'outlet_id'=>$r->outlet_id,'outlet_name'=>$r->outlet_name??null,'assigned_via'=>(string)$r->assigned_via,'status'=>(string)$r->status,'score_raw'=>$r->score_raw===null?null:(float)$r->score_raw,'score_final'=>$r->score_final===null?null:(float)$r->score_final,'result_label'=>$r->result_label,'result_passed'=>$r->result_passed===null?null:(bool)$r->result_passed,'result_snapshot'=>$this->decode($r->result_snapshot),'notes'=>$r->notes,'joined_at'=>$r->joined_at,'completed_at'=>$r->completed_at,'result_published_at'=>$r->result_published_at,'values'=>$values]; }
    private function achievementPayload(object $r): array { return ['id'=>(string)$r->id,'type'=>(string)($r->type??'badge'),'title'=>(string)$r->title,'achievement_code'=>$r->achievement_code??null,'snapshot'=>$this->decode($r->snapshot??null),'issued_at'=>$r->issued_at,'development_name'=>$r->development_name??null,'badge_logo_path'=>$r->badge_logo_path??null]; }
    private function emptyPage(): array { return ['items'=>[],'pagination'=>['current_page'=>1,'last_page'=>1,'per_page'=>25,'total'=>0]]; }
}
