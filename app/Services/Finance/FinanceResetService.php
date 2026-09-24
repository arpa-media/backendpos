<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FinanceResetService
{
    public function __construct(
        private readonly FinanceReconciliationService $reconciliations,
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceSettlementBulkService $bulk,
    ) {}

    public function dashboard(array $allowedOutletIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('strval',$allowedOutletIds))));
        $recs=DB::table('finance_reconciliations as r')->leftJoin('outlets as o','o.id','=','r.outlet_id')
            ->when($ids,fn($q)=>$q->whereIn('r.outlet_id',$ids),fn($q)=>$q->whereRaw('1=0'))
            ->whereIn('r.status',['DRAFT','POSTED'])->orderByDesc('r.business_date')->orderByDesc('r.created_at')->limit(100)
            ->get(['r.id','r.reconciliation_no','r.business_date','r.status','r.posting_version','r.outlet_id','o.name as outlet_name'])->map(fn($r)=>(array)$r)->all();
        $single=DB::table('finance_settlements as s')->leftJoin('outlets as o','o.id','=','s.outlet_id')
            ->when($ids,fn($q)=>$q->whereIn('s.outlet_id',$ids),fn($q)=>$q->whereRaw('1=0'))
            ->whereIn('s.status',['DRAFT','POSTED'])->orderByDesc('s.settlement_date')->orderByDesc('s.created_at')->limit(100)
            ->get(['s.id','s.settlement_no','s.settlement_date','s.status','s.posting_version','s.outlet_id','s.payment_method_name','s.marking','s.clearing_amount','o.name as outlet_name'])
            ->map(fn($r)=>(array)$r+['kind'=>'SINGLE'])->all();
        $bulk=DB::table('finance_settlement_batches as b')->leftJoin('outlets as o','o.id','=','b.outlet_id')
            ->when($ids,fn($q)=>$q->whereIn('b.outlet_id',$ids),fn($q)=>$q->whereRaw('1=0'))
            ->whereIn('b.status',['DRAFT','POSTED'])->orderByDesc('b.settlement_date')->orderByDesc('b.created_at')->limit(100)
            ->get(['b.id','b.batch_no as settlement_no','b.settlement_date','b.status','b.posting_version','b.outlet_id','b.payment_label as payment_method_name','b.clearing_amount','o.name as outlet_name'])
            ->map(fn($r)=>(array)$r+['kind'=>'BULK','marking'=>'MIXED'])->all();
        return ['reconciliations'=>$recs,'settlements'=>collect($single)->concat($bulk)->sortByDesc(fn($r)=>$r['settlement_date'].'|'.$r['settlement_no'])->values()->all()];
    }

    public function resetReconciliation(string $id,string $reason,?string $userId): array
    {
        return DB::transaction(function()use($id,$reason,$userId):array{
            $r=DB::table('finance_reconciliations')->where('id',$id)->lockForUpdate()->first();if(!$r)throw new InvalidArgumentException('Reconciliation tidak ditemukan.');
            $sourceIds=DB::table('finance_settlement_sources')->where('reconciliation_id',$id)->pluck('id')->map(fn($v)=>(string)$v)->all();
            if($sourceIds){
                $single=DB::table('finance_settlements')->whereIn('settlement_source_id',$sourceIds)->whereIn('status',['DRAFT','POSTED'])->exists();
                $bulk=DB::table('finance_settlement_bulk_items as i')->join('finance_settlement_batches as b','b.id','=','i.batch_id')->whereIn('i.settlement_source_id',$sourceIds)->whereIn('b.status',['DRAFT','POSTED'])->exists();
                if($single||$bulk)throw new InvalidArgumentException('Reconciliation masih mempunyai Settlement aktif. Reset Settlement terlebih dahulu.');
            }
            if($r->status==='POSTED'){
                // Admin Reset must neutralize the original accounting period.
                // Use the original journal date, not today's reset date, so GL
                // with DATE BASIS = JOURNAL nets to zero in the source period.
                $originalJournalDate=DB::table('finance_reconciliation_postings as p')
                    ->join('finance_journal_entries as j','j.id','=','p.journal_entry_id')
                    ->where('p.reconciliation_id',$id)
                    ->where('p.posting_version',(int)$r->posting_version)
                    ->orderBy('j.journal_date')
                    ->value('j.journal_date');
                $resetDate=(string)($originalJournalDate?:$r->business_date);
                $this->reconciliations->reopen($id,$resetDate,'[FINANCE RESET] '.$reason,$userId);
            }
            $history=DB::table('finance_reconciliation_postings')->where('reconciliation_id',$id)->exists();
            if(!$history){$this->reconciliations->deleteDraft($id);return ['id'=>$id,'status'=>'DELETED','action'=>'Dokumen draft dihapus. Buat reconciliation baru untuk outlet/tanggal yang sama.'];}
            // A reset of a previously POSTED reconciliation is an accounting
            // archive operation, not a source rebuild. Reverse journal, clear
            // overrides, archive immediately, and let a future Create/Refresh
            // explicitly rebuild source when needed.
            DB::table('finance_reconciliations')->where('id',$id)->update(['status'=>'CANCELLED','discount_override_amount'=>null,'tax_override_amount'=>null,'rounding_override_amount'=>null,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            DB::table('finance_reconciliation_payments')->where('reconciliation_id',$id)->update(['actual_override_amount'=>null,'note'=>null,'updated_at'=>now()]);
            return ['id'=>$id,'status'=>'CANCELLED','action'=>'Journal direversal dan Reconciliation diarsipkan tanpa rebuild source. Buat Reconciliation baru untuk tanggal yang sama bila diperlukan.'];
        });
    }

    public function resetSettlement(string $kind,string $id,string $reason,?string $userId): array
    {
        $kind=strtoupper($kind);
        if($kind==='BULK'){
            $b=DB::table('finance_settlement_batches')->where('id',$id)->first();if(!$b)throw new InvalidArgumentException('Bulk Settlement tidak ditemukan.');
            if($b->status==='POSTED'){
                $originalJournalDate=DB::table('finance_settlement_bulk_journals as bj')
                    ->join('finance_journal_entries as j','j.id','=','bj.journal_entry_id')
                    ->where('bj.batch_id',$id)->where('bj.posting_version',(int)$b->posting_version)
                    ->orderBy('j.journal_date')->value('j.journal_date');
                $resetDate=(string)($originalJournalDate?:$b->settlement_date);
                return $this->bulk->reopen($id,$resetDate,'[FINANCE RESET] '.$reason,$userId);
            }
            if($b->status==='DRAFT'){
                $sourceIds=DB::table('finance_settlement_bulk_items')->where('batch_id',$id)->pluck('settlement_source_id')->map(fn($v)=>(string)$v)->all();
                $hasJournal=DB::table('finance_settlement_bulk_journals')->where('batch_id',$id)->exists();
                if($hasJournal){DB::table('finance_settlement_batches')->where('id',$id)->update(['status'=>'CANCELLED','reopen_reason'=>$reason,'updated_at'=>now()]);}
                else DB::table('finance_settlement_batches')->where('id',$id)->delete();
                foreach($sourceIds as $sid)$this->settlements->recalculateSource($sid);
                return ['id'=>$id,'status'=>$hasJournal?'CANCELLED':'DELETED'];
            }
            return ['id'=>$id,'status'=>(string)$b->status,'idempotent'=>true];
        }
        $s=DB::table('finance_settlements')->where('id',$id)->first();if(!$s)throw new InvalidArgumentException('Settlement tidak ditemukan.');
        if($s->status==='POSTED'){
            $originalJournalDate=$s->journal_entry_id
                ? DB::table('finance_journal_entries')->where('id',$s->journal_entry_id)->value('journal_date')
                : null;
            $resetDate=(string)($originalJournalDate?:$s->settlement_date);
            $this->settlements->reopen($id,$resetDate,'[FINANCE RESET] '.$reason,$userId);
        }
        $fresh=DB::table('finance_settlements')->where('id',$id)->first();
        if($fresh&&$fresh->status==='DRAFT')$this->settlements->deleteDraft($id);
        $after=DB::table('finance_settlements')->where('id',$id)->first();
        return ['id'=>$id,'status'=>$after?(string)$after->status:'DELETED'];
    }
}
