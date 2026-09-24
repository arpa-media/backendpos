<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrRecruitmentService
{
    public function __construct(private readonly UserAuthContextResolver $resolver) {}

    public function references(Request $request): array
    {
        $destinations = $this->destinationOptions($request);
        $masters = DB::table('HR_master_data')
            ->whereNull('deleted_at')->where('is_active', true)
            ->whereIn('type', ['position','division'])
            ->orderBy('name')->get(['id','type','name'])
            ->groupBy('type');

        return [
            'destinations' => $destinations,
            'positions' => collect($masters->get('position', []))->map(fn($r)=>['id'=>(string)$r->id,'name'=>(string)$r->name])->values()->all(),
            'divisions' => collect($masters->get('division', []))->map(fn($r)=>['id'=>(string)$r->id,'name'=>(string)$r->name])->values()->all(),
            'destination_types' => [
                ['value'=>'outlet','label'=>'Outlet'],
                ['value'=>'management','label'=>'Management'],
                ['value'=>'warehouse','label'=>'Warehouse'],
            ],
            'employment_targets' => [
                ['value'=>'any','label'=>'Belum ditentukan'],
                ['value'=>'spt','label'=>'SPT'],
                ['value'=>'pkwt','label'=>'PKWT'],
            ],
            'registration_statuses' => ['pending','approved','rejected'],
        ];
    }

    public function index(Request $request, array $filters): array
    {
        $allowed = $this->allowedDestinationIds($request);
        if ($allowed === []) return $this->emptyPage();

        $query = DB::table('HR_recruitments as r')->whereNull('r.deleted_at')
            ->whereExists(function($q) use ($allowed) {
                $q->selectRaw('1')->from('HR_recruitment_positions as p')
                    ->whereColumn('p.recruitment_id','r.id')->whereNull('p.deleted_at')
                    ->whereIn('p.destination_outlet_id', $allowed);
            })
            ->when($filters['status'] ?? null, fn($q,$v)=>$q->where('r.status',$v))
            ->when($filters['search'] ?? null, function($q,$v){
                $like='%'.trim((string)$v).'%';
                $q->where(fn($x)=>$x->where('r.code','like',$like)->orWhere('r.title','like',$like)->orWhere('r.description','like',$like)
                    ->orWhereExists(fn($p)=>$p->selectRaw('1')->from('HR_recruitment_positions as rp')->whereColumn('rp.recruitment_id','r.id')->whereNull('rp.deleted_at')->where('rp.position_name','like',$like)));
            });

        if (!empty($filters['destination_outlet_id'])) {
            $dest=(string)$filters['destination_outlet_id'];
            if (!in_array($dest,$allowed,true)) return $this->emptyPage();
            $query->whereExists(fn($p)=>$p->selectRaw('1')->from('HR_recruitment_positions as rp')->whereColumn('rp.recruitment_id','r.id')->whereNull('rp.deleted_at')->where('rp.destination_outlet_id',$dest));
        }

        $sort=in_array($filters['sort_by']??'created_at',['created_at','title','code','status','active_from','active_until'],true)?($filters['sort_by']??'created_at'):'created_at';
        $dir=strtolower((string)($filters['sort_direction']??'desc'))==='asc'?'asc':'desc';
        $p=$query->orderBy("r.$sort",$dir)->paginate(min(100,max(10,(int)($filters['per_page']??30))));
        $items=collect($p->items())->map(fn($r)=>$this->summaryPayload($r,$allowed))->values()->all();

        return ['items'=>$items,'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function show(Request $request, string $id): array
    {
        $allowed=$this->allowedDestinationIds($request);
        $row=$this->findScopedRecruitment($id,$allowed);
        $positions=DB::table('HR_recruitment_positions as p')->join('outlets as o','o.id','=','p.destination_outlet_id')
            ->where('p.recruitment_id',$id)->whereNull('p.deleted_at')->whereIn('p.destination_outlet_id',$allowed)
            ->orderBy('p.sort_order')->orderBy('p.position_name')
            ->get(['p.*','o.code as destination_code','o.name as destination_name','o.type as outlet_type'])
            ->map(fn($p)=>$this->positionPayload($p))->values()->all();
        return array_merge($this->summaryPayload($row,$allowed),['description'=>$row->description,'notes'=>$row->notes,'positions'=>$positions]);
    }

    public function save(Request $request, ?string $id, array $data, ?User $actor): array
    {
        $allowed=$this->allowedDestinationIds($request);
        if ($allowed===[]) throw ValidationException::withMessages(['destination'=>['Tidak ada destination yang dapat Anda kelola.']]);
        if (!empty($data['active_from']) && !empty($data['active_until']) && $data['active_until'] < $data['active_from']) {
            throw ValidationException::withMessages(['active_until'=>['Periode akhir tidak boleh sebelum periode mulai.']]);
        }

        return DB::transaction(function() use($request,$id,$data,$actor,$allowed){
            $now=now();
            $existing=$id?DB::table('HR_recruitments')->where('id',$id)->whereNull('deleted_at')->lockForUpdate()->first():null;
            if($id&&!$existing) abort(404,'Recruitment tidak ditemukan.');
            if($existing) $this->assertFullyScoped($id,$allowed);
            if($existing && in_array((string)$existing->status,['closed','cancelled'],true)) throw ValidationException::withMessages(['status'=>['Recruitment yang sudah ditutup/cancel tidak dapat diedit.']]);

            $code=strtoupper(trim((string)($data['code']??'')));
            if($code==='') $code=$this->generateCode();
            $dup=DB::table('HR_recruitments')->whereRaw('UPPER(code)=?',[strtoupper($code)])->when($id,fn($q)=>$q->where('id','!=',$id))->exists();
            if($dup) throw ValidationException::withMessages(['code'=>['Kode recruitment sudah digunakan.']]);

            $recId=$id ?: (string)Str::ulid();
            $payload=['code'=>$code,'title'=>trim((string)$data['title']),'description'=>$data['description']??null,'active_from'=>$data['active_from']??null,'active_until'=>$data['active_until']??null,'notes'=>$data['notes']??null,'updated_by_user_id'=>$actor?->id,'updated_at'=>$now];
            if($existing) DB::table('HR_recruitments')->where('id',$recId)->update($payload);
            else DB::table('HR_recruitments')->insert(array_merge(['id'=>$recId,'status'=>'draft','created_by_user_id'=>$actor?->id,'created_at'=>$now],$payload));

            $this->syncPositions($request,$recId,(array)$data['positions'],$allowed);
            return $this->show($request,$recId);
        });
    }

    public function publish(Request $request, string $id, ?User $actor): array
    {
        return DB::transaction(function() use($request,$id,$actor){
            $allowed=$this->allowedDestinationIds($request);
            $row=$this->findScopedRecruitment($id,$allowed,true); $this->assertFullyScoped($id,$allowed);
            if(in_array((string)$row->status,['closed','cancelled'],true)) throw ValidationException::withMessages(['status'=>['Recruitment yang sudah ditutup tidak dapat dipublish.']]);
            $activePositions=DB::table('HR_recruitment_positions')->where('recruitment_id',$id)->whereNull('deleted_at')->where('is_active',true)->whereIn('destination_outlet_id',$allowed)->count();
            if($activePositions<1) throw ValidationException::withMessages(['positions'=>['Minimal satu posisi aktif wajib tersedia.']]);
            if(!$row->active_from || !$row->active_until) throw ValidationException::withMessages(['active_from'=>['Periode recruitment wajib lengkap sebelum publish.']]);
            if(now()->gt($row->active_until)) throw ValidationException::withMessages(['active_until'=>['Periode recruitment sudah berakhir.']]);
            DB::table('HR_recruitments')->where('id',$id)->update(['status'=>'published','published_by_user_id'=>$actor?->id,'published_at'=>$row->published_at ?: now(),'closed_by_user_id'=>null,'closed_at'=>null,'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
            return $this->show($request,$id);
        });
    }

    public function close(Request $request, string $id, ?User $actor): array
    {
        $allowed=$this->allowedDestinationIds($request); $this->findScopedRecruitment($id,$allowed); $this->assertFullyScoped($id,$allowed);
        DB::table('HR_recruitments')->where('id',$id)->update(['status'=>'closed','closed_by_user_id'=>$actor?->id,'closed_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
        return $this->show($request,$id);
    }

    public function destroy(Request $request, string $id): void
    {
        $allowed=$this->allowedDestinationIds($request); $row=$this->findScopedRecruitment($id,$allowed); $this->assertFullyScoped($id,$allowed);
        if((string)$row->status!=='draft') throw ValidationException::withMessages(['recruitment'=>['Hanya recruitment Draft tanpa applicant yang dapat dihapus. Tutup recruitment untuk mempertahankan history.']]);
        if(DB::table('HR_applications')->where('recruitment_id',$id)->exists()) throw ValidationException::withMessages(['recruitment'=>['Recruitment sudah memiliki applicant dan tidak boleh dihapus. Gunakan Tutup Recruitment.']]);
        DB::transaction(function() use($id){$now=now();DB::table('HR_recruitment_positions')->where('recruitment_id',$id)->whereNull('deleted_at')->update(['deleted_at'=>$now,'updated_at'=>$now]);DB::table('HR_recruitments')->where('id',$id)->update(['deleted_at'=>$now,'updated_at'=>$now]);});
    }

    public function applicants(Request $request, string $id, array $filters): array
    {
        $allowed=$this->allowedDestinationIds($request); $this->findScopedRecruitment($id,$allowed);
        $q=DB::table('HR_applications as a')->join('HR_recruitment_positions as p','p.id','=','a.recruitment_position_id')->join('outlets as o','o.id','=','p.destination_outlet_id')
            ->where('a.recruitment_id',$id)->whereIn('p.destination_outlet_id',$allowed)
            ->when($filters['stage']??null,fn($x,$v)=>$x->where('a.stage',$v))
            ->when($filters['position_id']??null,fn($x,$v)=>$x->where('a.recruitment_position_id',$v))
            ->when($filters['search']??null,function($x,$v){$like='%'.trim((string)$v).'%';$x->where(fn($z)=>$z->where('a.applicant_name','like',$like)->orWhere('a.nik','like',$like)->orWhere('a.phone','like',$like)->orWhere('a.email','like',$like));})
            ->orderByDesc('a.applied_at')->orderByDesc('a.created_at');
        $p=$q->paginate(min(200,max(10,(int)($filters['per_page']??50))),['a.*','p.position_name','p.code as position_code','p.quota','o.name as destination_name','o.code as destination_code']);
        return ['items'=>collect($p->items())->map(fn($r)=>(array)$r)->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function registrationRequests(array $filters): array
    {
        $q=DB::table('HR_career_registration_requests as r')->leftJoin('users as u','u.id','=','r.reviewed_by_user_id')
            ->when($filters['status']??null,fn($x,$v)=>$x->where('r.status',$v))
            ->when($filters['search']??null,function($x,$v){$like='%'.trim((string)$v).'%';$x->where(fn($z)=>$z->where('r.full_name','like',$like)->orWhere('r.nik','like',$like)->orWhere('r.phone','like',$like)->orWhere('r.email','like',$like));})
            ->orderByRaw("CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('r.requested_at')->orderByDesc('r.created_at');
        $p=$q->paginate(min(200,max(10,(int)($filters['per_page']??50))),['r.*','u.name as reviewer_name']);
        return ['items'=>collect($p->items())->map(function($r){$a=(array)$r;$a['request_type']='registration';$a['metadata']=$this->decodeJson($r->metadata??null);return $a;})->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function reviewRegistration(string $id, string $decision, ?string $notes, ?User $actor): array
    {
        return DB::transaction(function() use($id,$decision,$notes,$actor){
            $row=DB::table('HR_career_registration_requests')->where('id',$id)->lockForUpdate()->first();
            if(!$row) abort(404,'Request register tidak ditemukan.');
            if((string)$row->status!=='pending') throw ValidationException::withMessages(['status'=>['Request register sudah diproses.']]);
            if($decision==='approved'){
                $this->assertNikNotSquad((string)$row->nik);
                $approved=DB::table('HR_career_registration_requests')->where('nik',$row->nik)->where('status','approved')->where('id','!=',$id)->exists();
                if($approved) throw ValidationException::withMessages(['nik'=>['NIK ini sudah memiliki request register yang approved.']]);
            }
            $status=$decision==='approved'?'approved':'rejected';
            DB::table('HR_career_registration_requests')->where('id',$id)->update(['status'=>$status,'reviewed_by_user_id'=>$actor?->id,'reviewed_at'=>now(),'review_notes'=>$notes,'updated_at'=>now()]);
            if($status==='approved'){
                DB::table('HR_career_registration_requests')->where('nik',$row->nik)->where('status','pending')->where('id','!=',$id)->update(['status'=>'rejected','reviewed_by_user_id'=>$actor?->id,'reviewed_at'=>now(),'review_notes'=>'Ditutup otomatis karena request NIK yang sama sudah approved.','updated_at'=>now()]);
                // Approval admin langsung mematerialisasi Career Account agar tidak bergantung
                // pada login pertama. Profile/application history existing tidak disentuh.
                app(HrCareerAccountService::class)->provisionFromRegistration($id);
            }
            $result=(array)DB::table('HR_career_registration_requests')->where('id',$id)->first();
            $result['request_type']='registration';
            return $result;
        });
    }

    private function syncPositions(Request $request, string $recruitmentId, array $positions, array $allowed): void
    {
        if($positions===[]) throw ValidationException::withMessages(['positions'=>['Minimal satu posisi recruitment wajib diisi.']]);
        $seenCodes=[]; $kept=[]; $now=now();
        foreach($positions as $i=>$input){
            $code=strtoupper(trim((string)($input['code']??''))); if($code==='') $code='POS-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
            if(isset($seenCodes[$code])) throw ValidationException::withMessages(["positions.$i.code"=>['Kode posisi duplikat pada recruitment yang sama.']]); $seenCodes[$code]=true;
            $dest=(string)($input['destination_outlet_id']??'');
            if(!in_array($dest,$allowed,true)) throw ValidationException::withMessages(["positions.$i.destination_outlet_id"=>['Destination berada di luar scope Anda.']]);
            $outlet=DB::table('outlets')->where('id',$dest)->where('is_active',true)->first(['id','type']);
            if(!$outlet) throw ValidationException::withMessages(["positions.$i.destination_outlet_id"=>['Destination tidak ditemukan/aktif.']]);
            $actualType=$this->normalizeDestinationType((string)$outlet->type); $requested=(string)($input['destination_type']??$actualType);
            if($requested!==$actualType) throw ValidationException::withMessages(["positions.$i.destination_type"=>['Tipe destination tidak sesuai Master Outlet.']]);
            $quota=max(1,(int)($input['quota']??1));
            $posId=trim((string)($input['id']??'')); $existing=null;
            if($posId!=='') $existing=DB::table('HR_recruitment_positions')->where('id',$posId)->where('recruitment_id',$recruitmentId)->whereNull('deleted_at')->lockForUpdate()->first();
            if($posId!==''&&!$existing) throw ValidationException::withMessages(["positions.$i.id"=>['Posisi recruitment tidak ditemukan.']]);
            if(!$existing){$dup=DB::table('HR_recruitment_positions')->where('recruitment_id',$recruitmentId)->where('code',$code)->exists();if($dup)throw ValidationException::withMessages(["positions.$i.code"=>['Kode posisi sudah digunakan.']]);$posId=(string)Str::ulid();}
            $appCount=$existing?DB::table('HR_applications')->where('recruitment_position_id',$posId)->count():0;
            if($appCount>0){
                if($code!==(string)$existing->code || trim((string)$input['position_name'])!==(string)$existing->position_name || $dest!==(string)$existing->destination_outlet_id || $requested!==(string)$existing->destination_type || (string)($input['employment_type_target']??'any')!==(string)$existing->employment_type_target){
                    throw ValidationException::withMessages(["positions.$i"=>['Posisi yang sudah memiliki applicant tidak boleh mengganti kode, jabatan, destination, atau target kontrak. Buat posisi baru.']]);
                }
                if($quota<$appCount) throw ValidationException::withMessages(["positions.$i.quota"=>["Quota tidak boleh lebih kecil dari applicant existing ($appCount)."]]);
            }
            $payload=['code'=>$code,'position_name'=>trim((string)$input['position_name']),'destination_type'=>$requested,'destination_outlet_id'=>$dest,'quota'=>$quota,'employment_type_target'=>(string)($input['employment_type_target']??'any'),'description'=>$input['description']??null,'sort_order'=>$i,'is_active'=>(bool)($input['is_active']??true),'updated_at'=>$now];
            if($existing) DB::table('HR_recruitment_positions')->where('id',$posId)->update($payload); else DB::table('HR_recruitment_positions')->insert(array_merge(['id'=>$posId,'recruitment_id'=>$recruitmentId,'created_at'=>$now],$payload));
            if($appCount===0) $this->syncQualifications($posId,(array)($input['qualifications']??[]));
            $kept[]=$posId;
        }
        $removed=DB::table('HR_recruitment_positions')->where('recruitment_id',$recruitmentId)->whereNull('deleted_at')->whereIn('destination_outlet_id',$allowed)->whereNotIn('id',$kept)->get(['id']);
        foreach($removed as $r){$has=DB::table('HR_applications')->where('recruitment_position_id',$r->id)->exists();if($has)DB::table('HR_recruitment_positions')->where('id',$r->id)->update(['is_active'=>false,'updated_at'=>$now]);else DB::table('HR_recruitment_positions')->where('id',$r->id)->update(['deleted_at'=>$now,'updated_at'=>$now]);}
    }

    private function syncQualifications(string $positionId, array $items): void
    {
        DB::table('HR_recruitment_qualifications')->where('recruitment_position_id',$positionId)->delete();
        $now=now(); foreach($items as $i=>$q){$label=trim((string)($q['label']??''));if($label==='')continue;DB::table('HR_recruitment_qualifications')->insert(['id'=>(string)Str::ulid(),'recruitment_position_id'=>$positionId,'requirement_type'=>in_array(($q['requirement_type']??'required'),['required','preferred'],true)?$q['requirement_type']:'required','label'=>$label,'sort_order'=>$i,'created_at'=>$now,'updated_at'=>$now]);}
    }

    private function summaryPayload(object $r, array $allowed): array
    {
        $positions=DB::table('HR_recruitment_positions')->where('recruitment_id',$r->id)->whereNull('deleted_at')->whereIn('destination_outlet_id',$allowed)->get(['id','quota','is_active']);
        $posIds=$positions->pluck('id')->all(); $appCount=$posIds?DB::table('HR_applications')->whereIn('recruitment_position_id',$posIds)->count():0;
        $interviewCount=$posIds?DB::table('HR_applications')->whereIn('recruitment_position_id',$posIds)->whereIn('stage',['call_for_interview','interviewed','accepted_spt','accepted_pkwt'])->count():0;
        $quota=(int)$positions->where('is_active',true)->sum('quota');
        $effective=$this->effectiveStatus((string)$r->status,$r->active_from??null,$r->active_until??null);
        return ['id'=>(string)$r->id,'code'=>(string)$r->code,'title'=>(string)$r->title,'status'=>(string)$r->status,'effective_status'=>$effective,'active_from'=>$r->active_from,'active_until'=>$r->active_until,'published_at'=>$r->published_at,'closed_at'=>$r->closed_at,'positions_count'=>$positions->count(),'quota_total'=>$quota,'applicant_count'=>$appCount,'interview_count'=>$interviewCount,'quota_utilization'=>$quota>0?round(($appCount/$quota)*100,2):0,'created_at'=>$r->created_at,'updated_at'=>$r->updated_at];
    }

    private function positionPayload(object $p): array
    {
        $quals=DB::table('HR_recruitment_qualifications')->where('recruitment_position_id',$p->id)->orderBy('sort_order')->get(['id','requirement_type','label','sort_order'])->map(fn($r)=>(array)$r)->all();
        $stages=DB::table('HR_applications')->where('recruitment_position_id',$p->id)->select('stage',DB::raw('COUNT(*) as total'))->groupBy('stage')->pluck('total','stage')->map(fn($v)=>(int)$v)->all();
        $apps=array_sum($stages);
        return ['id'=>(string)$p->id,'code'=>(string)$p->code,'position_name'=>(string)$p->position_name,'destination_type'=>(string)$p->destination_type,'destination_outlet_id'=>(string)$p->destination_outlet_id,'destination_code'=>(string)($p->destination_code??''),'destination_name'=>(string)($p->destination_name??'-'),'quota'=>(int)$p->quota,'employment_type_target'=>(string)$p->employment_type_target,'description'=>$p->description,'sort_order'=>(int)$p->sort_order,'is_active'=>(bool)$p->is_active,'qualifications'=>$quals,'applicant_count'=>$apps,'quota_remaining'=>max(0,(int)$p->quota-$apps),'stage_counts'=>$stages];
    }

    private function destinationOptions(Request $request): array
    {
        $ids=$this->allowedDestinationIds($request); if($ids===[])return[];
        return DB::table('outlets')->whereIn('id',$ids)->where('is_active',true)->orderByRaw("FIELD(LOWER(type),'outlet','headquarter','warehouse')")->orderBy('name')->get(['id','code','name','type','timezone'])->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'outlet_type'=>(string)$r->type,'destination_type'=>$this->normalizeDestinationType((string)$r->type),'timezone'=>(string)($r->timezone??'Asia/Jakarta')])->values()->all();
    }

    private function allowedDestinationIds(Request $request): array
    {
        $user=$request->user(); if(!$user)return[]; $ctx=$this->resolver->resolve($user); $mode=$ctx['scope_mode']??'NONE';
        if($mode==='NONE')return[];
        $q=DB::table('outlets')->where('is_active',true)->whereRaw("LOWER(COALESCE(type,'outlet')) IN ('outlet','headquarter','warehouse')");
        if($mode==='ONE')$q->where('id',(string)($ctx['resolved_outlet_id']??''));
        return $q->pluck('id')->map(fn($v)=>(string)$v)->all();
    }

    private function findScopedRecruitment(string $id, array $allowed, bool $lock=false): object
    {
        $q=DB::table('HR_recruitments as r')->where('r.id',$id)->whereNull('r.deleted_at')->whereExists(function($x) use($allowed){$x->selectRaw('1')->from('HR_recruitment_positions as p')->whereColumn('p.recruitment_id','r.id')->whereNull('p.deleted_at')->whereIn('p.destination_outlet_id',$allowed);});
        if($lock)$q->lockForUpdate(); $row=$q->first(); if(!$row)abort(404,'Recruitment tidak ditemukan atau di luar scope.'); return $row;
    }


    private function assertFullyScoped(string $recruitmentId, array $allowed): void
    {
        $outside=DB::table('HR_recruitment_positions')->where('recruitment_id',$recruitmentId)->whereNull('deleted_at')->whereNotIn('destination_outlet_id',$allowed)->exists();
        if($outside) throw ValidationException::withMessages(['scope'=>['Recruitment ini mencakup destination di luar scope Anda. Perubahan recruitment multi-destination hanya dapat dilakukan user Management/Admin dengan scope penuh.']]);
    }

    private function assertNikNotSquad(string $nik): void
    {
        $nik=trim($nik);
        if($nik!=='' && DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('TRIM(nik) = ?', [$nik])->exists()) {
            throw ValidationException::withMessages(['nik'=>['NIK sudah terdaftar sebagai Squad. Registrasi Career tidak diperlukan; gunakan akses Squad/POS atau hubungi HR.']]);
        }
    }

    private function normalizeDestinationType(string $type): string { return match(strtolower(trim($type))){'headquarter','management'=>'management','warehouse'=>'warehouse',default=>'outlet'}; }
    private function effectiveStatus(string $status,$from,$until): string { if($status!=='published')return$status; $now=now(); if($from&&$now->lt($from))return'scheduled'; if($until&&$now->gt($until))return'expired'; return'active'; }
    private function generateCode(): string { do{$code='REC-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));}while(DB::table('HR_recruitments')->where('code',$code)->exists());return$code; }
    private function decodeJson($value): array { if(is_array($value))return$value;if(!is_string($value)||trim($value)==='')return[];$d=json_decode($value,true);return is_array($d)?$d:[]; }
    private function emptyPage(): array { return ['items'=>[],'pagination'=>['current_page'=>1,'last_page'=>1,'per_page'=>25,'total'=>0]]; }
}
