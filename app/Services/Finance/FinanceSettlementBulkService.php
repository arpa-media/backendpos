<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceSettlementBulkService
{
    public function __construct(
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceGeneralPostingService $generalPosting,
    ) {}

    public function preview(array $sourceIds,string $settlementDate,?float $bankActual=null,?float $mdrActual=null,?float $adminActual=null): array
    {
        $ctx=$this->context($sourceIds,$settlementDate);
        $gross=$ctx['gross'];
        $mdr=$mdrActual===null?$ctx['default_mdr']:round($mdrActual,2);
        $admin=$adminActual===null?$ctx['default_admin']:round($adminActual,2);
        $bank=$bankActual===null?round($gross-$mdr-$admin,2):round($bankActual,2);
        if(abs(round($bank+$mdr+$admin-$gross,2))>0.005)throw new InvalidArgumentException('Bank Actual + MDR + Admin Fee harus sama dengan total Gross source yang dipilih.');
        if($bank<0||$mdr<0||$admin<0)throw new InvalidArgumentException('Nilai bulk settlement tidak boleh negatif.');

        $markTotals=collect($ctx['sources'])->groupBy('marking')->map(fn($g)=>round((float)$g->sum('available_amount'),2))->all();
        $alloc=$this->allocateTotals($gross,$markTotals,$bank,$mdr,$admin);
        $journals=[];
        foreach($alloc as $marking=>$totals){
            $lines=[];
            if($totals['bank']>0.005)$lines[]=['account_id'=>$ctx['bank_account_id'],'description'=>"Penerimaan Bulk {$ctx['payment_label']} {$marking}",'debit'=>$totals['bank'],'credit'=>0];
            if($totals['mdr']>0.005){if(!$ctx['mdr_account_id'])throw new InvalidArgumentException('COA MDR belum dimapping.');$lines[]=['account_id'=>$ctx['mdr_account_id'],'description'=>"MDR Bulk {$ctx['payment_label']} {$marking}",'debit'=>$totals['mdr'],'credit'=>0];}
            if($totals['admin']>0.005){if(!$ctx['admin_account_id'])throw new InvalidArgumentException('COA Admin Fee belum dimapping.');$lines[]=['account_id'=>$ctx['admin_account_id'],'description'=>"Admin Bulk {$ctx['payment_label']} {$marking}",'debit'=>$totals['admin'],'credit'=>0];}
            foreach(collect($ctx['sources'])->where('marking',$marking) as $s)$lines[]=['account_id'=>$s['clearing_account_id'],'description'=>"Clear {$s['payment_method_name']} · {$s['reconciliation_no']} · {$marking}",'debit'=>0,'credit'=>$s['available_amount']];
            $journals[]=['marking'=>$marking,'totals'=>$totals,'lines'=>$this->decorate($lines)];
        }
        return $ctx+['bank_received_amount'=>$bank,'mdr_amount'=>$mdr,'admin_fee_amount'=>$admin,'journals'=>$journals];
    }

    public function post(array $sourceIds,string $settlementDate,float $bank,float $mdr,float $admin,?string $userId): array
    {
        return DB::transaction(function()use($sourceIds,$settlementDate,$bank,$mdr,$admin,$userId):array{
            $preview=$this->preview($sourceIds,$settlementDate,$bank,$mdr,$admin);
            $ids=array_values(array_map(fn($s)=>(string)$s['id'],$preview['sources']));sort($ids,SORT_STRING);
            DB::table('finance_settlement_sources')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get(['id']);
            // Rebuild after lock, because another settlement may have consumed source while request waited.
            $preview=$this->preview($ids,$settlementDate,$bank,$mdr,$admin);
            $batchKey=hash('sha256',implode('|',$ids).'|'.$settlementDate.'|'.round($bank,2).'|'.round($mdr,2).'|'.round($admin,2));
            $existing=DB::table('finance_settlement_batches')->where('batch_key',$batchKey)->first();
            if($existing&&$existing->status==='POSTED')return ['id'=>(string)$existing->id,'batch_no'=>(string)$existing->batch_no,'status'=>'POSTED','idempotent'=>true];

            $id=(string)($existing->id??Str::ulid());$batchNo=(string)($existing->batch_no??('STLB-'.str_replace('-','',$settlementDate).'-'.strtoupper(substr($id,-8))));
            if(!$existing){
                DB::table('finance_settlement_batches')->insert([
                    'id'=>$id,'batch_key'=>$batchKey,'batch_no'=>$batchNo,'settlement_date'=>$settlementDate,'company_code'=>$preview['company_code'],'outlet_id'=>$preview['outlet_id'],
                    'payment_label'=>$preview['payment_label'],'bank_account_id'=>$preview['bank_account_id'],'mdr_expense_account_id'=>$preview['mdr_account_id'],'admin_fee_expense_account_id'=>$preview['admin_account_id'],
                    'clearing_amount'=>$preview['gross'],'bank_received_amount'=>$bank,'mdr_amount'=>$mdr,'admin_fee_amount'=>$admin,'status'=>'DRAFT','posting_version'=>0,
                    'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
                ]);
                foreach($preview['sources'] as $s)DB::table('finance_settlement_bulk_items')->insert([
                    'id'=>(string)Str::ulid(),'batch_id'=>$id,'settlement_source_id'=>$s['id'],'marking'=>$s['marking'],'payment_method_name'=>$s['payment_method_name'],'clearing_account_id'=>$s['clearing_account_id'],'clearing_amount'=>$s['available_amount'],'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $version=(int)($existing->posting_version??0)+1;$journalRefs=[];
            foreach($preview['journals'] as $j){
                $posted=$this->generalPosting->stageSystem([
                    'source_key'=>"SETTLEMENT_BULK:{$id}:V{$version}:{$j['marking']}",'source_code'=>'SETTLEMENT','source_module'=>'SETTLEMENT','source_identity'=>$id,
                    'reference_no'=>$batchNo,'journal_date'=>$settlementDate,'business_date'=>$preview['business_date'],'company_code'=>$preview['company_code'],'outlet_id'=>$preview['outlet_id'],'marking'=>$j['marking'],
                    'mdr'=>$j['totals']['mdr'],'admin_fee'=>$j['totals']['admin'],
                    'description'=>"Bulk Settlement {$batchNo} · {$preview['payment_label']} · {$j['marking']}",
                    'metadata'=>['producer_version'=>'F03','bulk'=>true,'batch_id'=>$id,'source_ids'=>$ids,'payment_label'=>$preview['payment_label'],'gross'=>$j['totals']['gross'],'bank_received'=>$j['totals']['bank'],'mdr'=>$j['totals']['mdr'],'admin_fee'=>$j['totals']['admin'],'posting_version'=>$version],
                ],$j['lines'],$userId,true);
                $journalId=(string)($posted['journal_entry_id']??'');$journalNo=(string)($posted['journal_no']??'');
                if($journalId===''||$journalNo==='')throw new InvalidArgumentException('General Posting Bulk Settlement gagal menghasilkan journal.');
                DB::table('finance_settlement_bulk_journals')->insert(['id'=>(string)Str::ulid(),'batch_id'=>$id,'posting_version'=>$version,'marking'=>$j['marking'],'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                $journalRefs[]=$journalNo;
            }
            DB::table('finance_settlement_batches')->where('id',$id)->update(['status'=>'POSTED','posting_version'=>$version,'posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            foreach($ids as $sid)$this->settlements->recalculateSource($sid);
            return ['id'=>$id,'batch_no'=>$batchNo,'status'=>'POSTED','journal_nos'=>$journalRefs,'idempotent'=>false];
        });
    }

    public function reopen(string $id,string $date,string $reason,?string $userId):array
    {
        return DB::transaction(function()use($id,$date,$reason,$userId):array{
            $b=DB::table('finance_settlement_batches')->where('id',$id)->lockForUpdate()->first();if(!$b)throw new InvalidArgumentException('Bulk Settlement tidak ditemukan.');
            if($b->status!=='POSTED')throw new InvalidArgumentException('Hanya Bulk Settlement POSTED yang dapat direset/reopen.');
            $rows=DB::table('finance_settlement_bulk_journals')->where('batch_id',$id)->where('posting_version',$b->posting_version)->whereNull('reversal_journal_id')->lockForUpdate()->get();
            foreach($rows as $r){
                $general=$this->generalPosting->generalPostingByJournal((string)$r->journal_entry_id);
                $generalId=$general?(string)$general->id:(string)$this->generalPosting->adoptExistingJournal((string)$r->journal_entry_id,$userId)['general_posting_id'];
                $this->generalPosting->reopen($generalId,$reason,$userId);
                $link=DB::table('finance_general_posting_journals')->where('general_posting_id',$generalId)->where('journal_entry_id',(string)$r->journal_entry_id)->first();
                $rev=(string)($link->reversal_journal_id??'');$no=(string)($link->reversal_journal_no??'');
                if($rev==='')throw new InvalidArgumentException('Reversal General Posting Bulk Settlement tidak ditemukan.');
                DB::table('finance_settlement_bulk_journals')->where('id',$r->id)->update(['reversal_journal_id'=>$rev,'reversal_journal_no'=>$no,'reversed_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('finance_settlement_batches')->where('id',$id)->update(['status'=>'CANCELLED','reopened_at'=>now(),'reopened_by_user_id'=>$userId,'reopen_reason'=>$reason,'updated_at'=>now()]);
            foreach(DB::table('finance_settlement_bulk_items')->where('batch_id',$id)->pluck('settlement_source_id') as $sid)$this->settlements->recalculateSource((string)$sid);
            return ['id'=>$id,'status'=>'CANCELLED'];
        });
    }

    private function context(array $sourceIds,string $settlementDate):array
    {
        $ids=array_values(array_unique(array_filter(array_map('strval',$sourceIds))));if(count($ids)<2)throw new InvalidArgumentException('Settlement Bulk membutuhkan minimal 2 source.');
        if(count($ids)>100)throw new InvalidArgumentException('Settlement Bulk maksimal 100 source.');
        $sources=DB::table('finance_settlement_sources')->whereIn('id',$ids)->get();if($sources->count()!==count($ids))throw new InvalidArgumentException('Sebagian source Settlement tidak ditemukan.');
        $out=[];$company=null;$outlet=null;$businessDate=null;$bank=null;$mdrAcc=null;$adminAcc=null;$paymentNames=[];$defaultMdr=0;$defaultAdmin=0;$gross=0;
        foreach($sources as $s){
            if(!in_array((string)$s->status,['OPEN','PARTIAL'],true))throw new InvalidArgumentException("Source {$s->reconciliation_no} {$s->payment_method_name} {$s->marking} tidak aktif untuk settlement.");
            $sourceJournal=DB::table('finance_journal_entries')->where('id',$s->source_journal_entry_id)->value('status');
            $rec=DB::table('finance_reconciliations')->where('id',$s->reconciliation_id)->first(['status','posting_version']);
            if($sourceJournal!=='POSTED'||!$rec||$rec->status!=='POSTED'||(int)$rec->posting_version!==(int)$s->posting_version)throw new InvalidArgumentException('Sebagian source Reconciliation sudah berubah/reversed. Refresh Settlement.');
            $map=$this->settlements->mappingForSource($s);if(!$map)throw new InvalidArgumentException("Mapping source {$s->payment_method_name} {$s->marking} belum tersedia.");
            if($settlementDate<(string)$s->expected_settlement_date)throw new InvalidArgumentException("Source {$s->reconciliation_no} belum mencapai expected settlement {$s->expected_settlement_date}.");
            $avail=$this->settlements->availableAmount((string)$s->id);if($avail<=0.005)throw new InvalidArgumentException("Source {$s->reconciliation_no} {$s->payment_method_name} {$s->marking} tidak memiliki available amount.");
            if($company===null){$company=(string)$s->company_code;$outlet=(string)$s->outlet_id;$businessDate=(string)$s->business_date;$bank=(string)$map->bank_account_id;$mdrAcc=$map->mdr_expense_account_id?(string)$map->mdr_expense_account_id:null;$adminAcc=$map->admin_fee_expense_account_id?(string)$map->admin_fee_expense_account_id:null;}
            if((string)$s->company_code!==$company||(string)$s->outlet_id!==$outlet||(string)$s->business_date!==$businessDate)throw new InvalidArgumentException('Bulk hanya dapat menggabungkan source dari PT, outlet, dan business date yang sama agar dimensi GL tetap benar.');
            if((string)$map->bank_account_id!==$bank)throw new InvalidArgumentException('Source terpilih harus menuju rekening Bank/Kas yang sama.');
            if(($map->mdr_expense_account_id?(string)$map->mdr_expense_account_id:null)!==$mdrAcc||($map->admin_fee_expense_account_id?(string)$map->admin_fee_expense_account_id:null)!==$adminAcc)throw new InvalidArgumentException('Source terpilih harus memakai COA MDR/Admin yang sama.');
            $gross=round($gross+$avail,2);$defaultMdr=round($defaultMdr+($avail*(float)$map->mdr_rate/100)+(float)$map->mdr_fixed,2);$defaultAdmin=round($defaultAdmin+($avail*(float)$map->admin_fee_rate/100)+(float)$map->admin_fee_fixed,2);$paymentNames[(string)$s->payment_method_name]=true;
            $out[]=['id'=>(string)$s->id,'reconciliation_no'=>(string)$s->reconciliation_no,'marking'=>(string)$s->marking,'payment_method_name'=>(string)$s->payment_method_name,'clearing_account_id'=>(string)$s->clearing_account_id,'available_amount'=>$avail];
        }
        return ['company_code'=>$company,'outlet_id'=>$outlet,'business_date'=>$businessDate,'bank_account_id'=>$bank,'mdr_account_id'=>$mdrAcc,'admin_account_id'=>$adminAcc,'payment_label'=>implode(' + ',array_keys($paymentNames)),'gross'=>$gross,'default_mdr'=>$defaultMdr,'default_admin'=>$defaultAdmin,'sources'=>$out];
    }

    private function allocateTotals(float $gross,array $markTotals,float $bank,float $mdr,float $admin):array
    {
        $result=[];$marks=array_keys($markTotals);$totals=['bank'=>$bank,'mdr'=>$mdr,'admin'=>$admin];$remaining=$totals;
        foreach($marks as $i=>$mark){$g=round((float)$markTotals[$mark],2);$last=$i===count($marks)-1;$row=['gross'=>$g];foreach(['bank','mdr','admin'] as $k){$v=$last?$remaining[$k]:round(($gross>0?$g/$gross:0)*$totals[$k],2);$remaining[$k]=round($remaining[$k]-$v,2);$row[$k]=$v;}$result[$mark]=$row;}
        return $result;
    }

    private function decorate(array $lines):array
    {
        $ids=collect($lines)->pluck('account_id')->unique()->all();$a=DB::table('finance_chart_of_accounts')->whereIn('id',$ids)->get(['id','code','name'])->keyBy('id');
        return collect($lines)->map(fn($l)=>$l+['account_code'=>(string)($a->get($l['account_id'])->code??''),'account_name'=>(string)($a->get($l['account_id'])->name??'')])->all();
    }
}
