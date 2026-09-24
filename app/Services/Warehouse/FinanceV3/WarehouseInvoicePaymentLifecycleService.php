<?php

namespace App\Services\Warehouse\FinanceV3;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseInvoicePaymentLifecycleService
{
    public function __construct(private readonly WarehouseFinanceV3Service $base) {}

    public function invoices(string $direction, string $warehouseId, array $filters): array
    {
        $this->assertDirection($direction);
        $this->normalizeLifecycle($direction, $warehouseId);
        $data = $this->base->invoices($direction, $warehouseId, $filters);
        $data['items'] = collect($data['items'] ?? [])->map(fn(array $r) => array_merge(
            $r,
            $this->paymentSnapshot($direction, (string)$r['document_source'], (string)$r['id'], $warehouseId)
        ))->values()->all();
        $money = $this->lifecycleMetrics($direction, $warehouseId, $filters);
        $data['metrics'] = array_merge($data['metrics'] ?? [], $money);
        return $data;
    }

    public function detail(string $direction, string $source, string $id, string $warehouseId): array
    {
        $this->assertDirection($direction);
        $this->normalizeLifecycle($direction, $warehouseId, $id);
        $detail = $this->base->detail($direction, $source, $id, $warehouseId);
        $detail = array_merge($detail, $this->paymentSnapshot($direction, $source, $id, $warehouseId));
        $detail['payments'] = $this->paymentHistory($direction, $source, $id, $warehouseId);
        $detail['can_pay'] = $direction === 'incoming'
            && in_array((string)$detail['status'], ['issued','partially_paid'], true)
            && (float)$detail['outstanding_amount'] > 0.009;
        $detail['can_confirm_payment'] = $direction === 'outgoing'
            && in_array((string)$detail['status'], ['issued','partially_paid'], true)
            && (float)$detail['outstanding_amount'] > 0.009;
        return $detail;
    }

    public function recordPayment(string $direction, string $source, string $id, string $warehouseId, array $payload, string $userId): array
    {
        $this->assertDirection($direction);
        $this->normalizeLifecycle($direction, $warehouseId, $id);

        return DB::transaction(function () use ($direction,$source,$id,$warehouseId,$payload,$userId): array {
            $invoice = $this->lockDocument($direction,$source,$id,$warehouseId);
            $key = trim((string)($payload['idempotency_key'] ?? '')) ?: ('whv3-pay:'.Str::uuid());
            $existing = DB::table('wh_v3_invoice_payments')->where('document_source',$source)->where('document_id',$id)->where('idempotency_key',$key)->first();
            if ($existing) return $this->detail($direction,$source,$id,$warehouseId);

            if (! in_array((string)$invoice->status, ['issued','partially_paid'], true)) {
                throw ValidationException::withMessages(['status'=>['Pembayaran hanya dapat dicatat pada invoice ISSUED atau PARTIALLY PAID.']]);
            }
            $snapshot = $this->paymentSnapshot($direction,$source,$id,$warehouseId);
            $amount = round((float)$payload['amount'],2);
            $outstanding = round((float)$snapshot['outstanding_amount'],2);
            if ($amount <= 0 || $amount > $outstanding + 0.009) {
                throw ValidationException::withMessages(['amount'=>['Nominal pembayaran tidak valid atau melebihi sisa tagihan.']]);
            }

            $account = $this->paymentAccount($warehouseId,(string)$payload['payment_account_id']);
            $actor = DB::table('users')->where('id',$userId)->first(['id','name','nisj']);
            $payerName = trim((string)($payload['payer_name'] ?? '')) ?: (string)($actor->name ?? 'User');
            $accountSnapshot = [
                'id'=>(string)$account->id,'code'=>$account->code,'name'=>$account->name,'bank_name'=>$account->bank_name,
                'account_name'=>$account->account_name,'account_number'=>$account->account_number,
            ];

            $legacyReference = $this->writeLegacyCanonicalPayment($source,$invoice,$warehouseId,$payload,$amount,$key,$userId);
            $paymentId=(string)Str::ulid();
            DB::table('wh_v3_invoice_payments')->insert([
                'id'=>$paymentId,'payment_number'=>$this->number($direction==='incoming'?'WH-PAY-IN':'WH-PAY-OUT'),
                'document_source'=>$source,'document_id'=>$id,'direction'=>$direction,'warehouse_id'=>$warehouseId,
                'payment_date'=>$payload['payment_date'],'payment_term_detail'=>trim((string)($payload['payment_term_detail']??''))?:null,
                'amount'=>$amount,'payment_account_id'=>$account->id,'payment_account_snapshot'=>json_encode($accountSnapshot),
                'payer_name_snapshot'=>$payerName,'payer_nisj_snapshot'=>$actor->nisj??null,'reference_number'=>$payload['reference_number']??null,
                'notes'=>$payload['notes']??null,'status'=>'posted','idempotency_key'=>$key,'confirmed_by_user_id'=>$userId,'confirmed_at'=>now(),
                'metadata'=>json_encode(['lifecycle_version'=>3,'legacy_reference'=>$legacyReference]),'created_at'=>now(),'updated_at'=>now(),
            ]);

            $this->applyPayment($direction,$source,$id,$warehouseId,$amount,$userId);
            $after=$this->paymentSnapshot($direction,$source,$id,$warehouseId);
            $this->event($source,$id,$direction,'payment_posted',(string)$invoice->status,(string)$after['status'],
                $direction==='incoming'?'Pembayaran Incoming Invoice dicatat.':'Pembayaran Outgoing Invoice dikonfirmasi.',
                $userId,['payment_id'=>$paymentId,'amount'=>$amount,'remaining'=>$after['outstanding_amount'],'payment_account'=>$accountSnapshot,'payer_name'=>$payerName]);
            return $this->detail($direction,$source,$id,$warehouseId);
        },5);
    }

    private function normalizeLifecycle(string $direction, string $warehouseId, ?string $id=null): void
    {
        if (Schema::hasTable('wh_v3_manual_invoices')) {
            DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction',$direction)
                ->whereRaw('ABS(balance_due - GREATEST(grand_total - COALESCE(paid_total,0),0)) > 0.01')
                ->update(['balance_due'=>DB::raw('GREATEST(grand_total - COALESCE(paid_total,0),0)'),'updated_at'=>now()]);
            $q=DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('status','approved');
            if ($id) $q->where('id',$id);
            $q->update(['status'=>'issued','issued_at'=>DB::raw('COALESCE(issued_at, updated_at)'),'updated_at'=>now()]);
        }
        if ($direction === 'outgoing' && Schema::hasTable('wh_v3_outgoing_invoices')) {
            DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)
                ->whereRaw('ABS(balance_due - GREATEST(grand_total - COALESCE(paid_total,0),0)) > 0.01')
                ->update(['balance_due'=>DB::raw('GREATEST(grand_total - COALESCE(paid_total,0),0)'),'updated_at'=>now()]);
        }
        if ($direction !== 'outgoing' || !Schema::hasTable('pur_invoices') || !Schema::hasTable('wh_v3_outgoing_invoices')) return;
        $q=DB::table('wh_v3_outgoing_invoices as w')
            ->join('pur_invoices as p',function($join):void{$join->on('p.source_document_id','=','w.id')->where('p.direction','=','INCOMING')->where('p.source_document_kind','=','WAREHOUSE_OUTGOING_INVOICE');})
            ->where('w.warehouse_id',$warehouseId)->where('w.status','approved')->whereIn('p.status',['ISSUED','PARTIALLY_PAID','PAID','MIRROR'])->whereNull('p.deleted_at');
        if ($id) $q->where('w.id',$id);
        $ids=$q->pluck('w.id');
        if($ids->isEmpty())return;
        DB::table('wh_v3_outgoing_invoices')->whereIn('id',$ids)->where('status','approved')->update(['status'=>'issued','issued_at'=>now(),'updated_at'=>now()]);
        foreach($ids as $docId)$this->event('auto_outgoing',(string)$docId,'outgoing','issued_from_purchasing','approved','issued','Outgoing Invoice diterbitkan setelah Invoice Masuk Purchasing di-issue.',null,[]);
    }

    private function lifecycleMetrics(string $direction,string $warehouseId,array $filters): array
    {
        $union=$direction==='incoming'?$this->incomingMetricUnion($warehouseId):$this->outgoingMetricUnion($warehouseId);
        $q=DB::query()->fromSub($union,'x');
        $this->applyMetricFilters($q,$filters);
        $row=$q->whereNotIn('status',['draft','void','cancelled'])
            ->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(grand_total),0) AS total_value, COALESCE(SUM(paid_total),0) AS paid_value, COALESCE(SUM(balance_due),0) AS balance_value')->first();
        return [
            'approved_invoice_count'=>(int)($row->invoice_count??0),
            'approved_invoice_value'=>round((float)($row->total_value??0),2),
            'paid_invoice_value'=>round((float)($row->paid_value??0),2),
            'outstanding_invoice_value'=>round((float)($row->balance_value??0),2),
        ];
    }

    private function incomingMetricUnion(string $warehouseId)
    {
        $auto=DB::table('wh_supplier_invoices as i')->leftJoin('pur_supplier_sources as p','p.id','=','i.supplier_source_id')->where('i.warehouse_id',$warehouseId)
            ->selectRaw("i.id,'auto_incoming' AS document_source,'supplier' AS party_type,i.supplier_source_id AS party_id,p.code AS party_code,p.name AS party_name,i.invoice_number,i.invoice_date,i.status,i.grand_total,COALESCE(i.paid_total,0) AS paid_total,COALESCE(i.balance_due,GREATEST(i.grand_total-COALESCE(i.paid_total,0),0)) AS balance_due,NULL AS source_number,NULL AS external_reference");
        $manual=DB::table('wh_v3_manual_invoices as m')->where('m.warehouse_id',$warehouseId)->where('m.direction','incoming')
            ->selectRaw("m.id,'manual' AS document_source,m.party_type,m.party_id,m.party_code_snapshot AS party_code,m.party_name_snapshot AS party_name,m.invoice_number,m.invoice_date,m.status,m.grand_total,COALESCE(m.paid_total,0) AS paid_total,COALESCE(m.balance_due,GREATEST(m.grand_total-COALESCE(m.paid_total,0),0)) AS balance_due,NULL AS source_number,m.external_reference");
        return $auto->unionAll($manual);
    }

    private function outgoingMetricUnion(string $warehouseId)
    {
        $auto=DB::table('wh_v3_outgoing_invoices as i')->where('i.warehouse_id',$warehouseId)->whereIn('i.destination_type',['outlet','customer'])
            ->selectRaw("i.id,'auto_outgoing' AS document_source,i.destination_type AS party_type,i.destination_id AS party_id,i.destination_code_snapshot AS party_code,i.destination_name_snapshot AS party_name,i.invoice_number,i.invoice_date,i.status,i.grand_total,COALESCE(i.paid_total,0) AS paid_total,COALESCE(i.balance_due,GREATEST(i.grand_total-COALESCE(i.paid_total,0),0)) AS balance_due,i.source_number,NULL AS external_reference");
        $legacy=DB::table('wh_sales_invoices as i')->leftJoin('wh_customers as c','c.id','=','i.customer_id')->where('i.warehouse_id',$warehouseId)
            ->selectRaw("i.id,'legacy_outgoing' AS document_source,'customer' AS party_type,i.customer_id AS party_id,c.code AS party_code,c.name AS party_name,i.invoice_number,i.invoice_date,i.status,i.grand_total,COALESCE(i.paid_total,0) AS paid_total,COALESCE(i.balance_due,GREATEST(i.grand_total-COALESCE(i.paid_total,0),0)) AS balance_due,NULL AS source_number,NULL AS external_reference");
        $manual=DB::table('wh_v3_manual_invoices as m')->where('m.warehouse_id',$warehouseId)->where('m.direction','outgoing')
            ->selectRaw("m.id,'manual' AS document_source,m.party_type,m.party_id,m.party_code_snapshot AS party_code,m.party_name_snapshot AS party_name,m.invoice_number,m.invoice_date,m.status,m.grand_total,COALESCE(m.paid_total,0) AS paid_total,COALESCE(m.balance_due,GREATEST(m.grand_total-COALESCE(m.paid_total,0),0)) AS balance_due,NULL AS source_number,m.external_reference");
        return $auto->unionAll($legacy)->unionAll($manual);
    }

    private function applyMetricFilters($q,array $f):void
    {
        if(!empty($f['date_from']))$q->where('invoice_date','>=',$f['date_from']);
        if(!empty($f['date_to']))$q->where('invoice_date','<=',$f['date_to']);
        if(!empty($f['status']))$q->where('status',$f['status']);
        if(!empty($f['party_type'])&&$f['party_type']!=='all')$q->where('party_type',$f['party_type']);
        if(!empty($f['party_id']))$q->where('party_id',$f['party_id']);
        if(!empty($f['q'])){$term='%'.trim((string)$f['q']).'%';$q->where(fn($x)=>$x->where('invoice_number','like',$term)->orWhere('party_name','like',$term)->orWhere('party_code','like',$term)->orWhere('source_number','like',$term)->orWhere('external_reference','like',$term));}
    }

    private function paymentSnapshot(string $direction,string $source,string $id,string $warehouseId):array
    {
        $row=match($source){
            'auto_incoming'=>DB::table('wh_supplier_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->first(['grand_total','paid_total','balance_due','status']),
            'auto_outgoing'=>DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->first(['grand_total','paid_total','balance_due','status']),
            'legacy_outgoing'=>DB::table('wh_sales_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->first(['grand_total','paid_total','balance_due','status']),
            'manual'=>DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('id',$id)->first(['grand_total','paid_total','balance_due','status']),
            default=>null,
        };
        if(!$row)return ['paid_amount'=>0.0,'outstanding_amount'=>0.0,'payment_percent'=>0.0,'status'=>'missing'];
        $grand=round((float)$row->grand_total,2);$paid=round((float)($row->paid_total??0),2);$balance=round((float)($row->balance_due??max($grand-$paid,0)),2);
        if(abs(($grand-$paid)-$balance)>0.01)$balance=max(round($grand-$paid,2),0);
        return ['paid_amount'=>$paid,'outstanding_amount'=>$balance,'payment_percent'=>$grand>0?round(min(100,$paid/$grand*100),2):0.0,'status'=>(string)$row->status];
    }

    private function paymentHistory(string $direction,string $source,string $id,string $warehouseId):array
    {
        $generic=Schema::hasTable('wh_v3_invoice_payments')?DB::table('wh_v3_invoice_payments as p')->leftJoin('users as u','u.id','=','p.confirmed_by_user_id')
            ->where('p.warehouse_id',$warehouseId)->where('p.direction',$direction)->where('p.document_source',$source)->where('p.document_id',$id)->where('p.status','posted')
            ->get(['p.*','u.name as confirmer_name']):collect();
        $rows=$generic->map(fn($p)=>['id'=>(string)$p->id,'payment_number'=>$p->payment_number,'payment_date'=>(string)$p->payment_date,'amount'=>(float)$p->amount,'payment_term_detail'=>$p->payment_term_detail,'payment_account'=>$this->json($p->payment_account_snapshot),'payer_name'=>$p->payer_name_snapshot,'reference_number'=>$p->reference_number,'notes'=>$p->notes,'confirmed_by'=>$p->confirmer_name,'confirmed_at'=>(string)$p->confirmed_at,'source'=>'v3'])->all();
        $linked=collect($generic)->map(fn($p)=>$this->json($p->metadata)['legacy_reference']['id']??null)->filter()->flip();
        if($source==='auto_incoming'&&Schema::hasTable('wh_supplier_payments')){
            $legacy=DB::table('wh_supplier_payment_allocations as a')->join('wh_supplier_payments as p','p.id','=','a.supplier_payment_id')->leftJoin('users as u','u.id','=','p.posted_by_user_id')->where('a.supplier_invoice_id',$id)->where('p.status','!=','void')->get(['p.*','a.allocated_amount','u.name as payer_name']);
            foreach($legacy as $p)if(!$linked->has((string)$p->id))$rows[]=['id'=>'legacy-'.(string)$p->id,'payment_number'=>$p->payment_number,'payment_date'=>(string)$p->payment_date,'amount'=>(float)$p->allocated_amount,'payment_term_detail'=>null,'payment_account'=>[],'payer_name'=>$p->payer_name,'reference_number'=>$p->reference_number,'notes'=>$p->notes,'confirmed_by'=>$p->payer_name,'confirmed_at'=>(string)$p->posted_at,'source'=>'legacy'];
        }
        if($source==='legacy_outgoing'&&Schema::hasTable('wh_customer_receipts')){
            $legacy=DB::table('wh_customer_receipt_allocations as a')->join('wh_customer_receipts as p','p.id','=','a.customer_receipt_id')->leftJoin('users as u','u.id','=','p.posted_by_user_id')->where('a.sales_invoice_id',$id)->where('p.status','!=','void')->get(['p.*','a.allocated_amount','u.name as payer_name']);
            foreach($legacy as $p)if(!$linked->has((string)$p->id))$rows[]=['id'=>'legacy-'.(string)$p->id,'payment_number'=>$p->receipt_number,'payment_date'=>(string)$p->receipt_date,'amount'=>(float)$p->allocated_amount,'payment_term_detail'=>null,'payment_account'=>[],'payer_name'=>$p->payer_name,'reference_number'=>$p->reference_number,'notes'=>$p->notes,'confirmed_by'=>$p->payer_name,'confirmed_at'=>(string)$p->posted_at,'source'=>'legacy'];
        }
        usort($rows,fn($a,$b)=>strcmp((string)$b['payment_date'],(string)$a['payment_date']));
        return $rows;
    }

    private function lockDocument(string $direction,string $source,string $id,string $warehouseId):object
    {
        $row=match($source){
            'auto_incoming'=>$direction==='incoming'?DB::table('wh_supplier_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first():null,
            'auto_outgoing'=>$direction==='outgoing'?DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first():null,
            'legacy_outgoing'=>$direction==='outgoing'?DB::table('wh_sales_invoices')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first():null,
            'manual'=>DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('id',$id)->lockForUpdate()->first(),
            default=>null,
        };
        if(!$row)throw ValidationException::withMessages(['invoice'=>['Invoice tidak ditemukan pada Warehouse aktif.']]);
        return $row;
    }

    private function writeLegacyCanonicalPayment(string $source,object $invoice,string $warehouseId,array $payload,float $amount,string $key,string $userId):?array
    {
        if($source==='auto_incoming'){
            $legacyKey='whv3:'.$key;$old=DB::table('wh_supplier_payments')->where('idempotency_key',$legacyKey)->first();if($old)return ['type'=>'supplier_payment','id'=>(string)$old->id];
            $pid=(string)Str::ulid();DB::table('wh_supplier_payments')->insert(['id'=>$pid,'payment_number'=>$this->number('WH-PAY'),'warehouse_id'=>$warehouseId,'supplier_source_id'=>$invoice->supplier_source_id,'payment_date'=>$payload['payment_date'],'payment_method'=>'transfer','reference_number'=>$payload['reference_number']??null,'amount'=>$amount,'status'=>'posted','idempotency_key'=>$legacyKey,'notes'=>$payload['notes']??null,'posted_by_user_id'=>$userId,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('wh_supplier_payment_allocations')->insert(['id'=>(string)Str::ulid(),'supplier_payment_id'=>$pid,'supplier_invoice_id'=>$invoice->id,'allocated_amount'=>$amount,'created_at'=>now(),'updated_at'=>now()]);
            $paid=round((float)$invoice->paid_total+$amount,2);$bal=max(round((float)$invoice->grand_total-$paid,2),0);DB::table('wh_supplier_invoices')->where('id',$invoice->id)->update(['paid_total'=>$paid,'balance_due'=>$bal,'status'=>$bal<=0.009?'paid':'partially_paid','updated_at'=>now()]);return ['type'=>'supplier_payment','id'=>$pid];
        }
        if($source==='legacy_outgoing'){
            $legacyKey='whv3:'.$key;$old=DB::table('wh_customer_receipts')->where('idempotency_key',$legacyKey)->first();if($old)return ['type'=>'customer_receipt','id'=>(string)$old->id];
            $rid=(string)Str::ulid();DB::table('wh_customer_receipts')->insert(['id'=>$rid,'receipt_number'=>$this->number('WH-RCPT'),'warehouse_id'=>$warehouseId,'customer_id'=>$invoice->customer_id,'receipt_date'=>$payload['payment_date'],'payment_method'=>'transfer','reference_number'=>$payload['reference_number']??null,'amount'=>$amount,'status'=>'posted','idempotency_key'=>$legacyKey,'posted_by_user_id'=>$userId,'posted_at'=>now(),'notes'=>$payload['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('wh_customer_receipt_allocations')->insert(['id'=>(string)Str::ulid(),'customer_receipt_id'=>$rid,'sales_invoice_id'=>$invoice->id,'allocated_amount'=>$amount,'created_at'=>now(),'updated_at'=>now()]);
            $paid=round((float)$invoice->paid_total+$amount,2);$bal=max(round((float)$invoice->grand_total-$paid,2),0);DB::table('wh_sales_invoices')->where('id',$invoice->id)->update(['paid_total'=>$paid,'balance_due'=>$bal,'status'=>$bal<=0.009?'paid':'partially_paid','updated_at'=>now()]);return ['type'=>'customer_receipt','id'=>$rid];
        }
        return null;
    }

    private function applyPayment(string $direction,string $source,string $id,string $warehouseId,float $amount,string $userId):void
    {
        if(in_array($source,['auto_incoming','legacy_outgoing'],true))return;
        $table=$source==='auto_outgoing'?'wh_v3_outgoing_invoices':'wh_v3_manual_invoices';$q=DB::table($table)->where('warehouse_id',$warehouseId)->where('id',$id);if($source==='manual')$q->where('direction',$direction);
        $row=$q->lockForUpdate()->first(['grand_total','paid_total']);if(!$row)throw ValidationException::withMessages(['invoice'=>['Invoice tidak ditemukan saat memperbarui saldo.']]);
        $paid=round((float)$row->paid_total+$amount,2);$bal=max(round((float)$row->grand_total-$paid,2),0);$status=$bal<=0.009?'paid':'partially_paid';$update=['paid_total'=>$paid,'balance_due'=>$bal,'status'=>$status,'paid_at'=>$status==='paid'?now():null,'updated_at'=>now()];if($source==='manual')$update['updated_by_user_id']=$userId;$q->update($update);
    }

    private function paymentAccount(string $warehouseId,string $id):object
    {
        $row=DB::table('wh_v3_payment_accounts')->where('id',$id)->where('is_active',true)->where(fn($q)=>$q->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'))->first();
        if(!$row)throw ValidationException::withMessages(['payment_account_id'=>['Rekening pembayaran/penerimaan tidak aktif atau tidak tersedia untuk Warehouse ini.']]);return $row;
    }
    private function event(string $source,string $id,string $direction,string $event,?string $from,?string $to,string $message,?string $actor,array $meta):void{if(!Schema::hasTable('wh_v3_finance_events'))return;DB::table('wh_v3_finance_events')->insert(['id'=>(string)Str::ulid(),'document_source'=>$source,'document_id'=>$id,'direction'=>$direction,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>json_encode($meta),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function json(mixed $v):array{if(is_array($v))return $v;$d=is_string($v)?json_decode($v,true):null;return is_array($d)?$d:[];}
    private function number(string $prefix):string{return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(7));}
    private function assertDirection(string $direction):void{if(!in_array($direction,['incoming','outgoing'],true))abort(404,'Arah invoice tidak dikenali.');}
}
