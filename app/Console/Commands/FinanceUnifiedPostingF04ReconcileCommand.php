<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceGeneralPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class FinanceUnifiedPostingF04ReconcileCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f04-reconcile {--dry-run} {--limit=2000}';
    protected $description = 'Adopt/repair every legacy effective GL journal into General Posting without duplicate GL.';

    public function handle(FinanceGeneralPostingService $general): int
    {
        foreach ([
            'finance_journal_entries','finance_journal_entry_lines',
            'finance_general_postings','finance_general_posting_journals',
            'finance_posting_templates',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table {$table}. Apply Finance Unified Posting F01-F03 terlebih dahulu.");
                return self::FAILURE;
            }
        }

        $limit=max(1,min(10000,(int)$this->option('limit')));
        $posted=$this->orphanOriginals('POSTED',$limit);
        $remaining=max(0,$limit-$posted->count());
        $reversed=$remaining>0?$this->orphanOriginals('REVERSED',$remaining):collect();
        $remaining=max(0,$remaining-$reversed->count());
        $unlinkedReversals=$remaining>0?$this->unlinkedReversals($remaining):collect();

        $rows=$posted->map(fn($r)=>[
            'journal_no'=>$r->journal_no,'date'=>$r->journal_date,'status'=>'POSTED',
            'source'=>$r->source_type,'reference'=>$r->reference_no?:'-',
            'action'=>$this->postedAction($r),
        ])->merge($reversed->map(fn($r)=>[
            'journal_no'=>$r->journal_no,'date'=>$r->journal_date,'status'=>'REVERSED',
            'source'=>$r->source_type,'reference'=>$r->reference_no?:'-',
            'action'=>$this->reversedAction($r),
        ]))->merge($unlinkedReversals->map(fn($r)=>[
            'journal_no'=>$r->journal_no,'date'=>$r->journal_date,'status'=>'REVERSAL',
            'source'=>$r->source_type,'reference'=>$r->reference_no?:'-',
            'action'=>'REPAIR REVERSAL LINK',
        ]));

        $this->table(['Journal','Date','Status','Source','Reference','Action'],$rows->map(fn($r)=>array_values($r))->all());
        $this->info('F04 journal repairs: '.$rows->count().' (POSTED orphan '.$posted->count().', REVERSED orphan '.$reversed->count().', unlinked reversal '.$unlinkedReversals->count().').');

        if($this->option('dry-run'))return self::SUCCESS;

        $ok=0;$failed=0;
        foreach($posted as $row){
            try{
                $this->repairPosted($general,$row);
                $ok++;
            }catch(Throwable $e){
                $failed++;
                $this->warn($row->journal_no.' · '.$e->getMessage());
            }
        }
        foreach($reversed as $row){
            try{
                $this->repairReversed($row);
                $ok++;
            }catch(Throwable $e){
                $failed++;
                $this->warn($row->journal_no.' · '.$e->getMessage());
            }
        }
        foreach($unlinkedReversals as $row){
            try{
                $this->repairUnlinkedReversal($row);
                $ok++;
            }catch(Throwable $e){
                $failed++;
                $this->warn($row->journal_no.' · '.$e->getMessage());
            }
        }

        $this->info("Repaired/adopted {$ok}; failed {$failed}.");
        return $failed===0?self::SUCCESS:self::FAILURE;
    }

    private function orphanOriginals(string $status,int $limit)
    {
        return DB::table('finance_journal_entries as j')
            ->leftJoin('finance_general_posting_journals as gpj','gpj.journal_entry_id','=','j.id')
            ->where('j.status',$status)
            ->whereNull('j.reversal_of_journal_id')
            ->whereNull('gpj.id')
            ->orderBy('j.journal_date')->orderBy('j.journal_no')
            ->limit($limit)
            ->get([
                'j.id','j.journal_no','j.journal_date','j.business_date','j.company_code','j.outlet_id',
                'j.marking','j.source_type','j.source_id','j.source_key','j.reference_no','j.description',
                'j.total_debit','j.total_credit','j.source_meta','j.posted_at','j.posted_by_user_id',
                'j.reversal_journal_id','j.reversed_at','j.reversed_by_user_id',
            ]);
    }

    private function unlinkedReversals(int $limit)
    {
        return DB::table('finance_journal_entries as r')
            ->join('finance_journal_entries as o','o.id','=','r.reversal_of_journal_id')
            ->join('finance_general_posting_journals as g','g.journal_entry_id','=','o.id')
            ->leftJoin('finance_general_posting_journals as rg','rg.reversal_journal_id','=','r.id')
            ->where('r.status','POSTED')
            ->whereNotNull('r.reversal_of_journal_id')
            ->whereNull('rg.id')
            ->orderBy('r.journal_date')->orderBy('r.journal_no')
            ->limit($limit)
            ->get([
                'r.id','r.journal_no','r.journal_date','r.source_type','r.reference_no','r.total_debit',
                'r.reversal_of_journal_id','r.posted_at',
                'g.id as gp_link_id','g.general_posting_id','g.journal_entry_id as original_journal_id',
            ]);
    }

    private function repairUnlinkedReversal(object $row): void
    {
        DB::transaction(function()use($row):void{
            $original=DB::table('finance_journal_entries')->where('id',$row->original_journal_id)->first();
            if(!$original)throw new \RuntimeException('Original journal untuk reversal tidak ditemukan.');

            DB::table('finance_general_posting_journals')->where('id',$row->gp_link_id)->update([
                'reversal_journal_id'=>(string)$row->id,
                'reversal_journal_no'=>(string)$row->journal_no,
                'reversed_at'=>$original->reversed_at?:$row->posted_at?:now(),
                'updated_at'=>now(),
            ]);

            DB::table('finance_general_postings')->where('id',$row->general_posting_id)->update([
                'status'=>'DRAFT','posted_at'=>null,'posted_by_user_id'=>null,
                'reopened_at'=>$original->reversed_at?:now(),
                'reopened_by_user_id'=>$original->reversed_by_user_id,
                'reopen_reason'=>'F04 repaired historical direct reversal link',
                'updated_at'=>now(),
            ]);
        },3);
    }

    private function postedAction(object $row): string
    {
        $meta=$this->decode($row->source_meta);
        if(strtoupper((string)$row->source_type)==='GENERAL'&&!empty($meta['general_posting_id'])&&DB::table('finance_general_postings')->where('id',$meta['general_posting_id'])->exists()){
            return 'REPAIR GP LINK';
        }
        return 'ADOPT TO GP';
    }

    private function reversedAction(object $row): string
    {
        $meta=$this->decode($row->source_meta);
        if(strtoupper((string)$row->source_type)==='GENERAL'&&!empty($meta['general_posting_id'])&&DB::table('finance_general_postings')->where('id',$meta['general_posting_id'])->exists()){
            return 'REPAIR GP + REVERSAL LINK';
        }
        return 'ADOPT REVERSED PAIR AS GP DRAFT';
    }

    private function repairPosted(FinanceGeneralPostingService $general,object $row): void
    {
        $meta=$this->decode($row->source_meta);
        $gpId=(string)($meta['general_posting_id']??'');
        if(strtoupper((string)$row->source_type)==='GENERAL'&&$gpId!==''&&DB::table('finance_general_postings')->where('id',$gpId)->exists()){
            $this->repairLink($gpId,$row,false);
            return;
        }
        $general->adoptExistingJournal((string)$row->id,null);
    }

    private function repairReversed(object $row): void
    {
        if(!$row->reversal_journal_id){
            throw new \RuntimeException('Journal REVERSED tidak memiliki reversal_journal_id.');
        }
        $reversal=DB::table('finance_journal_entries')->where('id',$row->reversal_journal_id)->first();
        if(!$reversal||$reversal->status!=='POSTED'){
            throw new \RuntimeException('Reversal journal POSTED tidak ditemukan.');
        }

        $meta=$this->decode($row->source_meta);
        $gpId=(string)($meta['general_posting_id']??'');
        if(strtoupper((string)$row->source_type)==='GENERAL'&&$gpId!==''&&DB::table('finance_general_postings')->where('id',$gpId)->exists()){
            $this->repairLink($gpId,$row,true);
            DB::table('finance_general_postings')->where('id',$gpId)->update([
                'status'=>'DRAFT','posted_at'=>null,'posted_by_user_id'=>null,
                'reopened_at'=>$row->reversed_at?:now(),'reopened_by_user_id'=>$row->reversed_by_user_id,
                'reopen_reason'=>'F04 repaired historical reversed General Posting',
                'updated_at'=>now(),
            ]);
            return;
        }

        $template=DB::table('finance_posting_templates')
            ->where('code','SYS-GENERAL-AUTO-SNAPSHOT')->where('source_type','GENERAL')
            ->where('is_active',true)->whereNull('deleted_at')->first();
        if(!$template)throw new \RuntimeException('System template Unified General Posting tidak ditemukan.');

        $lines=DB::table('finance_journal_entry_lines')->where('journal_entry_id',$row->id)->orderBy('line_no')
            ->get(['account_id','debit','credit','description'])
            ->map(fn($l)=>[
                'account_id'=>(string)$l->account_id,
                'debit'=>(float)$l->debit,'credit'=>(float)$l->credit,
                'description'=>$l->description?(string)$l->description:null,
            ])->all();

        $id=(string)Str::ulid();
        $businessDate=(string)($row->business_date?:$row->journal_date);
        $metadata=[
            'posting_origin'=>'AUTO','posting_mode'=>'SNAPSHOT',
            'source_module'=>strtoupper((string)($row->source_type?:'LEGACY')),
            'source_identity'=>$row->source_id?(string)$row->source_id:(string)$row->id,
            'snapshot_lines'=>$lines,'unified_posting_version'=>'F04',
            'legacy_adopted'=>true,'historical_reversed'=>true,
            'legacy_source_type'=>(string)$row->source_type,
            'legacy_source_key'=>(string)($row->source_key??''),
        ];

        DB::transaction(function()use($row,$template,$id,$businessDate,$metadata):void{
            DB::table('finance_general_postings')->insert([
                'id'=>$id,
                'posting_no'=>'GEN-'.str_replace('-','',$businessDate).'-'.strtoupper(substr($id,-8)),
                'source_key'=>'LEGACY-REVERSED-JOURNAL:'.$row->id,
                'source_code'=>strtoupper((string)($row->source_type?:'LEGACY')),
                'reference_no'=>$row->reference_no,
                'company_code'=>$row->company_code,'outlet_id'=>$row->outlet_id,'marking'=>$row->marking,
                'template_id'=>$template->id,'business_date'=>$businessDate,'journal_date'=>$row->journal_date,
                'description'=>$row->description?:('Historical reversed journal '.$row->journal_no),
                'amount'=>(float)$row->total_debit,'subtotal'=>(float)$row->total_debit,
                'tax'=>0,'discount'=>0,'rounding'=>0,'mdr'=>0,'admin_fee'=>0,'payable'=>(float)$row->total_debit,
                'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'source_fingerprint'=>'F04-HISTORICAL-REVERSED-'.hash('sha256',(string)$row->id),
                'status'=>'DRAFT','posting_version'=>1,
                'posted_at'=>null,'posted_by_user_id'=>null,
                'reopened_at'=>$row->reversed_at?:now(),'reopened_by_user_id'=>$row->reversed_by_user_id,
                'reopen_reason'=>'F04 adopted historical reversed direct-to-GL journal',
                'created_by_user_id'=>$row->posted_by_user_id,'updated_by_user_id'=>$row->reversed_by_user_id,
                'created_at'=>$row->posted_at?:now(),'updated_at'=>now(),
            ]);
            $this->repairLink($id,$row,true);
        },3);
    }

    private function repairLink(string $gpId,object $row,bool $withReversal): void
    {
        if(DB::table('finance_general_posting_journals')->where('journal_entry_id',$row->id)->exists())return;

        $gp=DB::table('finance_general_postings')->where('id',$gpId)->first();
        if(!$gp)throw new \RuntimeException('General Posting target tidak ditemukan.');

        $meta=$this->decode($row->source_meta);
        $version=(int)($meta['posting_version']??$gp->posting_version??1);
        if($version<1)$version=1;

        $payload=[
            'id'=>(string)Str::ulid(),
            'general_posting_id'=>$gpId,
            'posting_version'=>$version,
            'journal_entry_id'=>(string)$row->id,
            'journal_no'=>(string)$row->journal_no,
            'posted_at'=>$row->posted_at?:now(),
            'created_at'=>now(),'updated_at'=>now(),
        ];
        if($withReversal){
            $rev=DB::table('finance_journal_entries')->where('id',$row->reversal_journal_id)->first();
            $payload['reversal_journal_id']=(string)$row->reversal_journal_id;
            $payload['reversal_journal_no']=$rev?->journal_no?(string)$rev->journal_no:null;
            $payload['reversed_at']=$row->reversed_at?:now();
        }
        DB::table('finance_general_posting_journals')->insert($payload);

        if(!$withReversal){
            DB::table('finance_general_postings')->where('id',$gpId)->update([
                'status'=>'POSTED','posting_version'=>max((int)$gp->posting_version,$version),
                'posted_at'=>$row->posted_at?:now(),'posted_by_user_id'=>$row->posted_by_user_id,
                'updated_at'=>now(),
            ]);
        }
    }

    private function decode(mixed $value): array
    {
        if(is_array($value))return $value;
        if(!is_string($value)||trim($value)==='')return [];
        $decoded=json_decode($value,true);
        return is_array($decoded)?$decoded:[];
    }
}
