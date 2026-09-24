<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FinanceGeneralPostingService
{
    private const RESERVED_SOURCE_CODES = [
        'COGS','PURCHASING','PAYROLL','RECONCILIATION','SETTLEMENT','MANUAL','MANUAL_JOURNAL',
    ];

    private const DRAFT_ONLY_SOURCE_CODES = [
        'PUR_REALIZATION',
    ];

    private const AUTO_TEMPLATE_CODE = 'SYS-GENERAL-AUTO-SNAPSHOT';
    private const AUTO_TEMPLATE_KEY = 'GENERAL_AUTO_SNAPSHOT';

    public function __construct(
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceJournalService $journalService,
        private readonly FinanceScopeResolver $scopeResolver,
    ) {}

    public function options(array $allowedOutletIds, bool $canCorporate): array
    {
        $ids=$this->normalizeIds($allowedOutletIds);
        $outlets=DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m',function($j):void{$j->on('m.outlet_id','=','o.id')->where('m.is_active',true);})
            ->when($ids,fn($q)=>$q->whereIn('o.id',$ids),fn($q)=>$q->whereRaw('1=0'))
            ->where('o.is_active',true)->orderBy('o.name')
            ->get(['o.id','o.code','o.name','m.company_code'])
            ->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)($r->code??''),'name'=>(string)$r->name,'company_code'=>$r->company_code?(string)$r->company_code:null])->all();

        $templates=DB::table('finance_posting_templates as t')
            ->leftJoin('outlets as o','o.id','=','t.outlet_id')
            ->where('t.source_type','GENERAL')->where('t.is_active',true)->whereNull('t.deleted_at')
            ->when(DB::getSchemaBuilder()->hasColumn('finance_posting_templates','manual_selectable'),fn($q)=>$q->where('t.manual_selectable',true))
            ->where(function($q)use($ids,$canCorporate):void{
                if($ids)$q->whereIn('t.outlet_id',$ids)->orWhereNull('t.outlet_id');
                elseif($canCorporate)$q->whereNull('t.outlet_id');
                else $q->whereRaw('1=0');
            })
            ->orderBy('t.code')->get(['t.id','t.code','t.name','t.company_code','t.outlet_id','t.marking','o.name as outlet_name'])
            ->map(fn($r)=>(array)$r)->all();

        return [
            'companies'=>$this->scopeResolver->companies(),
            'outlets'=>$outlets,
            'markings'=>FinanceScopeResolver::MARKINGS,
            'templates'=>$templates,
            'can_corporate'=>$canCorporate,
            'reserved_source_codes'=>self::RESERVED_SOURCE_CODES,
            'context_tokens'=>['amount','subtotal','tax','discount','rounding','mdr','admin_fee','payable'],
        ];
    }

    public function paginate(array $filters,array $allowedOutletIds,bool $canCorporate): array
    {
        $ids=$this->normalizeIds($allowedOutletIds);
        $q=DB::table('finance_general_postings as g')
            ->leftJoin('outlets as o','o.id','=','g.outlet_id')
            ->leftJoin('finance_posting_templates as t','t.id','=','g.template_id')
            ->where(function($x)use($ids,$canCorporate):void{
                if($ids)$x->whereIn('g.outlet_id',$ids);
                if($canCorporate){
                    if($ids)$x->orWhereNull('g.outlet_id'); else $x->whereNull('g.outlet_id');
                }elseif(!$ids)$x->whereRaw('1=0');
            })
            ->when($filters['date_from']??null,fn($q,$v)=>$q->where('g.business_date','>=',$v))
            ->when($filters['date_to']??null,fn($q,$v)=>$q->where('g.business_date','<=',$v))
            ->when($filters['company_code']??null,fn($q,$v)=>$q->where('g.company_code',$v))
            ->when($filters['outlet_id']??null,fn($q,$v)=>$q->where('g.outlet_id',$v))
            ->when($filters['status']??null,fn($q,$v)=>$q->where('g.status',$v))
            ->when($filters['source_code']??null,fn($q,$v)=>$q->where('g.source_code',strtoupper((string)$v)))
            ->when(trim((string)($filters['q']??''))!=='',function($q)use($filters):void{
                $s=trim((string)$filters['q']);$q->where(fn($w)=>$w->where('g.posting_no','like',"%{$s}%")->orWhere('g.reference_no','like',"%{$s}%")->orWhere('g.source_code','like',"%{$s}%")->orWhere('g.description','like',"%{$s}%"));
            })
            ->orderByRaw("CASE g.status WHEN 'DRAFT' THEN 0 WHEN 'POSTED' THEN 1 ELSE 2 END")->orderByDesc('g.business_date')->orderByDesc('g.created_at')
            ->select(['g.*','o.code as outlet_code','o.name as outlet_name','t.code as template_code','t.name as template_name']);
        $p=$q->paginate(min(100,max(10,(int)($filters['per_page']??30))));
        return [
            'items'=>collect($p->items())->map(fn($r)=>$this->decorate((array)$r))->all(),
            'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()],
        ];
    }

    public function controlSummary(array $allowedOutletIds, bool $canCorporate): array
    {
        $ids = $this->normalizeIds($allowedOutletIds);
        $scope = function ($q, string $alias) use ($ids, $canCorporate): void {
            $q->where(function ($w) use ($ids, $canCorporate, $alias): void {
                if ($ids) $w->whereIn($alias.'.outlet_id', $ids);
                if ($canCorporate) {
                    if ($ids) $w->orWhereNull($alias.'.outlet_id'); else $w->whereNull($alias.'.outlet_id');
                } elseif (! $ids) $w->whereRaw('1=0');
            });
        };

        $base = DB::table('finance_general_postings as g');
        $scope($base, 'g');

        $sources = (clone $base)->select('g.source_code', DB::raw('COUNT(*) as total'))
            ->groupBy('g.source_code')->orderByDesc('total')->get()
            ->map(fn ($r) => ['source_code' => (string) $r->source_code, 'total' => (int) $r->total])->all();

        $orphanJournals = 0;
        $orphanOriginals = 0;
        $unlinkedReversals = 0;
        $orphanSample = [];
        $reportEligibleJournals = 0;
        if (DB::getSchemaBuilder()->hasTable('finance_journal_entries') && DB::getSchemaBuilder()->hasTable('finance_general_posting_journals')) {
            $orphanBase = DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->whereIn('j.status', ['POSTED', 'REVERSED'])
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('l.id');
            $scope($orphanBase, 'j');
            $orphanOriginals = (int) (clone $orphanBase)->count();
            $orphanSample = (clone $orphanBase)
                ->orderBy('j.journal_date')->orderBy('j.journal_no')->limit(10)
                ->get(['j.id','j.journal_no','j.journal_date','j.business_date','j.status','j.source_type','j.reference_no'])
                ->map(fn ($r) => ['detach_type'=>'ORIGINAL'] + (array) $r)->all();

            $reversalBase = DB::table('finance_journal_entries as r')
                ->join('finance_journal_entries as o', 'o.id', '=', 'r.reversal_of_journal_id')
                ->join('finance_general_posting_journals as gl', 'gl.journal_entry_id', '=', 'o.id')
                ->leftJoin('finance_general_posting_journals as rl', 'rl.reversal_journal_id', '=', 'r.id')
                ->where('r.status', 'POSTED')
                ->whereNotNull('r.reversal_of_journal_id')
                ->whereNull('rl.id');
            $scope($reversalBase, 'r');
            $unlinkedReversals = (int) (clone $reversalBase)->distinct()->count('r.id');
            if (count($orphanSample) < 10 && $unlinkedReversals > 0) {
                $remaining = 10 - count($orphanSample);
                $reversalSample = (clone $reversalBase)
                    ->orderBy('r.journal_date')->orderBy('r.journal_no')->limit($remaining)
                    ->get(['r.id','r.journal_no','r.journal_date','r.business_date','r.status','r.source_type','r.reference_no'])
                    ->map(fn ($r) => ['detach_type'=>'REVERSAL_LINK'] + (array) $r)->all();
                $orphanSample = array_merge($orphanSample, $reversalSample);
            }
            $orphanJournals = $orphanOriginals + $unlinkedReversals;

            $eligible = DB::table('finance_journal_entries as j')
                ->join('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->join('finance_general_postings as g', 'g.id', '=', 'l.general_posting_id')
                ->where('j.status', 'POSTED')
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('j.reversal_journal_id')
                ->where('g.status', 'POSTED')
                ->whereColumn('l.posting_version', 'g.posting_version')
                ->whereNull('l.reversal_journal_id');
            $scope($eligible, 'j');
            $reportEligibleJournals = (int) $eligible->distinct()->count('j.id');
        }

        return [
            'total' => (int) (clone $base)->count(),
            'draft' => (int) (clone $base)->where('g.status', 'DRAFT')->count(),
            'posted' => (int) (clone $base)->where('g.status', 'POSTED')->count(),
            'orphan_gl_journals' => $orphanJournals,
            'orphan_original_journals' => $orphanOriginals,
            'unlinked_reversal_journals' => $unlinkedReversals,
            'orphan_gl_sample' => $orphanSample,
            'report_eligible_journals' => $reportEligibleJournals,
            'single_source_enforced' => true,
            'reconcile_needed' => $orphanJournals > 0,
            'sources' => $sources,
            'reconcile_command' => 'php artisan erp-v5:finance-unified-posting-f04-reconcile',
        ];
    }

    public function correctDraft(string $id, array $data, ?string $userId): void
    {
        DB::transaction(function () use ($id, $data, $userId): void {
            $g = $this->posting($id, true);
            if ((string) $g->status !== 'DRAFT') throw new InvalidArgumentException('Unpost General Posting terlebih dahulu sebelum koreksi.');
            if (! $this->isSnapshot($g)) throw new InvalidArgumentException('Koreksi line-level digunakan untuk posting snapshot AUTO/MANUAL. Posting template dapat diedit dari form draft.');
            if (! $g->reopened_at && ! DB::table('finance_general_posting_journals')->where('general_posting_id', $id)->exists()) {
                throw new InvalidArgumentException('Koreksi snapshot hanya tersedia setelah General Posting pernah dipost/unpost.');
            }

            $lines = $this->snapshotLines((array) ($data['lines'] ?? []));
            $meta = $this->decode($g->metadata);
            $history = is_array($meta['corrections'] ?? null) ? $meta['corrections'] : [];
            $history[] = [
                'reason' => trim((string) ($data['reason'] ?? '')),
                'user_id' => $userId,
                'at' => now()->toIso8601String(),
                'previous_fingerprint' => (string) $g->source_fingerprint,
            ];
            $meta['snapshot_lines'] = $lines;
            $meta['manual_correction'] = true;
            $meta['corrections'] = array_slice($history, -50);

            $businessDate = (string) ($data['business_date'] ?? $g->business_date);
            $journalDate = (string) ($data['journal_date'] ?? $g->journal_date);
            $description = trim((string) ($data['description'] ?? $g->description));
            $reference = $this->nullable($data['reference_no'] ?? $g->reference_no);
            $amount = round((float) collect($lines)->sum('debit'), 2);

            DB::table('finance_general_postings')->where('id', $id)->update([
                'business_date' => $businessDate,
                'journal_date' => $journalDate,
                'description' => $description !== '' ? $description : (string) $g->description,
                'reference_no' => $reference,
                'amount' => $amount,
                'subtotal' => $amount,
                'payable' => $amount,
                'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            $fresh = $this->posting($id);
            $template = $this->generalTemplate((string) $fresh->template_id, (string) $fresh->company_code, $fresh->outlet_id ? (string) $fresh->outlet_id : null, (string) $fresh->marking);
            DB::table('finance_general_postings')->where('id', $id)->update([
                'source_fingerprint' => $this->fingerprint($fresh, $template),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function save(array $data,?string $userId,?string $id=null): string
    {
        return DB::transaction(function()use($data,$userId,$id):string{
            $existing=$id?$this->posting($id,true):null;
            if($existing&&$existing->status!=='DRAFT')throw new InvalidArgumentException('Hanya General Posting DRAFT yang dapat diedit.');
            if($existing&&$this->isSnapshot($existing))throw new InvalidArgumentException('General Posting AUTO tidak dapat diedit manual. Unpost lalu hapus draft, atau jalankan ulang transaksi sumber.');

            $company=strtoupper(trim((string)($data['company_code']??'')));
            $outlet=$this->nullable($data['outlet_id']??null);
            $scope=$this->scopeResolver->resolve($company?:null,$outlet);
            $marking=$this->marking($data['marking']??null);
            $sourceCode=$this->sourceCode($data['source_code']??null);
            $template=$this->generalTemplate((string)$data['template_id'],$scope['company_code'],$outlet,$marking);
            $sourceKey=$this->nullable($data['source_key']??null);
            if(!$sourceKey)$sourceKey='GEN-'.strtoupper((string)Str::ulid());
            if(strlen($sourceKey)>150)throw new InvalidArgumentException('Source key maksimal 150 karakter.');
            if($existing&&DB::table('finance_general_posting_journals')->where('general_posting_id',$existing->id)->exists()&&(string)$existing->source_key!==$sourceKey)throw new InvalidArgumentException('Source key tidak dapat diubah setelah General Posting pernah mempunyai journal history.');
            $duplicate=DB::table('finance_general_postings')->where('source_key',$sourceKey)->when($id,fn($q)=>$q->where('id','<>',$id))->exists();
            if($duplicate)throw new InvalidArgumentException('Source key General Posting sudah digunakan.');

            $context=$this->context($data);
            $description=trim((string)($data['description']??''));if($description==='')throw new InvalidArgumentException('Deskripsi wajib diisi.');
            $businessDate=(string)$data['business_date'];$journalDate=(string)($data['journal_date']??$businessDate);
            $rowId=(string)($existing->id??Str::ulid());
            $metadata=(array)($data['metadata']??[]);
            $metadata['posting_origin']='MANUAL';$metadata['posting_mode']='TEMPLATE';
            $payload=[
                'source_key'=>$sourceKey,'source_code'=>$sourceCode,'reference_no'=>$this->nullable($data['reference_no']??null),
                'company_code'=>$scope['company_code'],'outlet_id'=>$outlet,'marking'=>$marking,'template_id'=>$template->id,
                'business_date'=>$businessDate,'journal_date'=>$journalDate,'description'=>$description,
                'amount'=>$context['amount'],'subtotal'=>$context['subtotal'],'tax'=>$context['tax'],'discount'=>$context['discount'],
                'rounding'=>$context['rounding'],'mdr'=>$context['mdr'],'admin_fee'=>$context['admin_fee'],'payable'=>$context['payable'],
                'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ];
            if($existing)DB::table('finance_general_postings')->where('id',$rowId)->update($payload);
            else DB::table('finance_general_postings')->insert($payload+[
                'id'=>$rowId,'posting_no'=>$this->postingNo($businessDate,$rowId),'status'=>'DRAFT','posting_version'=>0,'source_fingerprint'=>'PENDING',
                'created_by_user_id'=>$userId,'created_at'=>now(),
            ]);
            $current=DB::table('finance_general_postings')->where('id',$rowId)->first();
            DB::table('finance_general_postings')->where('id',$rowId)->update(['source_fingerprint'=>$this->fingerprint($current,$template),'updated_at'=>now()]);
            return $rowId;
        });
    }

    /**
     * Universal AUTO posting envelope. All auto modules must stage here first and only
     * FinanceGeneralPostingService may create the GL journal for that staged document.
     */
    public function stageSystem(array $data,array $lines,?string $userId,bool $autoPost=true): array
    {
        $id=DB::transaction(function()use($data,$lines,$userId):string{
            $sourceKey=trim((string)($data['source_key']??''));
            if($sourceKey===''||strlen($sourceKey)>150)throw new InvalidArgumentException('AUTO General Posting membutuhkan source_key maksimal 150 karakter.');
            $existing=DB::table('finance_general_postings')->where('source_key',$sourceKey)->lockForUpdate()->first();
            if($existing&&$existing->status==='POSTED')return (string)$existing->id;

            $company=strtoupper(trim((string)($data['company_code']??'')));
            $outlet=$this->nullable($data['outlet_id']??null);
            $scope=$this->scopeResolver->resolve($company?:null,$outlet);
            $marking=$this->marking($data['marking']??'MARKING');
            $sourceCode=$this->systemSourceCode($data['source_code']??null);
            $template=$this->systemTemplate();
            $normalizedLines=$this->snapshotLines($lines);
            $amount=round((float)collect($normalizedLines)->sum('debit'),2);
            if($amount<=0)throw new InvalidArgumentException('AUTO General Posting harus mempunyai total debit lebih besar dari 0.');

            $description=trim((string)($data['description']??''));if($description==='')throw new InvalidArgumentException('Deskripsi AUTO General Posting wajib diisi.');
            $businessDate=(string)($data['business_date']??now('Asia/Jakarta')->toDateString());
            $journalDate=(string)($data['journal_date']??$businessDate);
            $metadata=(array)($data['metadata']??[]);
            $metadata['posting_origin']='AUTO';
            $metadata['posting_mode']='SNAPSHOT';
            $metadata['source_module']=strtoupper(trim((string)($data['source_module']??$sourceCode)));
            $metadata['source_identity']=$this->nullable($data['source_identity']??null);
            $metadata['snapshot_lines']=$normalizedLines;
            $metadata['unified_posting_version']='F01';

            $rowId=(string)($existing->id??Str::ulid());
            $payload=[
                'source_key'=>$sourceKey,'source_code'=>$sourceCode,'reference_no'=>$this->nullable($data['reference_no']??null),
                'company_code'=>$scope['company_code'],'outlet_id'=>$outlet,'marking'=>$marking,'template_id'=>$template->id,
                'business_date'=>$businessDate,'journal_date'=>$journalDate,'description'=>$description,
                'amount'=>$amount,'subtotal'=>round((float)($data['subtotal']??$amount),2),'tax'=>round((float)($data['tax']??0),2),
                'discount'=>round((float)($data['discount']??0),2),'rounding'=>round((float)($data['rounding']??0),2),
                'mdr'=>round((float)($data['mdr']??0),2),'admin_fee'=>round((float)($data['admin_fee']??0),2),'payable'=>round((float)($data['payable']??$amount),2),
                'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ];
            if($existing){
                if($existing->status!=='DRAFT')throw new InvalidArgumentException('AUTO General Posting existing tidak berada pada status DRAFT/POSTED.');
                DB::table('finance_general_postings')->where('id',$rowId)->update($payload);
            }else{
                DB::table('finance_general_postings')->insert($payload+[
                    'id'=>$rowId,'posting_no'=>$this->postingNo($businessDate,$rowId),'status'=>'DRAFT','posting_version'=>0,'source_fingerprint'=>'PENDING',
                    'created_by_user_id'=>$userId,'created_at'=>now(),
                ]);
            }
            $current=DB::table('finance_general_postings')->where('id',$rowId)->first();
            DB::table('finance_general_postings')->where('id',$rowId)->update(['source_fingerprint'=>$this->fingerprint($current,$template),'updated_at'=>now()]);
            return $rowId;
        },3);

        if(!$autoPost){return ['general_posting_id'=>$id,'status'=>(string)DB::table('finance_general_postings')->where('id',$id)->value('status')];}
        $posted=$this->postOne($id,$userId);
        return ['general_posting_id'=>$id]+$posted;
    }

    /** Adopt a legacy direct-to-GL journal without creating a duplicate GL entry. */
    public function adoptExistingJournal(string $journalId,?string $userId=null): array
    {
        return DB::transaction(function()use($journalId,$userId):array{
            $already=DB::table('finance_general_posting_journals')->where('journal_entry_id',$journalId)->first();
            if($already)return ['general_posting_id'=>(string)$already->general_posting_id,'journal_entry_id'=>$journalId,'idempotent'=>true];
            $j=DB::table('finance_journal_entries')->where('id',$journalId)->lockForUpdate()->first();
            if(!$j)throw new InvalidArgumentException('Journal legacy tidak ditemukan.');
            if((string)$j->status!=='POSTED')throw new InvalidArgumentException('Hanya journal legacy POSTED yang dapat diadopsi.');
            if($j->reversal_of_journal_id)throw new InvalidArgumentException('Journal reversal tidak diadopsi sebagai General Posting utama.');

            $lines=DB::table('finance_journal_entry_lines')->where('journal_entry_id',$journalId)->orderBy('line_no')->get(['account_id','debit','credit','description'])->map(fn($r)=>(array)$r)->all();
            $normalized=$this->snapshotLines($lines);
            $template=$this->systemTemplate();
            $sourceCode=$this->systemSourceCode((string)($j->source_type?:'LEGACY'));
            $sourceKey='LEGACY-JOURNAL:'.$journalId;
            $metadata=[
                'posting_origin'=>'AUTO','posting_mode'=>'SNAPSHOT','source_module'=>$sourceCode,'source_identity'=>(string)($j->source_id??''),
                'snapshot_lines'=>$normalized,'unified_posting_version'=>'F01','legacy_adopted'=>true,
                'legacy_source_type'=>(string)$j->source_type,'legacy_source_key'=>(string)($j->source_key??''),'legacy_source_meta'=>$this->decode($j->source_meta),
            ];
            $id=(string)Str::ulid();$amount=round((float)$j->total_debit,2);$date=(string)$j->business_date;
            DB::table('finance_general_postings')->insert([
                'id'=>$id,'posting_no'=>$this->postingNo($date,$id),'source_key'=>$sourceKey,'source_code'=>$sourceCode,
                'reference_no'=>$j->reference_no,'company_code'=>$j->company_code,'outlet_id'=>$j->outlet_id,'marking'=>$j->marking,'template_id'=>$template->id,
                'business_date'=>$date,'journal_date'=>(string)$j->journal_date,'description'=>(string)($j->description?:'Legacy '.$j->journal_no),
                'amount'=>$amount,'subtotal'=>$amount,'tax'=>0,'discount'=>0,'rounding'=>0,'mdr'=>0,'admin_fee'=>0,'payable'=>$amount,
                'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'source_fingerprint'=>'PENDING','status'=>'POSTED','posting_version'=>1,
                'posted_at'=>$j->posted_at ?: now(),'posted_by_user_id'=>$j->posted_by_user_id ?: $userId,
                'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $g=DB::table('finance_general_postings')->where('id',$id)->first();
            DB::table('finance_general_postings')->where('id',$id)->update(['source_fingerprint'=>$this->fingerprint($g,$template),'updated_at'=>now()]);
            DB::table('finance_general_posting_journals')->insert([
                'id'=>(string)Str::ulid(),'general_posting_id'=>$id,'posting_version'=>1,'journal_entry_id'=>$journalId,'journal_no'=>(string)$j->journal_no,
                'posted_at'=>$j->posted_at ?: now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            return ['general_posting_id'=>$id,'journal_entry_id'=>$journalId,'journal_no'=>(string)$j->journal_no,'idempotent'=>false];
        },3);
    }

    public function show(string $id): array
    {
        $g=DB::table('finance_general_postings as g')->leftJoin('outlets as o','o.id','=','g.outlet_id')->leftJoin('finance_posting_templates as t','t.id','=','g.template_id')
            ->where('g.id',$id)->first(['g.*','o.code as outlet_code','o.name as outlet_name','t.code as template_code','t.name as template_name']);
        if(!$g)throw new InvalidArgumentException('General Posting tidak ditemukan.');
        $result=$this->decorate((array)$g);$result['preview']=null;$result['preview_error']=null;
        try{$result['preview']=$this->previewOne($id);}catch(Throwable $e){$result['preview_error']=$e->getMessage();}
        $result['journals']=DB::table('finance_general_posting_journals')->where('general_posting_id',$id)->orderByDesc('posting_version')->get()->map(fn($r)=>(array)$r)->all();
        return $result;
    }

    public function refresh(string $id,?string $userId): void
    {
        DB::transaction(function()use($id,$userId):void{
            $g=$this->posting($id,true);if($g->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat direfresh.');
            $template=$this->generalTemplate((string)$g->template_id,(string)$g->company_code,$g->outlet_id?(string)$g->outlet_id:null,(string)$g->marking);
            DB::table('finance_general_postings')->where('id',$id)->update(['source_fingerprint'=>$this->fingerprint($g,$template),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
        });
    }

    public function previewOne(string $id): array
    {
        $g=$this->posting($id);$template=$this->generalTemplate((string)$g->template_id,(string)$g->company_code,$g->outlet_id?(string)$g->outlet_id:null,(string)$g->marking);
        if($this->isSnapshot($g)){
            $lines=$this->snapshotPreview($this->decode($g->metadata)['snapshot_lines']??[]);
            $debit=round((float)collect($lines)->sum('debit'),2);$credit=round((float)collect($lines)->sum('credit'),2);
            return ['id'=>(string)$g->id,'posting_no'=>(string)$g->posting_no,'source_key'=>(string)$g->source_key,'source_code'=>(string)$g->source_code,
                'business_date'=>(string)$g->business_date,'journal_date'=>(string)$g->journal_date,'company_code'=>(string)$g->company_code,
                'outlet_id'=>$g->outlet_id?(string)$g->outlet_id:null,'marking'=>(string)$g->marking,'template'=>['id'=>(string)$template->id,'code'=>(string)$template->code,'name'=>(string)$template->name],
                'lines'=>$lines,'total_debit'=>$debit,'total_credit'=>$credit,'balanced'=>abs($debit-$credit)<=0.01,'posting_origin'=>'AUTO','posting_mode'=>'SNAPSHOT'];
        }
        $preview=$this->templateEngine->preview((string)$template->id,'GENERAL',$this->templateContext($g));
        return [
            'id'=>(string)$g->id,'posting_no'=>(string)$g->posting_no,'source_key'=>(string)$g->source_key,'source_code'=>(string)$g->source_code,
            'business_date'=>(string)$g->business_date,'journal_date'=>(string)$g->journal_date,'company_code'=>(string)$g->company_code,
            'outlet_id'=>$g->outlet_id?(string)$g->outlet_id:null,'marking'=>(string)$g->marking,'template'=>$preview['template'],
            'lines'=>$preview['lines'],'total_debit'=>$preview['total_debit'],'total_credit'=>$preview['total_credit'],'balanced'=>$preview['balanced'],'posting_origin'=>'MANUAL','posting_mode'=>'TEMPLATE',
        ];
    }

    public function bulkPreview(array $ids): array
    {
        $result=[];foreach($this->limitedIds($ids) as $id){try{$result[]=['id'=>$id,'ok'=>true,'preview'=>$this->previewOne($id),'error'=>null];}catch(Throwable $e){$result[]=['id'=>$id,'ok'=>false,'preview'=>null,'error'=>$e->getMessage()];}}
        return ['items'=>$result,'success_count'=>collect($result)->where('ok',true)->count(),'failed_count'=>collect($result)->where('ok',false)->count()];
    }

    public function postOne(string $id,?string $userId): array
    {
        return DB::transaction(function()use($id,$userId):array{
            $g=$this->posting($id,true);
            if($g->status==='POSTED'){
                $link=DB::table('finance_general_posting_journals')->where('general_posting_id',$id)->where('posting_version',$g->posting_version)->first();
                return ['id'=>$id,'status'=>'POSTED','posting_version'=>(int)$g->posting_version,'journal_entry_id'=>$link?->journal_entry_id,'journal_no'=>$link?->journal_no,'idempotent'=>true];
            }
            if($g->status!=='DRAFT')throw new InvalidArgumentException('Hanya General Posting DRAFT yang dapat diposting.');
            if(in_array(strtoupper((string)$g->source_code),self::DRAFT_ONLY_SOURCE_CODES,true))throw new InvalidArgumentException('General Posting realisasi Purchasing adalah draft mapping/preview dan tidak boleh dipost manual. Posting liability dilakukan melalui flow Invoice/AP.');
            $template=$this->generalTemplate((string)$g->template_id,(string)$g->company_code,$g->outlet_id?(string)$g->outlet_id:null,(string)$g->marking);
            if(!hash_equals((string)$g->source_fingerprint,$this->fingerprint($g,$template)))throw new InvalidArgumentException('Source/Template General Posting berubah. Klik Refresh sebelum POST.');
            $preview=$this->previewOne($id);
            if(empty($preview['balanced']))throw new InvalidArgumentException('General Posting tidak balance.');
            $version=(int)$g->posting_version+1;$journalKey='GENERAL:'.$g->source_key.':V'.$version;if(strlen($journalKey)>191)throw new InvalidArgumentException('Journal source key terlalu panjang.');
            $journalId=$this->journalService->createDraft([
                'journal_date'=>(string)$g->journal_date,'business_date'=>(string)$g->business_date,'company_code'=>(string)$g->company_code,
                'outlet_id'=>$g->outlet_id?(string)$g->outlet_id:null,'marking'=>(string)$g->marking,'source_type'=>'GENERAL','source_id'=>(string)$g->id,
                'source_key'=>$journalKey,'reference_no'=>$g->reference_no?(string)$g->reference_no:(string)$g->posting_no,'description'=>(string)$g->description,
                'source_meta'=>['general_posting_id'=>(string)$g->id,'posting_no'=>(string)$g->posting_no,'source_key'=>(string)$g->source_key,'source_code'=>(string)$g->source_code,
                    'template_id'=>(string)$template->id,'template_code'=>(string)$template->code,'posting_version'=>$version,'metadata'=>$this->decode($g->metadata)],
            ],$preview['lines'],$userId);
            $this->journalService->post($journalId,$userId);$journalNo=(string)DB::table('finance_journal_entries')->where('id',$journalId)->value('journal_no');
            DB::table('finance_general_posting_journals')->insert(['id'=>(string)Str::ulid(),'general_posting_id'=>$id,'posting_version'=>$version,'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('finance_general_postings')->where('id',$id)->update(['status'=>'POSTED','posting_version'=>$version,'posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            return ['id'=>$id,'status'=>'POSTED','posting_version'=>$version,'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'idempotent'=>false];
        },3);
    }

    public function bulkPost(array $ids,?string $userId): array
    {
        $result=[];foreach($this->limitedIds($ids) as $id){try{$result[]=['id'=>$id,'ok'=>true,'result'=>$this->postOne($id,$userId),'error'=>null];}catch(Throwable $e){$result[]=['id'=>$id,'ok'=>false,'result'=>null,'error'=>$e->getMessage()];}}
        return ['items'=>$result,'success_count'=>collect($result)->where('ok',true)->count(),'failed_count'=>collect($result)->where('ok',false)->count()];
    }

    public function reopen(string $id,string $reason,?string $userId): void
    {
        DB::transaction(function()use($id,$reason,$userId):void{
            $g=$this->posting($id,true);if($g->status!=='POSTED')throw new InvalidArgumentException('Hanya General Posting POSTED yang dapat di-unpost.');
            $link=DB::table('finance_general_posting_journals')->where('general_posting_id',$id)->where('posting_version',$g->posting_version)->lockForUpdate()->first();if(!$link)throw new InvalidArgumentException('Journal history General Posting tidak ditemukan.');
            if($link->reversal_journal_id){DB::table('finance_general_postings')->where('id',$id)->update(['status'=>'DRAFT','posted_at'=>null,'posted_by_user_id'=>null,'updated_at'=>now()]);return;}
            $originalDate=(string)(DB::table('finance_journal_entries')->where('id',$link->journal_entry_id)->value('journal_date')?:$g->journal_date);
            $reversalId=$this->journalService->reverse((string)$link->journal_entry_id,$originalDate,$reason,$userId);$reversalNo=(string)DB::table('finance_journal_entries')->where('id',$reversalId)->value('journal_no');
            DB::table('finance_general_posting_journals')->where('id',$link->id)->update(['reversal_journal_id'=>$reversalId,'reversal_journal_no'=>$reversalNo,'reversed_at'=>now(),'updated_at'=>now()]);
            DB::table('finance_general_postings')->where('id',$id)->update(['status'=>'DRAFT','posted_at'=>null,'posted_by_user_id'=>null,'reopened_at'=>now(),'reopened_by_user_id'=>$userId,'reopen_reason'=>$reason,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
        },3);
    }

    public function destroyDraft(string $id): void
    {
        DB::transaction(function()use($id):void{
            $g=$this->posting($id,true);
            if($g->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat dihapus. Unpost terlebih dahulu bila masih POSTED.');
            $hasHistory=DB::table('finance_general_posting_journals')->where('general_posting_id',$id)->exists();
            if($hasHistory || (int)$g->posting_version > 0){
                throw new InvalidArgumentException('General Posting yang sudah memiliki histori GL tidak boleh dihapus karena akan memutus lineage accounting. Biarkan sebagai DRAFT setelah Unpost.');
            }
            DB::table('finance_general_postings')->where('id',$id)->delete();
        },3);
    }

    public function generalPostingByJournal(string $journalId): ?object
    {
        return DB::table('finance_general_posting_journals as l')->join('finance_general_postings as g','g.id','=','l.general_posting_id')->where('l.journal_entry_id',$journalId)->first(['g.*','l.id as link_id']);
    }

    private function generalTemplate(string $id,string $company,?string $outlet,string $marking): object
    {
        $t=DB::table('finance_posting_templates')->where('id',$id)->where('source_type','GENERAL')->where('is_active',true)->whereNull('deleted_at')->first();if(!$t)throw new InvalidArgumentException('Jurnal Template GENERAL tidak ditemukan/aktif.');
        if($t->company_code&&strtoupper((string)$t->company_code)!==$company)throw new InvalidArgumentException('Template GENERAL tidak sesuai PT.');if($t->outlet_id&&(string)$t->outlet_id!==(string)$outlet)throw new InvalidArgumentException('Template GENERAL tidak sesuai outlet.');if($t->marking&&strtoupper((string)$t->marking)!==$marking)throw new InvalidArgumentException('Template GENERAL tidak sesuai marking.');return $t;
    }

    private function systemTemplate(): object
    {
        $q=DB::table('finance_posting_templates')->where('source_type','GENERAL')->where('is_active',true)->whereNull('deleted_at');
        if(DB::getSchemaBuilder()->hasColumn('finance_posting_templates','system_key'))$q->where(fn($x)=>$x->where('system_key',self::AUTO_TEMPLATE_KEY)->orWhere('code',self::AUTO_TEMPLATE_CODE));else $q->where('code',self::AUTO_TEMPLATE_CODE);
        $t=$q->first();if(!$t)throw new InvalidArgumentException('System template General Posting AUTO belum tersedia. Jalankan migration Finance Unified Posting F01.');return $t;
    }

    private function fingerprint(object $g,object $template): string
    {
        $meta=$this->decode($g->metadata);
        $lines=$this->isSnapshot($g)?($meta['snapshot_lines']??[]):DB::table('finance_posting_template_lines')->where('template_id',$template->id)->orderBy('sort_order')->get(['account_id','side','amount_formula','memo_template','updated_at'])->map(fn($r)=>(array)$r)->all();
        return hash('sha256',json_encode(['source_key'=>$g->source_key,'source_code'=>$g->source_code,'reference_no'=>$g->reference_no,'company_code'=>$g->company_code,'outlet_id'=>$g->outlet_id,'marking'=>$g->marking,
            'business_date'=>$g->business_date,'journal_date'=>$g->journal_date,'description'=>$g->description,'amount'=>$g->amount,'subtotal'=>$g->subtotal,'tax'=>$g->tax,'discount'=>$g->discount,'rounding'=>$g->rounding,'mdr'=>$g->mdr,'admin_fee'=>$g->admin_fee,'payable'=>$g->payable,'metadata'=>$g->metadata,
            'template_id'=>$template->id,'template_updated_at'=>$template->updated_at,'posting_lines'=>$lines],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function snapshotLines(array $lines): array
    {
        $result=[];$debit=0.0;$credit=0.0;
        foreach(array_values($lines) as $index=>$line){$account=trim((string)($line['account_id']??''));if($account==='')throw new InvalidArgumentException('AUTO General Posting memiliki baris tanpa account_id.');$coa=DB::table('finance_chart_of_accounts')->where('id',$account)->where('is_active',true)->where('is_postable',true)->first(['id']);if(!$coa)throw new InvalidArgumentException('COA AUTO General Posting tidak aktif/postable.');$d=round((float)($line['debit']??0),2);$c=round((float)($line['credit']??0),2);if(($d>0&&$c>0)||($d<=0&&$c<=0))throw new InvalidArgumentException('Setiap baris AUTO General Posting harus tepat salah satu DEBIT/CREDIT.');$result[]=['account_id'=>$account,'debit'=>$d,'credit'=>$c,'description'=>trim((string)($line['description']??''))?:('Auto posting line '.($index+1))];$debit+=$d;$credit+=$c;}
        if(count($result)<2)throw new InvalidArgumentException('AUTO General Posting minimal mempunyai 2 baris.');$debit=round($debit,2);$credit=round($credit,2);if(abs($debit-$credit)>0.01)throw new InvalidArgumentException("AUTO General Posting tidak balance. Debit {$debit}, Credit {$credit}.");return $result;
    }

    private function snapshotPreview(array $lines): array
    {
        $normalized=$this->snapshotLines($lines);$accounts=DB::table('finance_chart_of_accounts')->whereIn('id',collect($normalized)->pluck('account_id'))->get(['id','code','name'])->keyBy('id');
        return collect($normalized)->map(function($l)use($accounts){$a=$accounts->get($l['account_id']);return $l+['account_code'=>(string)($a->code??''),'account_name'=>(string)($a->name??'')];})->all();
    }

    private function isSnapshot(object $g): bool{return strtoupper((string)($this->decode($g->metadata)['posting_mode']??''))==='SNAPSHOT';}
    private function decorate(array $row): array{$m=$this->decode($row['metadata']??null);$row['posting_origin']=strtoupper((string)($m['posting_origin']??'MANUAL'));$row['posting_mode']=strtoupper((string)($m['posting_mode']??'TEMPLATE'));$row['source_module']=$m['source_module']??$row['source_code']??null;$row['source_identity']=$m['source_identity']??null;$row['is_auto']=$row['posting_origin']==='AUTO';$row['is_snapshot']=$row['posting_mode']==='SNAPSHOT';$row['can_correct_snapshot']=($row['status']??null)==='DRAFT'&&$row['is_snapshot']&&!empty($row['reopened_at']);$row['can_delete_draft']=($row['status']??null)==='DRAFT'&&(int)($row['posting_version']??0)===0;$row['corrections']=$m['corrections']??[];return $row;}
    private function templateContext(object $g): array{return ['amount'=>(float)$g->amount,'subtotal'=>(float)$g->subtotal,'tax'=>(float)$g->tax,'discount'=>(float)$g->discount,'rounding'=>(float)$g->rounding,'mdr'=>(float)$g->mdr,'admin_fee'=>(float)$g->admin_fee,'payable'=>(float)$g->payable,'cogs'=>0,'payroll'=>0,'description'=>(string)$g->description,'reference_no'=>(string)($g->reference_no??''),'source_code'=>(string)$g->source_code,'company_code'=>(string)$g->company_code,'outlet_id'=>$g->outlet_id?(string)$g->outlet_id:null,'marking'=>(string)$g->marking];}
    private function context(array $data): array{$amount=round((float)($data['amount']??0),2);if($amount<=0)throw new InvalidArgumentException('Amount harus lebih besar dari 0.');$result=['amount'=>$amount];foreach(['subtotal','tax','discount','rounding','mdr','admin_fee','payable'] as $k)$result[$k]=round((float)($data[$k]??($k==='subtotal'||$k==='payable'?$amount:0)),2);return $result;}
    private function posting(string $id,bool $lock=false):object{$q=DB::table('finance_general_postings')->where('id',$id);if($lock)$q->lockForUpdate();$g=$q->first();if(!$g)throw new InvalidArgumentException('General Posting tidak ditemukan.');return $g;}
    private function postingNo(string $date,string $id):string{return 'GEN-'.str_replace('-','',$date).'-'.strtoupper(substr($id,-8));}
    private function sourceCode(mixed $v):string{$v=$this->systemSourceCode($v);if(in_array($v,self::RESERVED_SOURCE_CODES,true))throw new InvalidArgumentException("Source code {$v} adalah source khusus dan tidak dapat dibuat manual. Auto-generated source tetap akan tampil di General Posting.");return $v;}
    private function systemSourceCode(mixed $v):string{$v=strtoupper(trim((string)$v));if($v===''||!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/',$v))throw new InvalidArgumentException('Source code wajib 2-40 karakter A-Z/0-9/_/-.');return $v;}
    private function marking(mixed $v):string{$v=strtoupper(trim((string)$v));if(!in_array($v,FinanceScopeResolver::MARKINGS,true))throw new InvalidArgumentException('Marking harus MARKING atau UNMARKING.');return $v;}
    private function nullable(mixed $v):?string{$v=trim((string)($v??''));return $v===''?null:$v;}
    private function normalizeIds(array $ids):array{return array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$ids))));}
    private function limitedIds(array $ids):array{$ids=$this->normalizeIds($ids);if(!$ids)throw new InvalidArgumentException('Pilih minimal satu General Posting.');if(count($ids)>100)throw new InvalidArgumentException('Bulk maksimal 100 dokumen sekali proses.');return $ids;}
    private function decode(mixed $v):array{if(is_array($v))return $v;if(!is_string($v)||trim($v)==='')return [];$d=json_decode($v,true);return is_array($d)?$d:[];}
}
