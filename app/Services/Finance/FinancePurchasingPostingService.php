<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FinancePurchasingPostingService
{
    public function __construct(
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly FinanceScopeResolver $scope,
    ) {}

    public function options(array $allowedOutlets=[]): array
    {
        $outlets=DB::table('outlets as o')->leftJoin('finance_outlet_company_mappings as m',fn($j)=>$j->on('m.outlet_id','=','o.id')->where('m.is_active',true))
            ->when($allowedOutlets,fn($q)=>$q->whereIn('o.id',$allowedOutlets))->where('o.is_active',true)->orderBy('o.name')
            ->get(['o.id','o.code','o.name','m.company_code'])->map(fn($r)=>(array)$r)->all();
        $coas=DB::table('finance_chart_of_accounts')->where('is_active',true)->where('is_postable',true)->orderBy('code')->get(['id','code','name','account_type'])->map(fn($r)=>(array)$r)->all();
        $payAccounts=Schema::hasTable('wh_v3_payment_accounts')?DB::table('wh_v3_payment_accounts as p')->leftJoin('outlets as w','w.id','=','p.warehouse_id')->where('p.is_active',true)->when($allowedOutlets,fn($q)=>$q->whereIn('p.warehouse_id',$allowedOutlets))->orderBy('p.name')->get(['p.id','p.warehouse_id','p.code','p.name','p.bank_name','p.account_name','p.account_number','w.name as warehouse_name'])->map(fn($r)=>(array)$r)->all():[];
        return ['companies'=>$this->scope->companies(),'outlets'=>$outlets,'coas'=>$coas,'payment_methods'=>['CASH','PETTY_CASH','BANK_TRANSFER','GIRO','VIRTUAL_ACCOUNT','OTHER'],'event_types'=>['INVOICE_ISSUED','INVOICE_PAYMENT_POSTED'],'posting_statuses'=>['UNPOSTED','DRAFT','POSTED','CANCELLED'],'warehouse_payment_accounts'=>$payAccounts];
    }

    public function outbox(array $filters,array $allowedOutlets=[]): array
    {
        $q=DB::table('pur_finance_posting_outbox as x')
            ->leftJoin('pur_invoices as i',function($j){$j->on('i.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE');})
            ->leftJoin('pur_invoice_payments as py',function($j){$j->on('py.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE_PAYMENT');})
            ->leftJoin('pur_invoices as pi','pi.id','=','py.invoice_id')
            ->leftJoin('finance_purchasing_postings as fp','fp.outbox_id','=','x.id')
            ->selectRaw('x.*, COALESCE(i.id,pi.id) invoice_id, COALESCE(i.invoice_number,pi.invoice_number) invoice_number, COALESCE(i.outlet_id,pi.outlet_id) outlet_id, COALESCE(i.counterparty_name,pi.counterparty_name) counterparty_name, COALESCE(i.source_document_kind,pi.source_document_kind) source_document_kind, COALESCE(i.total_amount,py.amount,0) amount, py.payment_date, py.payment_method, fp.id posting_id, fp.status posting_status, fp.posting_no');
        if($allowedOutlets)$q->whereIn(DB::raw('COALESCE(i.outlet_id,pi.outlet_id)'),$allowedOutlets);
        if(!empty($filters['date_from']))$q->where('x.created_at','>=',$filters['date_from'].' 00:00:00');
        if(!empty($filters['date_to']))$q->where('x.created_at','<',CarbonImmutable::parse($filters['date_to'])->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        if(!empty($filters['outlet_id']))$q->whereRaw('COALESCE(i.outlet_id,pi.outlet_id)=?',[$filters['outlet_id']]);
        if(!empty($filters['event_type']))$q->where('x.event_type',$filters['event_type']);
        if(!empty($filters['posting_status'])){
            $s=$filters['posting_status']; $s==='UNPOSTED'?$q->whereNull('fp.id'):$q->where('fp.status',$s);
        }
        $per=max(10,min(100,(int)($filters['per_page']??25)));$page=max(1,(int)($filters['page']??1));$total=(clone $q)->count();
        $items=$q->orderByDesc('x.created_at')->forPage($page,$per)->get()->map(function($r){$a=(array)$r;$a['amount']=round((float)$a['amount'],2);return $a;})->all();
        return ['items'=>$items,'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>(int)ceil($total/$per)]];
    }

    public function autoEvents(array $filters=[],array $allowedOutlets=[]): array
    {
        if(!Schema::hasTable('finance_purchasing_auto_events'))return ['items'=>[]];
        $q=DB::table('finance_purchasing_auto_events as e')->leftJoin('outlets as o','o.id','=','e.outlet_id')->leftJoin('finance_journal_entries as j','j.id','=','e.journal_entry_id');
        if($allowedOutlets)$q->whereIn('e.outlet_id',$allowedOutlets);
        if(!empty($filters['status']))$q->where('e.status',$filters['status']);
        if(!empty($filters['date_from']))$q->where('e.business_date','>=',$filters['date_from']);
        if(!empty($filters['date_to']))$q->where('e.business_date','<=',$filters['date_to']);
        return ['items'=>$q->orderByDesc('e.created_at')->limit(300)->get(['e.*','o.name as outlet_name','j.status as journal_status'])->map(fn($r)=>(array)$r)->all()];
    }

    public function issueMappings(array $allowedOutlets=[]): array
    {
        $q=DB::table('finance_purchasing_posting_mappings as m')->leftJoin('outlets as o','o.id','=','m.outlet_id')->leftJoin('finance_chart_of_accounts as d','d.id','=','m.default_debit_account_id')->leftJoin('finance_chart_of_accounts as ap','ap.id','=','m.ap_account_id')->leftJoin('finance_chart_of_accounts as tax','tax.id','=','m.tax_account_id');
        if($allowedOutlets)$q->where(fn($x)=>$x->whereNull('m.outlet_id')->orWhereIn('m.outlet_id',$allowedOutlets));
        return $q->orderBy('m.company_code')->orderBy('o.name')->orderBy('m.source_document_kind')->get(['m.*','o.name as outlet_name','d.code as debit_code','d.name as debit_name','ap.code as ap_code','ap.name as ap_name','tax.code as tax_code','tax.name as tax_name'])->map(fn($r)=>(array)$r)->all();
    }

    public function paymentMappings(array $allowedOutlets=[]): array
    {
        $q=DB::table('finance_purchasing_payment_mappings as m')->leftJoin('outlets as o','o.id','=','m.outlet_id')->leftJoin('finance_chart_of_accounts as c','c.id','=','m.cash_account_id');
        if($allowedOutlets)$q->where(fn($x)=>$x->whereNull('m.outlet_id')->orWhereIn('m.outlet_id',$allowedOutlets));
        return $q->orderBy('m.company_code')->orderBy('o.name')->orderBy('m.payment_method')->get(['m.*','o.name as outlet_name','c.code as cash_code','c.name as cash_name'])->map(fn($r)=>(array)$r)->all();
    }

    public function warehousePaymentMappings(array $allowedOutlets=[]): array
    {
        if(!Schema::hasTable('finance_warehouse_payment_account_mappings'))return [];
        $q=DB::table('finance_warehouse_payment_account_mappings as m')->join('wh_v3_payment_accounts as p','p.id','=','m.payment_account_id')->leftJoin('outlets as o','o.id','=','m.outlet_id')->join('finance_chart_of_accounts as c','c.id','=','m.cash_account_id');
        if($allowedOutlets)$q->where(fn($x)=>$x->whereNull('m.outlet_id')->orWhereIn('m.outlet_id',$allowedOutlets));
        return $q->orderBy('p.name')->get(['m.*','p.code as payment_account_code','p.name as payment_account_name','p.bank_name','p.account_number','o.name as outlet_name','c.code as cash_code','c.name as cash_name'])->map(fn($r)=>(array)$r)->all();
    }

    public function saveIssueMapping(array $d,?string $userId,?string $id=null): string
    {
        $this->assertCoa($d['default_debit_account_id'],['ASSET','EXPENSE','COGS','OTHER_EXPENSE']);$this->assertCoa($d['ap_account_id'],['LIABILITY']);$this->assertCoa($d['tax_account_id'],['ASSET']);
        $key=strtoupper($d['company_code']).':'.(($d['outlet_id']??null)?:'ALL').':'.strtoupper($d['source_document_kind']);$id=$id?: (string)Str::ulid();
        $row=['mapping_key'=>$key,'company_code'=>strtoupper($d['company_code']),'outlet_id'=>$d['outlet_id']??null,'source_document_kind'=>strtoupper($d['source_document_kind']),'default_debit_account_id'=>$d['default_debit_account_id'],'ap_account_id'=>$d['ap_account_id'],'tax_account_id'=>$d['tax_account_id'],'default_marking'=>strtoupper($d['default_marking']),'is_active'=>(bool)$d['is_active'],'notes'=>$d['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>now()];
        if(DB::table('finance_purchasing_posting_mappings')->where('id',$id)->exists())DB::table('finance_purchasing_posting_mappings')->where('id',$id)->update($row);else DB::table('finance_purchasing_posting_mappings')->insert($row+['id'=>$id,'created_by_user_id'=>$userId,'created_at'=>now()]);return $id;
    }

    public function savePaymentMapping(array $d,?string $userId,?string $id=null): string
    {
        $this->assertCoa($d['cash_account_id'],['ASSET']);$key=strtoupper($d['company_code']).':'.(($d['outlet_id']??null)?:'ALL').':'.strtoupper($d['payment_method']);$id=$id?: (string)Str::ulid();
        $row=['mapping_key'=>$key,'company_code'=>strtoupper($d['company_code']),'outlet_id'=>$d['outlet_id']??null,'payment_method'=>strtoupper($d['payment_method']),'cash_account_id'=>$d['cash_account_id'],'is_active'=>(bool)$d['is_active'],'notes'=>$d['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>now()];
        if(DB::table('finance_purchasing_payment_mappings')->where('id',$id)->exists())DB::table('finance_purchasing_payment_mappings')->where('id',$id)->update($row);else DB::table('finance_purchasing_payment_mappings')->insert($row+['id'=>$id,'created_by_user_id'=>$userId,'created_at'=>now()]);return $id;
    }

    public function saveWarehousePaymentMapping(array $d,?string $userId,?string $id=null): string
    {
        $this->assertCoa($d['cash_account_id'],['ASSET']);$account=DB::table('wh_v3_payment_accounts')->where('id',$d['payment_account_id'])->where('is_active',true)->first();if(!$account)throw new InvalidArgumentException('Payment Account Warehouse tidak ditemukan/aktif.');$scope=$this->scope->resolve(null,(string)$account->warehouse_id);
        $id=$id?: (string)Str::ulid();$row=['payment_account_id'=>$d['payment_account_id'],'company_code'=>$scope['company_code'],'outlet_id'=>(string)$account->warehouse_id,'cash_account_id'=>$d['cash_account_id'],'is_active'=>(bool)$d['is_active'],'notes'=>$d['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>now()];
        if(DB::table('finance_warehouse_payment_account_mappings')->where('id',$id)->exists())DB::table('finance_warehouse_payment_account_mappings')->where('id',$id)->update($row);else DB::table('finance_warehouse_payment_account_mappings')->insert($row+['id'=>$id,'created_by_user_id'=>$userId,'created_at'=>now()]);return $id;
    }

    public function deleteIssueMapping(string $id):void{$this->assertMappingUnused('issue_mapping_id',$id);DB::table('finance_purchasing_posting_mappings')->where('id',$id)->delete();}
    public function deletePaymentMapping(string $id):void{$this->assertMappingUnused('payment_mapping_id',$id);DB::table('finance_purchasing_payment_mappings')->where('id',$id)->delete();}
    public function deleteWarehousePaymentMapping(string $id):void{DB::table('finance_warehouse_payment_account_mappings')->where('id',$id)->delete();}

    public function createDraft(string $outboxId,?string $userId): string
    {
        return DB::transaction(function()use($outboxId,$userId):string{
            $existing=DB::table('finance_purchasing_postings')->where('outbox_id',$outboxId)->lockForUpdate()->first();
            $ctx=$this->outboxContext($outboxId);
            $scope=$this->scope->resolve($ctx['company_code']??null,$ctx['outlet_id']?:null);
            $kind=$this->mappingKind($ctx['source_document_kind']);
            $issue=$this->issueMapping($scope['company_code'],$ctx['outlet_id'],$kind);
            if(!$issue)throw new InvalidArgumentException("Mapping liability {$kind} untuk {$scope['company_code']} belum tersedia.");
            $payment=null;
            if($ctx['event_type']==='INVOICE_PAYMENT_POSTED'){
                $payment=$this->paymentMapping($scope['company_code'],$ctx['outlet_id'],$ctx['payment_method']);
                if(!$payment)throw new InvalidArgumentException("Mapping rekening pembayaran {$ctx['payment_method']} belum tersedia.");
            }
            $finger=$this->fingerprint($ctx,$issue,$payment);

            if($existing){
                if((string)$existing->status==='POSTED')return(string)$existing->id;
                if((string)$existing->status!=='DRAFT')throw new InvalidArgumentException('Existing Purchasing Posting tidak berada pada status DRAFT/POSTED.');

                // I05 repairs only a stale system contract. Manual line overrides remain untouched
                // when the source/mapping fingerprint is still the same.
                if(!hash_equals((string)($existing->source_fingerprint??''),$finger)){
                    DB::table('finance_purchasing_postings')->where('id',$existing->id)->update([
                        'source_document_kind'=>$ctx['source_document_kind']?:'MANUAL',
                        'source_document_id'=>$ctx['source_document_id']?:$ctx['invoice_id'],
                        'source_document_number'=>$ctx['source_document_number'],
                        'company_code'=>$scope['company_code'],'outlet_id'=>$ctx['outlet_id'],'marking'=>$ctx['marking'],
                        'issue_mapping_id'=>$issue->id,'payment_mapping_id'=>$payment?->id,
                        'business_date'=>$ctx['business_date'],'journal_date'=>$ctx['business_date'],
                        'source_fingerprint'=>$finger,'updated_by_user_id'=>$userId,'updated_at'=>now(),
                    ]);
                    DB::table('finance_purchasing_posting_lines')->where('purchasing_posting_id',$existing->id)->delete();
                    DB::table('finance_purchasing_posting_allocations')->where('purchasing_posting_id',$existing->id)->delete();
                    $this->buildLines((string)$existing->id,$ctx,$issue);
                }
                return(string)$existing->id;
            }

            $id=(string)Str::ulid();$now=now();
            DB::table('finance_purchasing_postings')->insert(['id'=>$id,'posting_no'=>'FPP-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr($id,-7)),'outbox_id'=>$outboxId,'event_key'=>$ctx['event_key'],'event_type'=>$ctx['event_type'],'invoice_id'=>$ctx['invoice_id'],'payment_id'=>$ctx['payment_id'],'source_document_kind'=>$ctx['source_document_kind']?:'MANUAL','source_document_id'=>$ctx['source_document_id']?:$ctx['invoice_id'],'source_document_number'=>$ctx['source_document_number'],'company_code'=>$scope['company_code'],'outlet_id'=>$ctx['outlet_id'],'marking'=>$ctx['marking'],'supplier_source_id'=>null,'supplier_name'=>$ctx['counterparty_name']?:'-','supplier_type'=>null,'warehouse_id'=>$ctx['warehouse_id'],'warehouse_name'=>$ctx['warehouse_name'],'counterparty_name'=>$ctx['counterparty_name']?:'-','issue_mapping_id'=>$issue->id,'payment_mapping_id'=>$payment?->id,'business_date'=>$ctx['business_date'],'journal_date'=>$ctx['business_date'],'source_fingerprint'=>$finger,'status'=>'DRAFT','posting_version'=>0,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now]);
            $this->buildLines($id,$ctx,$issue);return$id;
        },3);
    }

    public function show(string $id): array
    {
        $p=DB::table('finance_purchasing_postings')->where('id',$id)->first();if(!$p)throw new InvalidArgumentException('Purchasing Posting tidak ditemukan.');
        $lines=DB::table('finance_purchasing_posting_lines as l')->join('finance_chart_of_accounts as c','c.id','=','l.target_account_id')->where('l.purchasing_posting_id',$id)->orderBy('l.line_no')->get(['l.*','c.code as target_account_code','c.name as target_account_name'])->map(fn($r)=>(array)$r)->all();
        $journals=DB::table('finance_purchasing_posting_journals')->where('purchasing_posting_id',$id)->orderByDesc('posting_version')->get()->map(fn($r)=>(array)$r)->all();
        return (array)$p+['lines'=>$lines,'journals'=>$journals];
    }

    public function updateIssueLines(string $id,array $lines,?string $userId):void
    {
        DB::transaction(function()use($id,$lines,$userId){$p=DB::table('finance_purchasing_postings')->where('id',$id)->lockForUpdate()->first();if(!$p||$p->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat diubah.');foreach($lines as $line){$this->assertCoa($line['target_account_id'],['ASSET','EXPENSE','COGS','OTHER_EXPENSE']);DB::table('finance_purchasing_posting_lines')->where('purchasing_posting_id',$id)->where('id',$line['id'])->update(['target_account_id'=>$line['target_account_id'],'marking'=>$line['marking'],'updated_at'=>now()]);}DB::table('finance_purchasing_postings')->where('id',$id)->update(['updated_by_user_id'=>$userId,'updated_at'=>now()]);});
    }

    public function refreshDraft(string $id,?string $userId):void
    {
        $p=DB::table('finance_purchasing_postings')->where('id',$id)->first();if(!$p||$p->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat direfresh.');$ctx=$this->outboxContext((string)$p->outbox_id);$issue=DB::table('finance_purchasing_posting_mappings')->where('id',$p->issue_mapping_id)->first();DB::transaction(function()use($id,$ctx,$issue,$userId){DB::table('finance_purchasing_posting_lines')->where('purchasing_posting_id',$id)->delete();DB::table('finance_purchasing_posting_allocations')->where('purchasing_posting_id',$id)->delete();$this->buildLines($id,$ctx,$issue);DB::table('finance_purchasing_postings')->where('id',$id)->update(['journal_date'=>$ctx['business_date'],'source_fingerprint'=>$this->fingerprint($ctx,$issue,null),'updated_by_user_id'=>$userId,'updated_at'=>now()]);});
    }

    public function post(string $id,?string $userId): array
    {
        return DB::transaction(function()use($id,$userId):array{
            $p=DB::table('finance_purchasing_postings')->where('id',$id)->lockForUpdate()->first();if(!$p)throw new InvalidArgumentException('Purchasing Posting tidak ditemukan.');
            if($p->status==='POSTED'){
                $link=DB::table('finance_purchasing_posting_journals')->where('purchasing_posting_id',$id)->where('posting_version',$p->posting_version)->first();
                $gp=$link?$this->generalPosting->generalPostingByJournal((string)$link->journal_entry_id):null;
                if(!$gp && $link){
                    // Legacy Finance Purchasing rows may have been POSTED directly to GL before the unified General Posting bridge.
                    // Adopt the same journal; never create a second economic entry.
                    try{
                        $adopted=$this->generalPosting->adoptExistingJournal((string)$link->journal_entry_id,$userId);
                        $gp=DB::table('finance_general_postings')->where('id',$adopted['general_posting_id']??'')->first();
                    }catch(Throwable){/* keep legacy link untouched if adoption is not eligible */}
                }
                $outboxUpdate=['status'=>'POSTED','posted_at'=>$p->posted_at?:now(),'last_error'=>null,'updated_at'=>now()];
                if(Schema::hasColumn('pur_finance_posting_outbox','general_posting_id'))$outboxUpdate['general_posting_id']=$gp?->id;
                DB::table('pur_finance_posting_outbox')->where('id',$p->outbox_id)->update($outboxUpdate);
                $this->syncApLifecyclePostingState((string)$p->event_key,'POSTED',$link?->journal_no,null);
                return ['id'=>$id,'status'=>'POSTED','general_posting_id'=>$gp?->id,'journal_entry_id'=>$link?->journal_entry_id,'journal_no'=>$link?->journal_no,'idempotent'=>true];
            }
            if($p->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat diposting.');$ctx=$this->outboxContext((string)$p->outbox_id);$issue=DB::table('finance_purchasing_posting_mappings')->where('id',$p->issue_mapping_id)->first();if(!$issue)throw new InvalidArgumentException('Mapping liability sudah tidak tersedia.');
            $covered=$this->coveredWarehouseReceipt($ctx);
            if($covered){$journalId=(string)$covered->journal_entry_id;$journalNo=(string)$covered->journal_no;}
            else{$journalId=$this->createJournalForPosting($p,$ctx,$issue,$userId);$journalNo=(string)DB::table('finance_journal_entries')->where('id',$journalId)->value('journal_no');}
            $version=(int)$p->posting_version+1;DB::table('finance_purchasing_posting_journals')->insert(['id'=>(string)Str::ulid(),'purchasing_posting_id'=>$id,'posting_version'=>$version,'marking'=>(string)($p->marking ?? $issue->default_marking),'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $gp=$this->generalPosting->generalPostingByJournal($journalId);
            DB::table('finance_purchasing_postings')->where('id',$id)->update(['status'=>'POSTED','posting_version'=>$version,'posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $outboxUpdate=['status'=>'POSTED','posted_at'=>now(),'last_error'=>null,'updated_at'=>now()];
            if(Schema::hasColumn('pur_finance_posting_outbox','general_posting_id'))$outboxUpdate['general_posting_id']=$gp?->id;
            DB::table('pur_finance_posting_outbox')->where('id',$p->outbox_id)->update($outboxUpdate);DB::table('pur_invoices')->where('id',$p->invoice_id)->update(['journal_status'=>'POSTED','journal_reference'=>$journalNo,'updated_at'=>now()]);$this->syncApLifecyclePostingState((string)$p->event_key,'POSTED',$journalNo,null);
            return ['id'=>$id,'status'=>'POSTED','general_posting_id'=>$gp?->id,'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'covered_by_gr'=>(bool)$covered,'idempotent'=>false];
        },3);
    }

    public function reopen(string $id,string $reason,?string $userId):void
    {
        DB::transaction(function()use($id,$reason,$userId){$p=DB::table('finance_purchasing_postings')->where('id',$id)->lockForUpdate()->first();if(!$p||$p->status!=='POSTED')throw new InvalidArgumentException('Hanya POSTED yang dapat direopen.');$link=DB::table('finance_purchasing_posting_journals')->where('purchasing_posting_id',$id)->where('posting_version',$p->posting_version)->lockForUpdate()->first();if(!$link)throw new InvalidArgumentException('Journal posting tidak ditemukan.');
            $shared=DB::table('finance_purchasing_auto_events')->where('journal_entry_id',$link->journal_entry_id)->where('status','POSTED')->exists();if($shared)throw new InvalidArgumentException('Jurnal ini adalah liability GR authoritative. Unpost dilakukan dari General Posting source GR.');
            $general=$this->generalPosting->generalPostingByJournal((string)$link->journal_entry_id);
            if($general){
                $this->generalPosting->reopen((string)$general->id,$reason,$userId);
                $gpLink=DB::table('finance_general_posting_journals')->where('general_posting_id',$general->id)->where('posting_version',$general->posting_version)->first();
                $rev=(string)($gpLink?->reversal_journal_id??'');$revNo=(string)($gpLink?->reversal_journal_no??'');
            }else{
                throw new InvalidArgumentException('Journal Purchasing legacy belum memiliki General Posting. Jalankan Reconcile Historical GL dari menu General Posting terlebih dahulu, lalu ulangi Unpost.');
            }
            DB::table('finance_purchasing_posting_journals')->where('id',$link->id)->update(['reversal_journal_id'=>$rev?:null,'reversal_journal_no'=>$revNo?:null,'reversed_at'=>now(),'updated_at'=>now()]);DB::table('finance_purchasing_postings')->where('id',$id)->update(['status'=>'DRAFT','reopened_at'=>now(),'reopened_by_user_id'=>$userId,'reopen_reason'=>$reason,'updated_at'=>now()]);DB::table('pur_finance_posting_outbox')->where('id',$p->outbox_id)->update(['status'=>'PENDING','posted_at'=>null,'updated_at'=>now()]);});
    }
    public function destroyDraft(string $id):void{$p=DB::table('finance_purchasing_postings')->where('id',$id)->first();if(!$p)return;if($p->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat dihapus.');DB::table('finance_purchasing_postings')->where('id',$id)->delete();}

    public function autoPostOutboxByEventKey(string $eventKey,?string $userId): array
    {
        $x=DB::table('pur_finance_posting_outbox')->where('event_key',$eventKey)->first();
        if(!$x)return ['status'=>'NO_OUTBOX'];
        try{
            $id=$this->createDraft((string)$x->id,$userId);
            return $this->post($id,$userId);
        }catch(Throwable $e){
            DB::table('pur_finance_posting_outbox')->where('id',$x->id)->update(['attempts'=>DB::raw('attempts+1'),'last_error'=>$e->getMessage(),'updated_at'=>now()]);
            $this->syncApLifecyclePostingState($eventKey,'NEEDS_MAPPING',null,$e->getMessage());
            return ['status'=>'NEEDS_MAPPING','message'=>$e->getMessage()];
        }
    }

    public function processPending(array $allowedOutlets=[],?string $userId=null,int $limit=100): array
    {
        $q=DB::table('pur_finance_posting_outbox as x')->leftJoin('pur_invoices as i',function($j){$j->on('i.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE');})->leftJoin('pur_invoice_payments as p',function($j){$j->on('p.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE_PAYMENT');})->leftJoin('pur_invoices as pi','pi.id','=','p.invoice_id')->whereIn('x.status',['PENDING','FAILED'])->whereIn('x.event_type',['INVOICE_ISSUED','INVOICE_PAYMENT_POSTED']);if($allowedOutlets)$q->whereIn(DB::raw('COALESCE(i.outlet_id,pi.outlet_id)'),$allowedOutlets);$rows=$q->orderBy('x.created_at')->limit(max(1,min(200,$limit)))->get(['x.id','x.event_key']);$items=[];foreach($rows as $r)$items[]=['event_key'=>$r->event_key,'result'=>$this->autoPostOutboxByEventKey((string)$r->event_key,$userId)];return ['processed'=>count($items),'items'=>$items];
    }

    public function autoPostStockReceipt(string $goodsReceiptId,?string $userId): array
    {
        if(!Schema::hasTable('finance_purchasing_auto_events'))return ['status'=>'UNAVAILABLE'];
        $g=DB::table('wh_v3_goods_receipts as g')->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')->leftJoin('wh_v3_outgoing_invoices as w','w.id','=','g.outgoing_invoice_id')->where('g.id',$goodsReceiptId)->first(['g.id','g.goods_receipt_number','g.status','g.destination_type','g.destination_id','g.receipt_date','g.completed_at','g.outgoing_invoice_id','d.source_type','d.source_id','w.grand_total','w.invoice_number']);
        if(!$g||$g->status!=='completed'||$g->source_type!=='stock_request'||$g->destination_type!=='outlet')return ['status'=>'IGNORED'];
        $invoice=$g->outgoing_invoice_id?DB::table('pur_invoices')->where('direction','INCOMING')->where('source_document_kind','WAREHOUSE_OUTGOING_INVOICE')->where('source_document_id',$g->outgoing_invoice_id)->whereNull('deleted_at')->first():null;$amount=round((float)($invoice->total_amount??$g->grand_total??0),2);$eventKey='STOCK_REQUEST_GR:'.$goodsReceiptId;
        $event=$this->ensureAutoEvent($eventKey,'STOCK_RECEIPT_RECOGNIZED','WH_V3_GOODS_RECEIPT',$goodsReceiptId,$invoice?->id,null,$goodsReceiptId,(string)$g->destination_id,(string)($g->receipt_date?:substr((string)$g->completed_at,0,10)), $amount,['stock_request_id'=>$g->source_id,'warehouse_outgoing_invoice_id'=>$g->outgoing_invoice_id,'invoice_number'=>$invoice->invoice_number??null]);if($event->status==='POSTED')return ['status'=>'POSTED','journal_no'=>$event->journal_no,'idempotent'=>true];
        try{$scope=$this->scope->resolve(null,(string)$g->destination_id);$map=$this->issueMapping($scope['company_code'],(string)$g->destination_id,'GOODS_RECEIPT');if(!$map)throw new InvalidArgumentException('Mapping GOODS_RECEIPT (Persediaan/Hutang) belum tersedia untuk outlet ini.');if($amount<=0)throw new InvalidArgumentException('Nilai invoice/stock receipt belum tersedia atau nol.');
            $posted=$this->generalPosting->stageSystem([
                'source_key'=>'FIN-PUR-STOCK-GR:'.$goodsReceiptId,'source_code'=>'PURCHASING','source_module'=>'PURCHASING','source_identity'=>$goodsReceiptId,
                'reference_no'=>$g->goods_receipt_number,'company_code'=>$scope['company_code'],'outlet_id'=>(string)$g->destination_id,'marking'=>$map->default_marking,
                'business_date'=>(string)($g->receipt_date?:now('Asia/Jakarta')->toDateString()),'journal_date'=>(string)($g->receipt_date?:now('Asia/Jakarta')->toDateString()),
                'description'=>'Stock Request received · '.$g->goods_receipt_number,'payable'=>$amount,
                'metadata'=>['event_key'=>$eventKey,'stock_request_id'=>$g->source_id,'invoice_id'=>$invoice?->id,'posting_kind'=>'STOCK_RECEIPT_RECOGNIZED'],
            ],[[ 'account_id'=>(string)$map->default_debit_account_id,'debit'=>$amount,'credit'=>0,'description'=>'Persediaan dari Stock Request'],['account_id'=>(string)$map->ap_account_id,'debit'=>0,'credit'=>$amount,'description'=>'Hutang persediaan Warehouse']],$userId,true);
            $journalId=(string)$posted['journal_entry_id'];$journalNo=(string)$posted['journal_no'];DB::table('finance_purchasing_auto_events')->where('event_key',$eventKey)->update(['company_code'=>$scope['company_code'],'status'=>'POSTED','journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'mapping_snapshot'=>json_encode(['issue_mapping_id'=>$map->id,'debit_account_id'=>$map->default_debit_account_id,'ap_account_id'=>$map->ap_account_id,'general_posting_id'=>$posted['general_posting_id']]),'last_error'=>null,'attempts'=>DB::raw('attempts+1'),'posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_at'=>now()]);if($invoice)DB::table('pur_invoices')->where('id',$invoice->id)->update(['journal_status'=>'POSTED','journal_reference'=>$journalNo,'updated_at'=>now()]);return ['status'=>'POSTED','general_posting_id'=>$posted['general_posting_id'],'journal_entry_id'=>$journalId,'journal_no'=>$journalNo];}
        catch(Throwable $e){DB::table('finance_purchasing_auto_events')->where('event_key',$eventKey)->update(['status'=>'NEEDS_MAPPING','last_error'=>$e->getMessage(),'attempts'=>DB::raw('attempts+1'),'updated_at'=>now()]);return ['status'=>'NEEDS_MAPPING','message'=>$e->getMessage()];}
    }

    public function autoPostWarehouseIncomingPayment(string $documentSource,string $documentId,string $warehouseId,string $idempotencyKey,?string $userId): array
    {
        $payment=DB::table('wh_v3_invoice_payments')->where('document_source',$documentSource)->where('document_id',$documentId)->where('direction','incoming')->where('idempotency_key',$idempotencyKey)->first();if(!$payment)return ['status'=>'NO_PAYMENT'];$eventKey='WAREHOUSE_INCOMING_PAYMENT:'.$payment->id;$event=$this->ensureAutoEvent($eventKey,'INVOICE_PAYMENT_POSTED','WH_V3_INVOICE_PAYMENT',(string)$payment->id,null,(string)$payment->id,null,$warehouseId,(string)$payment->payment_date,round((float)$payment->amount,2),['document_source'=>$documentSource,'document_id'=>$documentId,'payment_account_id'=>$payment->payment_account_id]);if($event->status==='POSTED')return ['status'=>'POSTED','journal_no'=>$event->journal_no,'idempotent'=>true];
        try{$scope=$this->scope->resolve(null,$warehouseId);$issue=$this->issueMapping($scope['company_code'],$warehouseId,'GOODS_RECEIPT');if(!$issue)throw new InvalidArgumentException('Mapping AP Warehouse belum tersedia.');$pm=DB::table('finance_warehouse_payment_account_mappings')->where('payment_account_id',$payment->payment_account_id)->where('is_active',true)->first();if(!$pm)throw new InvalidArgumentException('Payment Account belum dipetakan ke COA Kas/Bank Finance.');$amount=round((float)$payment->amount,2);
            $posted=$this->generalPosting->stageSystem([
                'source_key'=>'FIN-PUR-WH-PAY:'.$payment->id,'source_code'=>'PURCHASING','source_module'=>'PURCHASING','source_identity'=>(string)$payment->id,
                'reference_no'=>$payment->payment_number,'company_code'=>$scope['company_code'],'outlet_id'=>$warehouseId,'marking'=>$issue->default_marking,
                'business_date'=>$payment->payment_date,'journal_date'=>$payment->payment_date,'description'=>'Pembayaran hutang Warehouse · '.$payment->payment_number,'payable'=>$amount,
                'metadata'=>['event_key'=>$eventKey,'document_source'=>$documentSource,'document_id'=>$documentId,'posting_kind'=>'WAREHOUSE_INCOMING_PAYMENT'],
            ],[[ 'account_id'=>(string)$issue->ap_account_id,'debit'=>$amount,'credit'=>0,'description'=>'Pelunasan Hutang Usaha'],['account_id'=>(string)$pm->cash_account_id,'debit'=>0,'credit'=>$amount,'description'=>'Sumber pembayaran']],$userId,true);
            $journalId=(string)$posted['journal_entry_id'];$journalNo=(string)$posted['journal_no'];DB::table('finance_purchasing_auto_events')->where('event_key',$eventKey)->update(['company_code'=>$scope['company_code'],'status'=>'POSTED','journal_entry_id'=>$journalId,'journal_no'=>$journalNo,'mapping_snapshot'=>json_encode(['issue_mapping_id'=>$issue->id,'warehouse_payment_mapping_id'=>$pm->id,'general_posting_id'=>$posted['general_posting_id']]),'last_error'=>null,'attempts'=>DB::raw('attempts+1'),'posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_at'=>now()]);return ['status'=>'POSTED','general_posting_id'=>$posted['general_posting_id'],'journal_no'=>$journalNo];}
        catch(Throwable $e){DB::table('finance_purchasing_auto_events')->where('event_key',$eventKey)->update(['status'=>'NEEDS_MAPPING','last_error'=>$e->getMessage(),'attempts'=>DB::raw('attempts+1'),'updated_at'=>now()]);return ['status'=>'NEEDS_MAPPING','message'=>$e->getMessage()];}
    }

    public function retryAutoEvent(string $id,?string $userId):array
    {
        $e=DB::table('finance_purchasing_auto_events')->where('id',$id)->first();if(!$e)throw new InvalidArgumentException('Auto event tidak ditemukan.');if($e->event_type==='STOCK_RECEIPT_RECOGNIZED')return $this->autoPostStockReceipt((string)$e->goods_receipt_id,$userId);if($e->source_type==='WH_V3_INVOICE_PAYMENT'){$p=DB::table('wh_v3_invoice_payments')->where('id',$e->payment_id)->first();if(!$p)throw new InvalidArgumentException('Payment source tidak ditemukan.');return $this->autoPostWarehouseIncomingPayment((string)$p->document_source,(string)$p->document_id,(string)$p->warehouse_id,(string)$p->idempotency_key,$userId);}throw new InvalidArgumentException('Tipe auto event belum mendukung retry langsung.');
    }

    private function createJournalForPosting(object $p,array $ctx,object $issue,?string $userId):string
    {
        $lines=[];
        if($ctx['event_type']==='INVOICE_PAYMENT_POSTED'){
            $pay=DB::table('finance_purchasing_payment_mappings')->where('id',$p->payment_mapping_id)->first();if(!$pay)throw new InvalidArgumentException('Payment mapping tidak tersedia.');$amount=round((float)$ctx['amount'],2);
            $lines=[['account_id'=>(string)$issue->ap_account_id,'debit'=>$amount,'credit'=>0,'description'=>'Pelunasan Account Payable'],['account_id'=>(string)$pay->cash_account_id,'debit'=>0,'credit'=>$amount,'description'=>'Kas/Bank pembayaran']];
        }else{
            $rows=DB::table('finance_purchasing_posting_lines')->where('purchasing_posting_id',$p->id)->get();$groups=$rows->groupBy('target_account_id');$total=0.0;
            foreach($groups as $accountId=>$g){$amount=round((float)$g->sum('subtotal_value'),2);if($amount>0){$lines[]=['account_id'=>(string)$accountId,'debit'=>$amount,'credit'=>0,'description'=>'Purchasing '.$ctx['source_document_number']];$total+=$amount;}}
            $tax=round((float)$rows->sum('tax_value'),2);if($tax>0){$lines[]=['account_id'=>(string)$issue->tax_account_id,'debit'=>$tax,'credit'=>0,'description'=>'PPN Masukan'];$total+=$tax;}
            $invoiceTotal=round((float)$ctx['amount'],2);if(abs($invoiceTotal-$total)>0.02){$lines=[['account_id'=>(string)$issue->default_debit_account_id,'debit'=>$invoiceTotal,'credit'=>0,'description'=>'Purchasing '.$ctx['source_document_number']]];}
            $lines[]=['account_id'=>(string)$issue->ap_account_id,'debit'=>0,'credit'=>$invoiceTotal,'description'=>'Account Payable'];
        }
        $posted=$this->generalPosting->stageSystem([
            'source_key'=>'FIN-PUR-OUTBOX:'.$p->event_key,'source_code'=>'PURCHASING','source_module'=>'PURCHASING','source_identity'=>(string)$p->id,
            'reference_no'=>$ctx['invoice_number'],'company_code'=>$p->company_code,'outlet_id'=>$p->outlet_id,'marking'=>(string)($p->marking ?? $ctx['marking'] ?? $issue->default_marking),
            'business_date'=>$p->business_date,'journal_date'=>$p->journal_date,'description'=>$ctx['event_type']==='INVOICE_PAYMENT_POSTED'?'Pembayaran '.$ctx['invoice_number']:'Liability '.$ctx['invoice_number'],'payable'=>round((float)$ctx['amount'],2),
            'metadata'=>['purchasing_posting_id'=>$p->id,'event_key'=>$p->event_key,'invoice_id'=>$p->invoice_id,'posting_kind'=>$ctx['event_type']],
        ],$lines,$userId,true);
        return (string)$posted['journal_entry_id'];
    }

    private function buildLines(string $postingId,array $ctx,object $issue):void
    {
        if($ctx['event_type']!=='INVOICE_ISSUED')return;$items=DB::table('pur_invoice_items')->where('invoice_id',$ctx['invoice_id'])->orderBy('line_no')->get();$rows=[];$now=now();$marking=(string)($ctx['marking']??$issue->default_marking);foreach($items as $i)$rows[]=['id'=>(string)Str::ulid(),'purchasing_posting_id'=>$postingId,'invoice_item_id'=>$i->id,'line_no'=>(int)$i->line_no,'item_name'=>$i->item_name,'sku_id'=>$i->sku_id,'subtotal_value'=>round((float)$i->subtotal,2),'tax_value'=>round((float)$i->tax_amount,2),'target_account_id'=>$issue->default_debit_account_id,'marking'=>$marking,'created_at'=>$now,'updated_at'=>$now];if($rows)DB::table('finance_purchasing_posting_lines')->insert($rows);DB::table('finance_purchasing_posting_allocations')->insert(['id'=>(string)Str::ulid(),'purchasing_posting_id'=>$postingId,'marking'=>$marking,'amount'=>$ctx['amount'],'created_at'=>$now,'updated_at'=>$now]);
    }

    private function outboxContext(string $id): array
    {
        $x = DB::table('pur_finance_posting_outbox')->where('id', $id)->first();
        if (! $x) throw new InvalidArgumentException('Outbox Purchasing tidak ditemukan.');

        $payment = null;
        $invoice = null;
        if ($x->aggregate_type === 'PURCHASING_INVOICE') {
            $invoice = DB::table('pur_invoices')->where('id', $x->aggregate_id)->whereNull('deleted_at')->first();
        } elseif ($x->aggregate_type === 'PURCHASING_INVOICE_PAYMENT') {
            $payment = DB::table('pur_invoice_payments')->where('id', $x->aggregate_id)->first();
            if ($payment) $invoice = DB::table('pur_invoices')->where('id', $payment->invoice_id)->whereNull('deleted_at')->first();
        }
        if (! $invoice) throw new InvalidArgumentException('Invoice sumber outbox tidak ditemukan.');
        if ($invoice->direction !== 'INCOMING') throw new InvalidArgumentException('Finance Purchasing Posting hanya memproses Account Payable dari Invoice Masuk.');

        $warehouseId = null;
        $warehouseName = null;
        if ($invoice->source_document_kind === 'WAREHOUSE_OUTGOING_INVOICE') {
            $w = DB::table('wh_v3_outgoing_invoices as wi')
                ->leftJoin('outlets as wh', 'wh.id', '=', 'wi.warehouse_id')
                ->where('wi.id', $invoice->source_document_id)
                ->first(['wi.warehouse_id', 'wh.name']);
            $warehouseId = $w?->warehouse_id;
            $warehouseName = $w?->name;
        }

        return [
            'outbox_id' => (string) $x->id,
            'event_key' => (string) $x->event_key,
            'event_type' => (string) $x->event_type,
            'invoice_id' => (string) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'payment_id' => $payment ? (string) $payment->id : null,
            'payment_method' => $payment ? strtoupper((string) $payment->payment_method) : null,
            'outlet_id' => $invoice->outlet_id ? (string) $invoice->outlet_id : null,
            // Corporate Fund Request has no outlet. After realization, company_code is
            // stored on SES/GR/SA/RP and becomes the authoritative PT hint for retry.
            'company_code' => $this->corporateCompanyForInvoice($invoice),
            'marking' => $this->markingForInvoice($invoice),
            'counterparty_name' => (string) $invoice->counterparty_name,
            'source_document_kind' => $this->effectiveSourceKind($invoice),
            'source_document_id' => (string) ($invoice->source_document_id ?: $invoice->id),
            'source_document_number' => (string) ($invoice->source_document_number ?: $invoice->invoice_number),
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'business_date' => (string) ($payment?->payment_date ?: $invoice->invoice_date),
            'amount' => round((float) ($payment?->amount ?? $invoice->total_amount), 2),
        ];
    }

    private function corporateCompanyForInvoice(object $invoice): ?string
    {
        if (! empty($invoice->outlet_id)) return null; // FinanceScopeResolver derives it from outlet mapping.

        $metadata = $this->invoiceMetadata($invoice);
        $active = $this->scope->activeCompanyCodes();
        $direct = strtoupper(trim((string) ($metadata['company_code'] ?? '')));
        if ($direct !== '' && in_array($direct, $active, true)) return $direct;

        [$orderKind, $orderId] = $this->invoiceOrderIdentity($invoice, $metadata);
        if ($orderId === '') return null;

        $orderTable = match ($orderKind) {
            'PURCHASE_ORDER' => 'pur_purchase_orders',
            'SERVICE_ORDER' => 'pur_service_orders',
            'REIMBURSE_ORDER' => 'pur_reimburse_orders',
            default => null,
        };
        if ($orderTable && Schema::hasTable($orderTable) && Schema::hasColumn($orderTable, 'company_code')) {
            $company = strtoupper(trim((string) DB::table($orderTable)->where('id', $orderId)->value('company_code')));
            if ($company !== '' && in_array($company, $active, true)) return $company;
        }

        $tables = match ($orderKind) {
            'PURCHASE_ORDER' => ['pur_service_entry_sheets', 'pur_goods_receipts'],
            'SERVICE_ORDER' => ['pur_service_acceptances'],
            'REIMBURSE_ORDER' => ['pur_reimburse_payments'],
            default => ['pur_service_entry_sheets', 'pur_goods_receipts', 'pur_service_acceptances', 'pur_reimburse_payments'],
        };
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_code') || ! Schema::hasColumn($table, 'order_id')) continue;
            $company = strtoupper(trim((string) DB::table($table)
                ->where('order_id', $orderId)
                ->whereNotNull('company_code')
                ->orderByDesc('updated_at')
                ->value('company_code')));
            if ($company !== '' && in_array($company, $active, true)) return $company;
        }

        if ($orderKind === 'REIMBURSE_ORDER' && Schema::hasTable('pur_reimburse_payables')) {
            $company = strtoupper(trim((string) DB::table('pur_reimburse_payables')->where('reimburse_order_id', $orderId)->value('company_code')));
            if ($company !== '' && in_array($company, $active, true)) return $company;
        }
        return null;
    }

    private function markingForInvoice(object $invoice): string
    {
        $metadata = $this->invoiceMetadata($invoice);
        $marking = strtoupper(trim((string) ($metadata['marking'] ?? '')));
        if (in_array($marking, ['MARKING', 'UNMARKING'], true)) return $marking;

        [$orderKind, $orderId] = $this->invoiceOrderIdentity($invoice, $metadata);
        $orderTable = match ($orderKind) {
            'PURCHASE_ORDER' => 'pur_purchase_orders',
            'SERVICE_ORDER' => 'pur_service_orders',
            'REIMBURSE_ORDER' => 'pur_reimburse_orders',
            default => null,
        };
        if ($orderTable && $orderId !== '' && Schema::hasTable($orderTable) && Schema::hasColumn($orderTable, 'marking')) {
            $marking = strtoupper(trim((string) DB::table($orderTable)->where('id', $orderId)->value('marking')));
            if (in_array($marking, ['MARKING', 'UNMARKING'], true)) return $marking;
        }

        return 'MARKING';
    }

    private function effectiveSourceKind(object $invoice): string
    {
        $subtype = strtoupper(trim((string) ($invoice->ap_order_subtype ?? '')));
        if ($subtype === 'ASSET') return 'ASSET_RECEIPT';
        return strtoupper(trim((string) ($invoice->source_document_kind ?: 'MANUAL')));
    }

    /** @return array<string,mixed> */
    private function invoiceMetadata(object $invoice): array
    {
        if (empty($invoice->metadata)) return [];
        $decoded = json_decode((string) $invoice->metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0:string,1:string} */
    private function invoiceOrderIdentity(object $invoice, array $metadata): array
    {
        return [
            strtoupper(trim((string) ($invoice->ap_order_kind ?? $metadata['order_kind'] ?? ''))),
            trim((string) ($invoice->ap_order_id ?? $metadata['order_id'] ?? $invoice->source_document_id ?? '')),
        ];
    }

    private function syncApLifecyclePostingState(string $eventKey,string $status,?string $journalNo,?string $error):void
    {
        if(Schema::hasTable('pur_order_ap_lifecycles')){
            DB::table('pur_order_ap_lifecycles')->where('recognition_event_key',$eventKey)->update([
                'recognition_posting_status'=>$status,
                'recognition_journal_no'=>$journalNo,
                'recognition_error'=>$status==='POSTED'?null:$error,
                'updated_at'=>now(),
            ]);
        }
        if(Schema::hasTable('pur_order_ap_settlements')){
            $update=['posting_status'=>$status,'journal_no'=>$journalNo,'posting_error'=>$status==='POSTED'?null:$error,'updated_at'=>now()];
            if(Schema::hasColumn('pur_order_ap_settlements','posted_at'))$update['posted_at']=$status==='POSTED'?now():null;
            DB::table('pur_order_ap_settlements')->where('posting_event_key',$eventKey)->update($update);
        }
    }

    private function coveredWarehouseReceipt(array $ctx):?object
    {
        if($ctx['event_type']!=='INVOICE_ISSUED'||$ctx['source_document_kind']!=='WAREHOUSE_OUTGOING_INVOICE')return null;$g=DB::table('wh_v3_outgoing_invoices')->where('id',$ctx['source_document_id'])->value('goods_receipt_id');if(!$g)return null;return DB::table('finance_purchasing_auto_events')->where('event_key','STOCK_REQUEST_GR:'.$g)->where('status','POSTED')->whereNotNull('journal_entry_id')->first();
    }

    private function ensureAutoEvent(string $key,string $type,string $sourceType,string $sourceId,?string $invoiceId,?string $paymentId,?string $grId,?string $outletId,?string $date,float $amount,array $payload):object
    {
        DB::table('finance_purchasing_auto_events')->insertOrIgnore(['id'=>(string)Str::ulid(),'event_key'=>$key,'event_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,'invoice_id'=>$invoiceId,'payment_id'=>$paymentId,'goods_receipt_id'=>$grId,'outlet_id'=>$outletId,'business_date'=>$date,'amount'=>$amount,'status'=>'PENDING','payload'=>json_encode($payload),'created_at'=>now(),'updated_at'=>now()]);return DB::table('finance_purchasing_auto_events')->where('event_key',$key)->first();
    }
    private function issueMapping(string $company,?string $outlet,string $kind):?object{
        $q=DB::table('finance_purchasing_posting_mappings')->where('company_code',$company)->where('source_document_kind',$kind)->where('is_active',true);
        if($outlet){$q->where(fn($x)=>$x->where('outlet_id',$outlet)->orWhereNull('outlet_id'))->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END');}
        else{$q->whereNull('outlet_id');}
        return $q->first();
    }
    private function paymentMapping(string $company,?string $outlet,string $method):?object{
        $q=DB::table('finance_purchasing_payment_mappings')->where('company_code',$company)->where('payment_method',strtoupper($method))->where('is_active',true);
        if($outlet){$q->where(fn($x)=>$x->where('outlet_id',$outlet)->orWhereNull('outlet_id'))->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END');}
        else{$q->whereNull('outlet_id');}
        return $q->first();
    }
    private function mappingKind(string $kind):string{return $kind==='WAREHOUSE_OUTGOING_INVOICE'?'GOODS_RECEIPT':(in_array($kind,['GOODS_RECEIPT','ASSET_RECEIPT','SERVICE_ACCEPTANCE','REIMBURSE_PAYMENT'],true)?$kind:'SERVICE_ACCEPTANCE');}
    private function fingerprint(array $ctx,object $issue,?object $pay):string{return hash('sha256',json_encode([$ctx,$issue->id,$pay?->id]));}
    private function assertMappingUnused(string $column,string $id):void{if(DB::table('finance_purchasing_postings')->where($column,$id)->exists())throw new InvalidArgumentException('Mapping sudah dipakai posting dan tidak boleh dihapus. Nonaktifkan mapping bila tidak dipakai lagi.');}
    private function assertCoa(string $id,array $types):void{$c=DB::table('finance_chart_of_accounts')->where('id',$id)->where('is_active',true)->where('is_postable',true)->first();if(!$c||!in_array((string)$c->account_type,$types,true))throw new InvalidArgumentException('COA tidak valid untuk fungsi mapping ini.');}
}
