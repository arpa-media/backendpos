<?php

namespace App\Services\Warehouse\FinanceV4;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseTreasuryV4Service
{
    private const TYPES = ['cash_in','cash_out','bank_in','bank_out','book_transfer'];

    public function __construct(
        private readonly WarehouseGeneralPostingEngine $engine,
        private readonly WarehouseAutoPostingV4Service $autoPosting,
    ) {}

    public function options(string $warehouseId): array
    {
        $this->ensureDefaultCashAccount($warehouseId);
        $accountQuery = DB::table('wh_v3_payment_accounts')
            ->where('is_active', true)
            ->where(fn($q) => $q->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'));
        if (Schema::hasColumn('wh_v3_payment_accounts','is_treasury_enabled')) {
            $accountQuery->where('is_treasury_enabled', true);
        }
        $accounts = $accountQuery->orderBy('name')->get();

        $paymentAccounts = [];
        foreach ($accounts as $account) {
            $paymentAccounts[] = $this->paymentAccountSnapshot($this->ensurePaymentAccountCoa($account));
        }

        $counterAccounts = DB::table('wh_v4_finance_coa')
            ->where('is_active',true)->where('is_postable',true)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance'])
            ->map(fn($r)=>[
                'id'=>(string)$r->id,'code'=>$r->code,'name'=>$r->name,
                'account_type'=>$r->account_type,'normal_balance'=>$r->normal_balance,
            ])->values()->all();

        return [
            'payment_accounts'=>$paymentAccounts,
            'cash_accounts'=>array_values(array_filter($paymentAccounts,fn($x)=>$x['account_type']==='CASH')),
            'bank_accounts'=>array_values(array_filter($paymentAccounts,fn($x)=>$x['account_type']==='BANK')),
            'counter_accounts'=>$counterAccounts,
        ];
    }

    public function createPaymentAccount(string $warehouseId,array $payload,string $userId): array
    {
        $type=strtoupper((string)$payload['account_type']);
        if(!in_array($type,['CASH','BANK'],true)) throw ValidationException::withMessages(['account_type'=>['Tipe rekening harus CASH atau BANK.']]);
        $code=Str::upper(trim((string)($payload['code']??''))) ?: ($type.'-'.Str::upper(Str::random(5)));
        $base=$code;$i=1;
        while(DB::table('wh_v3_payment_accounts')->where('warehouse_id',$warehouseId)->where('code',$code)->exists()){
            $code=Str::limit($base,52,'').'-'.(++$i);
        }
        $id=(string)Str::ulid();$now=now();
        DB::table('wh_v3_payment_accounts')->insert([
            'id'=>$id,'warehouse_id'=>$warehouseId,'code'=>$code,'name'=>trim((string)$payload['name']),
            'bank_name'=>$type==='BANK'?($payload['bank_name']??null):null,
            'account_name'=>$payload['account_name']??null,'account_number'=>$type==='BANK'?($payload['account_number']??null):null,
            'account_type'=>$type,'finance_coa_id'=>null,'is_treasury_enabled'=>true,'is_active'=>true,
            'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return $this->paymentAccountSnapshot($this->ensurePaymentAccountCoa(DB::table('wh_v3_payment_accounts')->where('id',$id)->first()));
    }

    public function list(array $warehouseIds,string $type,array $filters): array
    {
        $this->assertType($type);
        $page=max(1,(int)($filters['page']??1));$per=min(100,max(10,(int)($filters['per_page']??25)));
        $q=DB::table('wh_v4_treasury_transactions as t')
            ->leftJoin('outlets as w','w.id','=','t.warehouse_id')
            ->leftJoin('users as c','c.id','=','t.created_by_user_id')
            ->leftJoin('users as a','a.id','=','t.approved_by_user_id')
            ->whereIn('t.warehouse_id',$warehouseIds)->where('t.transaction_type',$type);
        if(!empty($filters['status']))$q->where('t.status',strtoupper((string)$filters['status']));
        if(!empty($filters['date_from']))$q->where('t.transaction_date','>=',$filters['date_from']);
        if(!empty($filters['date_to']))$q->where('t.transaction_date','<=',$filters['date_to']);
        if(!empty($filters['q'])){$term='%'.trim((string)$filters['q']).'%';$q->where(fn($x)=>$x->where('t.treasury_number','like',$term)->orWhere('t.reference_number','like',$term)->orWhere('t.counterparty_name','like',$term)->orWhere('t.description','like',$term)->orWhere('w.name','like',$term));}
        $total=(clone $q)->count();
        $rows=$q->orderByDesc('t.transaction_date')->orderByDesc('t.created_at')->forPage($page,$per)->get([
            't.*','w.code as warehouse_code','w.name as warehouse_name','c.name as created_by_name','a.name as approved_by_name',
        ])->map(fn($r)=>$this->row($r,false))->values()->all();
        $metrics=DB::table('wh_v4_treasury_transactions')->whereIn('warehouse_id',$warehouseIds)->where('transaction_type',$type)
            ->selectRaw("COUNT(*) total_count, SUM(CASE WHEN status='APPROVED' THEN amount ELSE 0 END) approved_value, SUM(CASE WHEN status='SUBMITTED' THEN 1 ELSE 0 END) submitted_count")->first();
        return ['items'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>(int)ceil($total/$per)],'metrics'=>['total_count'=>(int)($metrics->total_count??0),'approved_value'=>(float)($metrics->approved_value??0),'submitted_count'=>(int)($metrics->submitted_count??0)]];
    }

    public function detail(string $id,array $warehouseIds): array
    {
        $r=DB::table('wh_v4_treasury_transactions as t')
            ->leftJoin('outlets as w','w.id','=','t.warehouse_id')
            ->leftJoin('users as c','c.id','=','t.created_by_user_id')
            ->leftJoin('users as s','s.id','=','t.submitted_by_user_id')
            ->leftJoin('users as a','a.id','=','t.approved_by_user_id')
            ->leftJoin('users as rj','rj.id','=','t.rejected_by_user_id')
            ->where('t.id',$id)->whereIn('t.warehouse_id',$warehouseIds)
            ->first(['t.*','w.code as warehouse_code','w.name as warehouse_name','c.name as created_by_name','s.name as submitted_by_name','a.name as approved_by_name','rj.name as rejected_by_name']);
        if(!$r) abort(404,'Dokumen Treasury Warehouse tidak ditemukan.');
        $data=$this->row($r,true);
        $data['events']=DB::table('wh_v4_treasury_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where('e.treasury_transaction_id',$id)->orderBy('e.occurred_at')->get(['e.*','u.name as actor_name'])->map(fn($e)=>['event_type'=>$e->event_type,'from_status'=>$e->from_status,'to_status'=>$e->to_status,'message'=>$e->message,'metadata'=>$this->json($e->metadata),'actor_name'=>$e->actor_name,'occurred_at'=>(string)$e->occurred_at])->values()->all();
        $data['general_posting']=$r->general_posting_id ? $this->engine->detail((string)$r->general_posting_id,$warehouseIds) : null;
        return $data;
    }

    public function create(string $warehouseId,string $type,array $payload,string $userId): array
    {
        $this->assertType($type);
        return DB::transaction(function()use($warehouseId,$type,$payload,$userId){
            $normalized=$this->normalizePayload($warehouseId,$type,$payload);
            $id=(string)Str::ulid();$number=$this->nextNumber($type);$now=now();
            DB::table('wh_v4_treasury_transactions')->insert(array_merge($normalized,[
                'id'=>$id,'treasury_number'=>$number,'warehouse_id'=>$warehouseId,'transaction_type'=>$type,
                'source_type'=>'MANUAL','source_id'=>null,'source_key'=>'WHV4:TREASURY:'.$id,'status'=>'DRAFT','auto_generated'=>false,'approval_mode'=>'MANUAL','general_posting_id'=>null,
                'metadata'=>json_encode(['treasury_version'=>6]),'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                'submitted_by_user_id'=>null,'submitted_at'=>null,'approved_by_user_id'=>null,'approved_at'=>null,'rejected_by_user_id'=>null,'rejected_at'=>null,'rejection_reason'=>null,
                'created_at'=>$now,'updated_at'=>$now,
            ]));
            $this->event($id,'created',null,'DRAFT','Dokumen Treasury dibuat.',$userId,[]);
            return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function update(string $id,string $warehouseId,string $type,array $payload,string $userId): array
    {
        $this->assertType($type);
        return DB::transaction(function()use($id,$warehouseId,$type,$payload,$userId){
            $row=DB::table('wh_v4_treasury_transactions')->where('id',$id)->where('warehouse_id',$warehouseId)->where('transaction_type',$type)->lockForUpdate()->first();
            if(!$row)abort(404,'Dokumen Treasury tidak ditemukan.');
            if($row->status!=='DRAFT'||$row->auto_generated)throw ValidationException::withMessages(['status'=>['Hanya dokumen manual DRAFT yang dapat diedit.']]);
            DB::table('wh_v4_treasury_transactions')->where('id',$id)->update(array_merge($this->normalizePayload($warehouseId,$type,$payload),['updated_by_user_id'=>$userId,'updated_at'=>now()]));
            $this->event($id,'updated','DRAFT','DRAFT','Dokumen Treasury diperbarui.',$userId,[]);
            return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function submit(string $id,string $warehouseId,string $type,string $userId):array
    {
        return DB::transaction(function()use($id,$warehouseId,$type,$userId){
            $row=$this->lock($id,$warehouseId,$type);if($row->status==='SUBMITTED')return $this->detail($id,[$warehouseId]);
            if($row->status!=='DRAFT')throw ValidationException::withMessages(['status'=>['Hanya DRAFT yang dapat disubmit.']]);
            DB::table('wh_v4_treasury_transactions')->where('id',$id)->update(['status'=>'SUBMITTED','submitted_by_user_id'=>$userId,'submitted_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'submitted','DRAFT','SUBMITTED','Dokumen Treasury diajukan untuk approval.',$userId,[]);return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function approve(string $id,string $warehouseId,string $type,string $userId):array
    {
        return DB::transaction(function()use($id,$warehouseId,$type,$userId){
            $row=$this->lock($id,$warehouseId,$type);if($row->status==='APPROVED')return $this->detail($id,[$warehouseId]);
            if($row->status!=='SUBMITTED')throw ValidationException::withMessages(['status'=>['Hanya SUBMITTED yang dapat di-approve.']]);
            $posting=$this->postManualTreasury($row,$userId);
            DB::table('wh_v4_treasury_transactions')->where('id',$id)->update(['status'=>'APPROVED','approved_by_user_id'=>$userId,'approved_at'=>now(),'general_posting_id'=>$posting['id'],'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'approved','SUBMITTED','APPROVED','Dokumen Treasury disetujui dan diposting ke General Posting Warehouse.',$userId,['general_posting_id'=>$posting['id'],'posting_no'=>$posting['posting_no']??null]);return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function reject(string $id,string $warehouseId,string $type,string $reason,string $userId):array
    {
        return DB::transaction(function()use($id,$warehouseId,$type,$reason,$userId){
            $row=$this->lock($id,$warehouseId,$type);if($row->status!=='SUBMITTED')throw ValidationException::withMessages(['status'=>['Hanya SUBMITTED yang dapat ditolak.']]);
            DB::table('wh_v4_treasury_transactions')->where('id',$id)->update(['status'=>'REJECTED','rejected_by_user_id'=>$userId,'rejected_at'=>now(),'rejection_reason'=>$reason,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'rejected','SUBMITTED','REJECTED','Dokumen Treasury ditolak.',$userId,['reason'=>$reason]);return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function cancel(string $id,string $warehouseId,string $type,string $userId):array
    {
        return DB::transaction(function()use($id,$warehouseId,$type,$userId){
            $row=$this->lock($id,$warehouseId,$type);if(!in_array($row->status,['DRAFT','REJECTED'],true)||$row->auto_generated)throw ValidationException::withMessages(['status'=>['Hanya dokumen manual DRAFT/REJECTED yang dapat dibatalkan.']]);
            DB::table('wh_v4_treasury_transactions')->where('id',$id)->update(['status'=>'CANCELLED','updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'cancelled',$row->status,'CANCELLED','Dokumen Treasury dibatalkan.',$userId,[]);return $this->detail($id,[$warehouseId]);
        },5);
    }

    public function syncInvoicePayment(string $warehouseId,string $direction,string $source,string $documentId,string $idempotencyKey,string $userId): array
    {
        return DB::transaction(function()use($warehouseId,$direction,$source,$documentId,$idempotencyKey,$userId){
            $payment=DB::table('wh_v3_invoice_payments')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('document_source',$source)->where('document_id',$documentId)->where('idempotency_key',$idempotencyKey)->first();
            if(!$payment)throw ValidationException::withMessages(['treasury'=>['Payment invoice sudah selesai tetapi payment row tidak ditemukan untuk auto-generate Treasury.']]);

            $account=DB::table('wh_v3_payment_accounts')->where('id',$payment->payment_account_id)->first();
            if(!$account)throw ValidationException::withMessages(['payment_account'=>['Payment account tidak ditemukan untuk Treasury.']]);
            $account=$this->ensurePaymentAccountCoa($account);
            $controlCode=$direction==='incoming'?'2100':'1100';
            $control=DB::table('wh_v4_finance_coa')->where('code',$controlCode)->where('is_active',true)->where('is_postable',true)->first();
            if(!$control)throw ValidationException::withMessages(['finance'=>["COA {$controlCode} untuk payment invoice tidak ditemukan."]]);
            $controlSnapshot=['id'=>(string)$control->id,'code'=>$control->code,'name'=>$control->name,'account_type'=>$control->account_type,'normal_balance'=>$control->normal_balance];

            // Preserve Iterasi 05 backfill semantics without using its generic Cash/Bank payment template.
            if($direction==='outgoing' && $source==='auto_outgoing'){
                $invoice=DB::table('wh_v3_outgoing_invoices')->where('id',$documentId)->where('warehouse_id',$warehouseId)->first();
                if($invoice?->goods_receipt_id)$this->autoPosting->dispatch('goods_receipt_complete',$warehouseId,['id'=>(string)$invoice->goods_receipt_id],$userId);
            }
            if($direction==='incoming' && $source==='auto_incoming'){
                $invoice=DB::table('wh_supplier_invoices')->where('id',$documentId)->where('warehouse_id',$warehouseId)->first();
                if($invoice?->purchase_order_id)$this->autoPosting->dispatch('purchase_stock_receipt',$warehouseId,['id'=>(string)$invoice->purchase_order_id],$userId);
            }

            $gpSourceKey='WHV4:PAYMENT:'.$payment->id;
            $gp=DB::table('wh_v4_finance_general_postings')->where('source_key',$gpSourceKey)->first();
            if(!$gp){
                $amount=round((float)$payment->amount,2);
                $lines=$direction==='incoming'
                    ? [
                        ['account_id'=>(string)$control->id,'description'=>'Pelunasan hutang invoice '.$documentId,'debit'=>$amount,'credit'=>0],
                        ['account_id'=>(string)$account->finance_coa_id,'description'=>'Pembayaran dari '.$account->name,'debit'=>0,'credit'=>$amount],
                    ]
                    : [
                        ['account_id'=>(string)$account->finance_coa_id,'description'=>'Penerimaan ke '.$account->name,'debit'=>$amount,'credit'=>0],
                        ['account_id'=>(string)$control->id,'description'=>'Pelunasan piutang invoice '.$documentId,'debit'=>0,'credit'=>$amount],
                    ];
                $draft=$this->engine->createManualDraft($warehouseId,[
                    'source_key'=>$gpSourceKey,'reference_no'=>(string)$payment->payment_number,'business_date'=>(string)$payment->payment_date,'journal_date'=>(string)$payment->payment_date,'currency_code'=>'IDR',
                    'description'=>$direction==='incoming'?'Pembayaran hutang Warehouse':'Penerimaan piutang Warehouse','notes'=>$payment->notes,'lines'=>$lines,
                ],$userId);
                DB::table('wh_v4_finance_general_postings')->where('id',$draft['id'])->update([
                    'source_type'=>$direction==='incoming'?'INCOMING_PAYMENT':'OUTGOING_PAYMENT','source_id'=>(string)$payment->id,
                    'metadata'=>json_encode(['origin'=>'treasury_invoice_payment_v6','direction'=>$direction,'document_source'=>$source,'document_id'=>$documentId,'payment_account_id'=>(string)$account->id,'payment_account_coa_id'=>(string)$account->finance_coa_id,'treasury_version'=>6]),
                    'updated_by_user_id'=>$userId,'updated_at'=>now(),
                ]);
                $posted=$this->engine->post((string)$draft['id'],[$warehouseId],$userId);
                $gp=(object)['id'=>$posted['id'],'posting_no'=>$posted['posting_no'],'status'=>$posted['status']];
            }elseif((string)$gp->status==='DRAFT'){
                $posted=$this->engine->post((string)$gp->id,[$warehouseId],$userId);
                $gp=(object)['id'=>$posted['id'],'posting_no'=>$posted['posting_no'],'status'=>$posted['status']];
            }

            $sourceKey='WHV4:TREASURY:PAYMENT:'.$payment->id;
            $existing=DB::table('wh_v4_treasury_transactions')->where('source_key',$sourceKey)->first();
            if($existing)return $this->detail((string)$existing->id,[$warehouseId]);

            $type=(string)$account->account_type==='BANK'?($direction==='incoming'?'bank_out':'bank_in'):($direction==='incoming'?'cash_out':'cash_in');
            $id=(string)Str::ulid();$snap=$this->paymentAccountSnapshot($account);$now=now();
            $counterparty=trim((string)($payment->payer_name_snapshot??'')) ?: null;
            DB::table('wh_v4_treasury_transactions')->insert([
                'id'=>$id,'treasury_number'=>$this->nextNumber($type),'warehouse_id'=>$warehouseId,'transaction_type'=>$type,'transaction_date'=>$payment->payment_date,'amount'=>$payment->amount,'currency_code'=>'IDR',
                'from_payment_account_id'=>str_ends_with($type,'_out')?(string)$account->id:null,'to_payment_account_id'=>str_ends_with($type,'_in')?(string)$account->id:null,'counter_account_id'=>(string)$control->id,
                'from_account_snapshot'=>str_ends_with($type,'_out')?json_encode($snap):null,'to_account_snapshot'=>str_ends_with($type,'_in')?json_encode($snap):null,'counter_account_snapshot'=>json_encode($controlSnapshot),
                'counterparty_name'=>$counterparty,'reference_number'=>$payment->reference_number,'source_type'=>'INVOICE_PAYMENT','source_id'=>(string)$payment->id,'source_key'=>$sourceKey,
                'description'=>$direction==='incoming'?'Pembayaran Incoming Invoice Warehouse':'Penerimaan Outgoing Invoice Warehouse','notes'=>$payment->notes,'status'=>'APPROVED','auto_generated'=>true,'approval_mode'=>'SOURCE_PAYMENT','general_posting_id'=>$gp->id,
                'metadata'=>json_encode(['treasury_version'=>6,'direction'=>$direction,'document_source'=>$source,'document_id'=>$documentId,'invoice_payment_id'=>(string)$payment->id,'payment_number'=>$payment->payment_number]),
                'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'submitted_by_user_id'=>$userId,'submitted_at'=>$now,'approved_by_user_id'=>$userId,'approved_at'=>$now,'rejected_by_user_id'=>null,'rejected_at'=>null,'rejection_reason'=>null,'created_at'=>$now,'updated_at'=>$now,
            ]);
            $this->event($id,'auto_generated',null,'APPROVED','Treasury otomatis dibuat dari payment invoice dan ditautkan ke General Posting payment.',$userId,['payment_id'=>(string)$payment->id,'general_posting_id'=>(string)$gp->id]);
            return $this->detail($id,[$warehouseId]);
        },5);
    }

    private function normalizePayload(string $warehouseId,string $type,array $payload):array
    {
        $amount=round((float)$payload['amount'],2);if($amount<=0)throw ValidationException::withMessages(['amount'=>['Nominal harus lebih dari 0.']]);
        $from=null;$to=null;$counter=null;$fromSnap=null;$toSnap=null;$counterSnap=null;
        if(in_array($type,['cash_out','bank_out','book_transfer'],true)){$from=$this->accountForType($warehouseId,(string)($payload['from_payment_account_id']??''),$type==='cash_out'?'CASH':'BANK');$fromSnap=$this->paymentAccountSnapshot($from);}
        if(in_array($type,['cash_in','bank_in','book_transfer'],true)){$to=$this->accountForType($warehouseId,(string)($payload['to_payment_account_id']??''),$type==='cash_in'?'CASH':'BANK');$toSnap=$this->paymentAccountSnapshot($to);}
        if($type==='book_transfer' && (string)$from->id===(string)$to->id)throw ValidationException::withMessages(['to_payment_account_id'=>['Rekening asal dan tujuan Book Transfer harus berbeda.']]);
        if($type!=='book_transfer'){
            $counterId=trim((string)($payload['counter_account_id']??''));$counter=DB::table('wh_v4_finance_coa')->where('id',$counterId)->where('is_active',true)->where('is_postable',true)->first();
            if(!$counter)throw ValidationException::withMessages(['counter_account_id'=>['Counter COA aktif dan postable wajib dipilih.']]);
            $paymentCoa=(string)(($from??$to)->finance_coa_id??'');if($paymentCoa===(string)$counter->id)throw ValidationException::withMessages(['counter_account_id'=>['Counter COA harus berbeda dengan COA rekening Kas/Bank.']]);
            $counterSnap=['id'=>(string)$counter->id,'code'=>$counter->code,'name'=>$counter->name,'account_type'=>$counter->account_type,'normal_balance'=>$counter->normal_balance];
        }
        return ['transaction_date'=>$payload['transaction_date'],'amount'=>$amount,'currency_code'=>strtoupper((string)($payload['currency_code']??'IDR')),
            'from_payment_account_id'=>$from?->id,'to_payment_account_id'=>$to?->id,'counter_account_id'=>$counter?->id,
            'from_account_snapshot'=>$fromSnap?json_encode($fromSnap):null,'to_account_snapshot'=>$toSnap?json_encode($toSnap):null,'counter_account_snapshot'=>$counterSnap?json_encode($counterSnap):null,
            'counterparty_name'=>trim((string)($payload['counterparty_name']??''))?:null,'reference_number'=>trim((string)($payload['reference_number']??''))?:null,'description'=>trim((string)($payload['description']??''))?:null,'notes'=>trim((string)($payload['notes']??''))?:null];
    }

    private function postManualTreasury(object $row,string $userId):array
    {
        $type=(string)$row->transaction_type;$amount=(float)$row->amount;$lines=[];
        $from=$row->from_payment_account_id?$this->ensurePaymentAccountCoa(DB::table('wh_v3_payment_accounts')->where('id',$row->from_payment_account_id)->first()):null;
        $to=$row->to_payment_account_id?$this->ensurePaymentAccountCoa(DB::table('wh_v3_payment_accounts')->where('id',$row->to_payment_account_id)->first()):null;
        $counter=$row->counter_account_id?DB::table('wh_v4_finance_coa')->where('id',$row->counter_account_id)->first():null;
        if($type==='book_transfer'){
            if(!$from||!$to)throw ValidationException::withMessages(['account'=>['Rekening Book Transfer tidak lengkap.']]);
            $lines[]=['account_id'=>(string)$to->finance_coa_id,'description'=>'Book Transfer masuk ke '.$to->name,'debit'=>$amount,'credit'=>0];
            $lines[]=['account_id'=>(string)$from->finance_coa_id,'description'=>'Book Transfer keluar dari '.$from->name,'debit'=>0,'credit'=>$amount];
        } elseif(in_array($type,['cash_in','bank_in'],true)){
            if(!$to||!$counter)throw ValidationException::withMessages(['account'=>['Rekening penerimaan / counter COA tidak lengkap.']]);
            $lines[]=['account_id'=>(string)$to->finance_coa_id,'description'=>'Penerimaan '.$to->name,'debit'=>$amount,'credit'=>0];
            $lines[]=['account_id'=>(string)$counter->id,'description'=>$row->description ?: 'Counter penerimaan','debit'=>0,'credit'=>$amount];
        } else {
            if(!$from||!$counter)throw ValidationException::withMessages(['account'=>['Rekening pembayaran / counter COA tidak lengkap.']]);
            $lines[]=['account_id'=>(string)$counter->id,'description'=>$row->description ?: 'Counter pengeluaran','debit'=>$amount,'credit'=>0];
            $lines[]=['account_id'=>(string)$from->finance_coa_id,'description'=>'Pengeluaran '.$from->name,'debit'=>0,'credit'=>$amount];
        }
        $draft=$this->engine->createManualDraft((string)$row->warehouse_id,[
            'source_key'=>'WHV4:TREASURY:'.$row->id,'reference_no'=>$row->treasury_number,'business_date'=>(string)$row->transaction_date,'journal_date'=>(string)$row->transaction_date,'currency_code'=>$row->currency_code,
            'description'=>trim((string)$row->description)?:$this->typeLabel($type).' '.$row->treasury_number,'notes'=>$row->notes,'lines'=>$lines,
        ],$userId);
        DB::table('wh_v4_finance_general_postings')->where('id',$draft['id'])->update([
            'source_type'=>'TREASURY','source_id'=>(string)$row->id,
            'metadata'=>json_encode(['origin'=>'treasury_v4','treasury_transaction_id'=>(string)$row->id,'transaction_type'=>$type,'from_payment_account_id'=>$row->from_payment_account_id,'to_payment_account_id'=>$row->to_payment_account_id,'counter_account_id'=>$row->counter_account_id,'treasury_version'=>6]),
            'updated_by_user_id'=>$userId,'updated_at'=>now(),
        ]);
        return $this->engine->post((string)$draft['id'],[(string)$row->warehouse_id],$userId);
    }

    private function accountForType(string $warehouseId,string $id,string $expected):object
    {
        $row=DB::table('wh_v3_payment_accounts')->where('id',$id)->where('is_active',true)->where(fn($q)=>$q->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'))->first();
        if(!$row)throw ValidationException::withMessages(['payment_account_id'=>['Rekening aktif tidak ditemukan untuk Warehouse ini.']]);
        $row=$this->ensurePaymentAccountCoa($row);if((string)$row->account_type!==$expected)throw ValidationException::withMessages(['payment_account_id'=>["Menu ini membutuhkan rekening {$expected}."]]);return $row;
    }

    private function ensureDefaultCashAccount(string $warehouseId):void
    {
        $q=DB::table('wh_v3_payment_accounts')->where('is_active',true)->where(fn($x)=>$x->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'));
        if(Schema::hasColumn('wh_v3_payment_accounts','account_type') && (clone $q)->where('account_type','CASH')->exists())return;
        $legacy=(clone $q)->whereNull('bank_name')->whereNull('account_number')->first();if($legacy){$this->ensurePaymentAccountCoa($legacy);return;}
        $id=(string)Str::ulid();$now=now();DB::table('wh_v3_payment_accounts')->insert(['id'=>$id,'warehouse_id'=>$warehouseId,'code'=>'CASH','name'=>'Kas Warehouse','bank_name'=>null,'account_name'=>'Kas Warehouse','account_number'=>null,'account_type'=>'CASH','finance_coa_id'=>null,'is_treasury_enabled'=>true,'is_active'=>true,'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $this->ensurePaymentAccountCoa(DB::table('wh_v3_payment_accounts')->where('id',$id)->first());
    }

    private function ensurePaymentAccountCoa(?object $account):object
    {
        if(!$account)throw ValidationException::withMessages(['payment_account'=>['Payment Account tidak ditemukan.']]);
        $type=strtoupper(trim((string)($account->account_type??'')));if(!in_array($type,['CASH','BANK'],true)){$hay=strtoupper(implode(' ',[(string)($account->code??''),(string)($account->name??''),(string)($account->bank_name??''),(string)($account->account_number??'')]));$type=((string)($account->bank_name??'')!==''||(string)($account->account_number??'')!==''||preg_match('/BANK|BCA|BRI|BNI|MANDIRI|CIMB|PERMATA|TRANSFER|GIRO|VA/',$hay))?'BANK':'CASH';}
        $coaId=(string)($account->finance_coa_id??'');$coa=$coaId!==''?DB::table('wh_v4_finance_coa')->where('id',$coaId)->where('is_active',true)->where('is_postable',true)->first():null;
        if(!$coa){
            $prefix=$type==='BANK'?'1020':'1010';$token=preg_replace('/[^A-Z0-9]+/','',strtoupper((string)($account->code??''))) ?: substr((string)$account->id,-8);$token=substr($token,0,16);$code=$prefix.'.'.$token;$i=1;while(DB::table('wh_v4_finance_coa')->where('code',$code)->exists()){$code=$prefix.'.'.substr($token,0,12).(++$i);}
            $coaId=(string)Str::ulid();DB::table('wh_v4_finance_coa')->insert(['id'=>$coaId,'code'=>$code,'name'=>($type==='BANK'?'Bank - ':'Kas - ').$account->name,'account_type'=>'ASSET','normal_balance'=>'DEBIT','is_header'=>false,'is_postable'=>true,'is_active'=>true,'sort_order'=>$type==='BANK'?1025:1015,'description'=>'Auto sub-account Treasury untuk Payment Account '.$account->name,'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('wh_v3_payment_accounts')->where('id',$account->id)->update(['account_type'=>$type,'finance_coa_id'=>$coaId,'is_treasury_enabled'=>true,'updated_at'=>now()]);
        return DB::table('wh_v3_payment_accounts')->where('id',$account->id)->first();
    }

    private function paymentAccountSnapshot(object $a):array
    {
        $coa=$a->finance_coa_id?DB::table('wh_v4_finance_coa')->where('id',$a->finance_coa_id)->first(['id','code','name']):null;
        return ['id'=>(string)$a->id,'warehouse_id'=>$a->warehouse_id?(string)$a->warehouse_id:null,'code'=>$a->code,'name'=>$a->name,'account_type'=>$a->account_type,'bank_name'=>$a->bank_name,'account_name'=>$a->account_name,'account_number'=>$a->account_number,'finance_coa_id'=>$a->finance_coa_id,'finance_coa'=>$coa?['id'=>(string)$coa->id,'code'=>$coa->code,'name'=>$coa->name]:null];
    }

    private function lock(string $id,string $warehouseId,?string $type=null):object{$q=DB::table('wh_v4_treasury_transactions')->where('id',$id)->where('warehouse_id',$warehouseId);if($type!==null)$q->where('transaction_type',$type);$r=$q->lockForUpdate()->first();if(!$r)abort(404,'Dokumen Treasury tidak ditemukan.');return$r;}
    private function assertType(string $type):void{if(!in_array($type,self::TYPES,true))abort(404,'Jenis Treasury tidak dikenali.');}
    private function nextNumber(string $type):string{$prefix=match($type){'cash_in'=>'WH-CIN','cash_out'=>'WH-COUT','bank_in'=>'WH-BIN','bank_out'=>'WH-BOUT','book_transfer'=>'WH-BTR'};do{$n=$prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));}while(DB::table('wh_v4_treasury_transactions')->where('treasury_number',$n)->exists());return$n;}
    private function typeLabel(string $type):string{return match($type){'cash_in'=>'Cash-In','cash_out'=>'Cash-Out','bank_in'=>'Bank-In','bank_out'=>'Bank-Out','book_transfer'=>'Book Transfer',default=>$type};}
    private function event(string $id,string $event,?string $from,?string $to,string $message,?string $actor,array $meta):void{DB::table('wh_v4_treasury_events')->insert(['id'=>(string)Str::ulid(),'treasury_transaction_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>json_encode($meta),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function row(object $r,bool $detail):array{return ['id'=>(string)$r->id,'treasury_number'=>$r->treasury_number,'warehouse_id'=>(string)$r->warehouse_id,'warehouse'=>['code'=>$r->warehouse_code??null,'name'=>$r->warehouse_name??null],'transaction_type'=>$r->transaction_type,'transaction_date'=>(string)$r->transaction_date,'amount'=>(float)$r->amount,'currency_code'=>$r->currency_code,'from_account'=>$this->json($r->from_account_snapshot),'to_account'=>$this->json($r->to_account_snapshot),'counter_account'=>$this->json($r->counter_account_snapshot),'counterparty_name'=>$r->counterparty_name,'reference_number'=>$r->reference_number,'source_type'=>$r->source_type,'source_id'=>$r->source_id,'description'=>$r->description,'notes'=>$r->notes,'status'=>$r->status,'auto_generated'=>(bool)$r->auto_generated,'approval_mode'=>$r->approval_mode,'general_posting_id'=>$r->general_posting_id,'created_by_name'=>$r->created_by_name??null,'submitted_by_name'=>$r->submitted_by_name??null,'approved_by_name'=>$r->approved_by_name??null,'rejected_by_name'=>$r->rejected_by_name??null,'submitted_at'=>(string)($r->submitted_at??''),'approved_at'=>(string)($r->approved_at??''),'rejected_at'=>(string)($r->rejected_at??''),'rejection_reason'=>$r->rejection_reason??null,'metadata'=>$detail?$this->json($r->metadata):[],'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at];}
    private function json(mixed $v):array{if(is_array($v))return$v;if(!is_string($v)||trim($v)==='')return[];$d=json_decode($v,true);return is_array($d)?$d:[];}
}
