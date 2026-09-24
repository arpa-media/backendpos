<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrRecruitmentInterviewService
{
    public function __construct(
        private readonly UserAuthContextResolver $resolver,
        private readonly HrCareerDocumentService $documents,
        private readonly HrCareerPortalService $career,
        private readonly HrHiringConversionService $hiring,
    ) {}

    public function index(Request $request, array $filters = []): array
    {
        $user = $request->user();
        $ctx = $this->resolver->resolve($user);
        $mode = (string) ($ctx['scope_mode'] ?? 'NONE');
        if ($mode === 'NONE') abort(403, 'Akun tidak memiliki scope outlet.');

        $q = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->leftJoin('HR_career_accounts as ca', 'ca.id', '=', 'a.career_account_id');

        if ($mode === 'ONE') {
            $q->where('p.destination_outlet_id', (string) ($ctx['resolved_outlet_id'] ?? ''));
        }
        if (! empty($filters['stage'])) $q->where('a.stage', (string) $filters['stage']);
        if (! empty($filters['recruitment_id'])) $q->where('a.recruitment_id', (string) $filters['recruitment_id']);
        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $q->where(function ($x) use ($like): void {
                $x->where('a.applicant_name', 'like', $like)
                    ->orWhere('a.nik', 'like', $like)
                    ->orWhere('a.phone', 'like', $like)
                    ->orWhere('a.email', 'like', $like)
                    ->orWhere('r.title', 'like', $like)
                    ->orWhere('p.position_name', 'like', $like);
            });
        }

        $perPage = min(200, max(10, (int) ($filters['per_page'] ?? 50)));
        $page = $q->orderByRaw("CASE a.stage WHEN 'call_for_interview' THEN 0 WHEN 'interviewed' THEN 1 WHEN 'applied' THEN 2 ELSE 3 END")
            ->orderByDesc('a.stage_changed_at')->orderByDesc('a.applied_at')
            ->paginate($perPage, [
                'a.*', 'r.code as recruitment_code', 'r.title as recruitment_title',
                'p.position_name', 'p.destination_outlet_id', 'p.destination_type', 'p.employment_type_target',
                'o.name as destination_name', 'o.code as destination_code',
                'ca.application_blocked_at', 'ca.application_block_reason',
            ]);

        $items = collect($page->items())->map(function ($row): array {
            $latestInterview = DB::table('HR_interviews')->where('application_id', $row->id)->orderByDesc('sequence_no')->first();
            $conversion = DB::table('HR_hiring_conversions')->where('application_id', $row->id)->first();
            $item = (array) $row;
            $item['interview_count'] = DB::table('HR_interviews')->where('application_id', $row->id)->count();
            $item['latest_interview'] = $latestInterview ? (array) $latestInterview : null;
            $item['conversion'] = $conversion ? [
                'hire_type' => $conversion->hire_type, 'status' => $conversion->status,
                'assigned_nisj' => $conversion->assigned_nisj, 'contract_id' => $conversion->contract_id,
            ] : null;
            return $item;
        })->all();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ];
    }

    public function detail(Request $request, string $applicationId): array
    {
        $a = $this->scopedApplication($request,$applicationId);
        $profile = $a->career_account_id ? DB::table('HR_career_profiles')->where('career_account_id',$a->career_account_id)->first() : null;
        $cv = $a->career_account_id ? $this->documents->currentCv((string)$a->career_account_id) : null;
        $history = DB::table('HR_application_stage_histories as h')->leftJoin('users as u','u.id','=','h.actor_user_id')->where('h.application_id',$a->id)->orderBy('h.changed_at')->get(['h.*','u.name as actor_name'])->map(fn($r)=>(array)$r)->all();
        $interviews = DB::table('HR_interviews')->where('application_id',$a->id)->orderBy('sequence_no')->get()->map(fn($r)=>(array)$r)->all();
        $conversion = DB::table('HR_hiring_conversions')->where('application_id',$a->id)->first();
        return ['application'=>(array)$a,'profile'=>$profile?(array)$profile:null,'cv'=>$cv?$this->documents->metadata($cv):null,'history'=>$history,'interviews'=>$interviews,'conversion'=>$conversion?(array)$conversion:null];
    }

    public function callInterview(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function() use($request,$applicationId,$data,$actor){
            $a=$this->scopedApplication($request,$applicationId,true);
            if(!in_array((string)$a->stage,['applied','interviewed'],true))throw ValidationException::withMessages(['stage'=>['Hanya applicant Applied atau Interviewed yang dapat dipanggil interview ulang.']]);
            $from=(string)$a->stage;
            DB::table('HR_applications')->where('id',$a->id)->update(['stage'=>'call_for_interview','stage_changed_at'=>now(),'notes'=>$data['note']??$a->notes,'updated_at'=>now()]);
            $this->career->stageHistory((string)$a->id,$from,'call_for_interview','admin',(string)$actor->id,null,$data['note']??'Dipanggil interview.',['scheduled_at'=>$data['scheduled_at']??null]);
            if(!empty($data['scheduled_at'])){
                $seq=(int)DB::table('HR_interviews')->where('application_id',$a->id)->max('sequence_no')+1;
                DB::table('HR_interviews')->insert(['id'=>(string)Str::ulid(),'application_id'=>$a->id,'sequence_no'=>$seq,'scheduled_at'=>$data['scheduled_at'],'interviewer_user_id'=>$actor->id,'interviewer_name_snapshot'=>$actor->name,'notes'=>$data['note']??null,'recommendation'=>'scheduled','result'=>null,'recorded_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
            return $this->detail($request,$applicationId);
        });
    }

    public function recordInterview(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function() use($request,$applicationId,$data,$actor){
            $a=$this->scopedApplication($request,$applicationId,true);
            $result=$data['result']??'interviewed';
            // Idempotent acceptance: a client retry after a timeout must return the
            // already-created conversion instead of producing a second interview or identity.
            if (in_array((string) $a->stage, ['accepted_spt','accepted_pkwt'], true)) {
                if ((string) $a->stage === (string) $result) return $this->detail($request, $applicationId);
                throw ValidationException::withMessages(['stage'=>['Application sudah diterima dengan hasil berbeda dan tidak dapat ditimpa.']]);
            }
            if ((string) $a->stage === 'rejected_all') throw ValidationException::withMessages(['stage'=>['Application sudah final dan tidak dapat ditimpa.']]);
            $seq=(int)DB::table('HR_interviews')->where('application_id',$a->id)->max('sequence_no')+1;
            DB::table('HR_interviews')->insert([
                'id'=>(string)Str::ulid(),'application_id'=>$a->id,'sequence_no'=>$seq,'scheduled_at'=>$data['scheduled_at']??null,'conducted_at'=>$data['conducted_at']??now(),
                'interviewer_user_id'=>$actor->id,'interviewer_name_snapshot'=>$actor->name,'notes'=>$data['notes']??null,'recommendation'=>$data['recommendation']??null,
                'result'=>$result,'metadata'=>!empty($data['metadata'])?json_encode($data['metadata'],JSON_UNESCAPED_UNICODE):null,'recorded_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $from=(string)$a->stage;
            $to=in_array($result,['accepted_spt','accepted_pkwt','rejected_partial','rejected_all'],true)?$result:'interviewed';
            DB::table('HR_applications')->where('id',$a->id)->update(['stage'=>$to,'stage_changed_at'=>now(),'updated_at'=>now()]);
            $this->career->stageHistory((string)$a->id,$from,$to,'interview',(string)$actor->id,null,$data['notes']??null,['interview_sequence'=>$seq,'recommendation'=>$data['recommendation']??null]);

            if($to==='rejected_all'&&$a->career_account_id){
                DB::table('HR_career_accounts')->where('id',$a->career_account_id)->update(['application_blocked_at'=>now(),'application_block_reason'=>'Rejected all pada '.$a->recruitment_title.' / '.$a->position_name,'updated_at'=>now()]);
            }
            if(in_array($to,['accepted_spt','accepted_pkwt'],true)){
                if($a->career_account_id){
                    DB::table('HR_career_accounts')->where('id',$a->career_account_id)->update(['application_blocked_at'=>now(),'application_block_reason'=>'Accepted '.$to.' pada '.$a->recruitment_title.' / '.$a->position_name,'updated_at'=>now()]);
                }
                $hireType=$to==='accepted_spt'?'spt':'pkwt';
                $this->hiring->convert((string)$a->id,$hireType,[
                    'contract_start_date'=>$data['contract_start_date']??now()->toDateString(),'contract_end_date'=>$data['contract_end_date']??null,
                    'division_name'=>$data['division_name']??null,'contract_notes'=>$data['contract_notes']??null,
                ],$actor);
            }
            return $this->detail($request,$applicationId);
        });
    }

    public function downloadCv(Request $request,string $applicationId)
    {
        $a=$this->scopedApplication($request,$applicationId);
        if(!$a->career_account_id)abort(404,'Career Account tidak tersedia.');
        return $this->documents->downloadForAccount((string)$a->career_account_id);
    }

    private function scopedApplication(Request $request,string $id,bool $lock=false): object
    {
        $q=DB::table('HR_applications as a')->join('HR_recruitments as r','r.id','=','a.recruitment_id')->join('HR_recruitment_positions as p','p.id','=','a.recruitment_position_id')->join('outlets as o','o.id','=','p.destination_outlet_id')->where('a.id',$id);
        if($lock)$q->lockForUpdate();
        $a=$q->first(['a.*','r.title as recruitment_title','r.code as recruitment_code','p.position_name','p.destination_outlet_id','p.destination_type','p.employment_type_target','o.name as destination_name','o.code as destination_code']);
        abort_unless($a,404,'Application tidak ditemukan.');
        $user=$request->user();
        $ctx=$this->resolver->resolve($user);
        if(($ctx['scope_mode']??'NONE')==='NONE')abort(403,'Akun tidak memiliki scope outlet.');
        if(($ctx['scope_mode']??'NONE')==='ONE'&&(string)($ctx['resolved_outlet_id']??'')!==(string)$a->destination_outlet_id)abort(403,'Application di luar scope outlet Anda.');
        return $a;
    }
}
