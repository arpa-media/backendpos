<?php

namespace App\Services\Warehouse\FinanceV4;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WarehouseAutoPostingV4Service
{
    public function __construct(private readonly WarehouseGeneralPostingEngine $engine) {}

    public function dispatch(string $event, string $warehouseId, array $context, string $userId): array
    {
        return match ($event) {
            'purchase_request_approve' => $this->purchaseOrderGenerated($warehouseId,(string)$context['id'],$userId),
            'purchase_stock_receipt' => $this->purchaseStockReceipt($warehouseId,(string)$context['id'],$userId),
            'production_material_out' => $this->productionMaterialOut($warehouseId,(string)$context['id'],$userId),
            'production_finished_in' => $this->productionFinishedIn($warehouseId,(string)$context['id'],(string)$context['result_id'],$userId),
            'production_finish' => $this->productionFinish($warehouseId,(string)$context['id'],$userId),
            'delivery_dispatch' => $this->transferDispatch($warehouseId,(string)$context['id'],$userId),
            'transfer_receive' => $this->transferReceive($warehouseId,(string)$context['id'],$userId),
            'goods_receipt_complete' => $this->warehouseSaleByGoodsReceipt($warehouseId,(string)$context['id'],$userId),
            'sales_customer_complete' => $this->warehouseSaleBySalesOrder($warehouseId,(string)$context['id'],$userId),
            'invoice_payment' => $this->invoicePayment($warehouseId,(string)$context['direction'],(string)$context['source'],(string)$context['id'],(string)$context['idempotency_key'],$userId),
            'ledger_adjustment' => $this->ledgerAdjustment($warehouseId,(string)$context['idempotency_key'],$userId),
            'ledger_reverse' => $this->ledgerReversal($warehouseId,(string)$context['id'],(string)($context['reason'] ?? 'Warehouse ledger reversal'),$userId),
            default => throw ValidationException::withMessages(['finance_event'=>["Warehouse Finance auto-post event {$event} tidak dikenal."]]),
        };
    }

    private function purchaseOrderGenerated(string $warehouseId,string $purchaseRequestId,string $userId):array
    {
        $po=DB::table('wh_supplier_purchase_orders')->where('warehouse_id',$warehouseId)->where('purchase_request_id',$purchaseRequestId)->orderByDesc('created_at')->first();
        if(!$po) throw ValidationException::withMessages(['finance'=>['PO hasil approval Purchase Request tidak ditemukan.']]);
        $amount=round((float)$po->estimated_total,2);
        if($amount<=0) return ['skipped'=>'zero_amount'];
        return $this->post($warehouseId,'PURCHASE_ORDER_APPROVED',[
            'source_type'=>'PURCHASE_ORDER','source_id'=>(string)$po->id,'source_key'=>'WHV4:PO:APPROVED:'.$po->id,
            'reference_no'=>(string)$po->po_number,'business_date'=>$this->date($po->created_at ?? null),'currency_code'=>(string)($po->currency ?: 'IDR'),
            'amounts'=>['payable'=>$amount],'description'=>'PO approved menjadi hutang Warehouse',
            'metadata'=>['purchase_request_id'=>$purchaseRequestId,'auto_posting_version'=>5,'valuation_basis'=>'approved_po_estimated_total'],
        ],$userId);
    }

    private function purchaseStockReceipt(string $warehouseId,string $poId,string $userId):array
    {
        $po=DB::table('wh_supplier_purchase_orders')->where('id',$poId)->where('warehouse_id',$warehouseId)->first();
        if(!$po) abort(404,'Purchase Order Warehouse tidak ditemukan.');
        $results=[];
        // Backfill-safe: memastikan jurnal hutang PO ada walaupun approval terjadi sebelum Iterasi 05 dipasang.
        $results['po_approved']=$this->purchaseOrderGenerated($warehouseId,(string)$po->purchase_request_id,$userId);
        $estimated=round((float)$po->estimated_total,2);$actual=round((float)$po->actual_total,2);$delta=round($actual-$estimated,2);
        if(abs($delta)>0.009){
            $template=$delta>0?'PURCHASE_ORDER_ADJUST_UP':'PURCHASE_ORDER_ADJUST_DOWN';
            $results['po_revaluation']=$this->post($warehouseId,$template,[
                'source_type'=>'PURCHASE_ORDER','source_id'=>(string)$po->id,'source_key'=>'WHV4:PO:REVALUE:'.$po->id,
                'reference_no'=>(string)$po->po_number,'business_date'=>$this->date($po->updated_at ?? null),'currency_code'=>(string)($po->currency ?: 'IDR'),
                'amounts'=>['adjustment'=>abs($delta)],'description'=>'Penyesuaian hutang PO ke actual supplier total',
                'metadata'=>['estimated_total'=>$estimated,'actual_total'=>$actual,'delta'=>$delta,'auto_posting_version'=>5],
            ],$userId);
        }
        $stockIn=DB::table('wh_stock_ins')->where('purchase_order_id',$poId)->where('warehouse_id',$warehouseId)->first();
        if(!$stockIn?->ledger_posting_id) throw ValidationException::withMessages(['finance'=>['Stock In completed belum memiliki Warehouse Ledger posting.']]);
        $results['inventory']=$this->postLedger((string)$stockIn->ledger_posting_id,'PURCHASE_STOCK_RECEIPT',$userId,[
            'purchase_order_id'=>$poId,'stock_in_id'=>(string)$stockIn->id,'po_number'=>(string)$po->po_number,
        ]);
        return $results;
    }

    private function productionMaterialOut(string $warehouseId,string $requestId,string $userId):array
    {
        $r=DB::table('wh_v3_production_material_requests')->where('id',$requestId)->where('warehouse_id',$warehouseId)->first();
        if(!$r) abort(404,'Production Request Warehouse tidak ditemukan.');
        if(!$r->material_ledger_posting_id){
            $carryOnly=Schema::hasTable('wh_v7_production_material_allocations')
                && DB::table('wh_v7_production_material_allocations')->where('warehouse_id',$warehouseId)->where('production_id',$r->production_id)->exists()
                && (float)DB::table('wh_v7_production_material_allocations')->where('warehouse_id',$warehouseId)->where('production_id',$r->production_id)->sum('warehouse_issue_qty_base')<=0.0001;
            if($carryOnly) return ['skipped'=>'production_stock_carry_only','production_request_id'=>$requestId,'production_id'=>(string)$r->production_id];
            throw ValidationException::withMessages(['finance'=>['Production Request approved belum memiliki material ledger posting.']]);
        }
        return $this->postLedger((string)$r->material_ledger_posting_id,'PRODUCTION_MATERIAL_OUT',$userId,['production_request_id'=>$requestId,'production_id'=>(string)$r->production_id,
            'production_stock_carry_forward'=>Schema::hasTable('wh_v7_production_material_allocations')?round((float)DB::table('wh_v7_production_material_allocations')->where('production_id',$r->production_id)->sum('carry_in_qty_base'),4):0]);
    }

    private function productionFinishedIn(string $warehouseId,string $productionId,string $resultId,string $userId):array
    {
        $production=DB::table('wh_productions')->where('id',$productionId)->where('warehouse_id',$warehouseId)->first();
        $result=DB::table('wh_v3_production_results')->where('id',$resultId)->where('production_id',$productionId)->first();
        if(!$production||!$result) abort(404,'Production Result Warehouse tidak ditemukan.');
        $results=[];
        // Backfill-safe material WIP bila Production Request sudah approved sebelum cut-over Iterasi 05.
        $materialRequest=DB::table('wh_v3_production_material_requests')->where('production_id',$productionId)->where('warehouse_id',$warehouseId)->first();
        if($materialRequest?->material_ledger_posting_id){
            $results['material']=$this->postLedger((string)$materialRequest->material_ledger_posting_id,'PRODUCTION_MATERIAL_OUT',$userId,['production_request_id'=>(string)$materialRequest->id,'production_id'=>$productionId]);
        }
        $conversion=round((float)($production->labor_cost ?? 0)+(float)($production->overhead_cost ?? 0),2);
        if($conversion>0){
            $results['conversion_cost']=$this->post($warehouseId,'PRODUCTION_COST_ABSORPTION',[
                'source_type'=>'PRODUCTION_COST','source_id'=>$productionId,'source_key'=>'WHV4:PRODUCTION:COST:'.$productionId,
                'reference_no'=>(string)$production->production_number,'business_date'=>(string)$production->production_date,
                'amounts'=>['cost'=>$conversion],'description'=>'Kapitalisasi labor & overhead produksi ke WIP',
                'metadata'=>['labor_cost'=>(float)($production->labor_cost??0),'overhead_cost'=>(float)($production->overhead_cost??0),'auto_posting_version'=>5],
            ],$userId);
        }
        $ledgerId=(string)($result->ledger_posting_id ?? '');
        if($ledgerId===''){
            $ledgerId=(string)(DB::table('wh_ledger_postings')->where('warehouse_id',$warehouseId)->where('reference_type','wh_v3_production_result')->where('reference_id',$resultId)->where('movement_type','production_in')->value('id') ?? '');
        }
        if($ledgerId==='') throw ValidationException::withMessages(['finance'=>['Production Result approved belum memiliki production_in ledger posting.']]);
        $results['finished_goods']=$this->postLedger($ledgerId,'PRODUCTION_FINISHED_IN',$userId,['production_id'=>$productionId,'production_result_id'=>$resultId]);
        return $results;
    }

    private function productionFinish(string $warehouseId,string $productionId,string $userId):array
    {
        $p=DB::table('wh_productions')->where('id',$productionId)->where('warehouse_id',$warehouseId)->first();
        if(!$p) abort(404,'Production Warehouse tidak ditemukan.');
        $backfill=[];
        $materialRequest=DB::table('wh_v3_production_material_requests')->where('production_id',$productionId)->where('warehouse_id',$warehouseId)->first();
        if($materialRequest?->material_ledger_posting_id){
            $backfill['material']=$this->postLedger((string)$materialRequest->material_ledger_posting_id,'PRODUCTION_MATERIAL_OUT',$userId,['production_request_id'=>(string)$materialRequest->id,'production_id'=>$productionId]);
        }
        $conversion=round((float)($p->labor_cost??0)+(float)($p->overhead_cost??0),2);
        if($conversion>0){
            $backfill['conversion_cost']=$this->post($warehouseId,'PRODUCTION_COST_ABSORPTION',[
                'source_type'=>'PRODUCTION_COST','source_id'=>$productionId,'source_key'=>'WHV4:PRODUCTION:COST:'.$productionId,
                'reference_no'=>(string)$p->production_number,'business_date'=>(string)$p->production_date,'amounts'=>['cost'=>$conversion],
                'description'=>'Kapitalisasi labor & overhead produksi ke WIP','metadata'=>['labor_cost'=>(float)($p->labor_cost??0),'overhead_cost'=>(float)($p->overhead_cost??0),'auto_posting_version'=>5],
            ],$userId);
        }
        foreach(DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->get(['id','ledger_posting_id']) as $result){
            if($result->ledger_posting_id) $backfill['result:'.(string)$result->id]=$this->postLedger((string)$result->ledger_posting_id,'PRODUCTION_FINISHED_IN',$userId,['production_id'=>$productionId,'production_result_id'=>(string)$result->id]);
        }
        $costPool=round((float)$p->actual_input_value+$conversion,2);
        $output=round((float)$p->actual_output_value,2);$variance=round($costPool-$output,2);
        if(abs($variance)<=0.009) return ['skipped'=>'zero_variance','cost_pool'=>$costPool,'finished_value'=>$output,'backfill'=>$backfill];
        $template=$variance>0?'PRODUCTION_VARIANCE_LOSS':'PRODUCTION_VARIANCE_GAIN';
        $variancePosting=$this->post($warehouseId,$template,[
            'source_type'=>'PRODUCTION_VARIANCE','source_id'=>$productionId,'source_key'=>'WHV4:PRODUCTION:VARIANCE:'.$productionId,
            'reference_no'=>(string)$p->production_number,'business_date'=>(string)$p->production_date,
            'amounts'=>['variance'=>abs($variance)],'description'=>'Final variance Production Warehouse',
            'metadata'=>['cost_pool'=>$costPool,'finished_value'=>$output,'variance'=>$variance,'auto_posting_version'=>5],
        ],$userId);
        return ['variance'=>$variancePosting,'backfill'=>$backfill];
    }

    private function transferDispatch(string $warehouseId,string $prepareId,string $userId):array
    {
        $d=DB::table('wh_v3_delivery_orders')->where('warehouse_id',$warehouseId)->where('prepare_request_id',$prepareId)->orderByDesc('created_at')->first();
        if(!$d || (string)$d->source_type!=='transfer_stock') return ['skipped'=>'not_transfer'];
        $gr=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$d->id)->first();
        if(!$gr?->ledger_posting_id) throw ValidationException::withMessages(['finance'=>['Transfer Dispatch belum memiliki transfer_out ledger posting.']]);
        return $this->postLedger((string)$gr->ledger_posting_id,'TRANSFER_DISPATCH',$userId,['delivery_order_id'=>(string)$d->id,'transfer_order_id'=>(string)$d->source_id]);
    }

    private function transferReceive(string $warehouseId,string $deliveryId,string $userId):array
    {
        $gr=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->first();
        if(!$gr) abort(404,'Goods Receipt Transfer tidak ditemukan.');
        $ledgerId=(string)($gr->destination_ledger_posting_id ?? '');
        if($ledgerId==='') throw ValidationException::withMessages(['finance'=>['Receiving Warehouse tujuan belum memiliki transfer_in ledger posting.']]);
        $results=[];
        if($gr->ledger_posting_id){
            $results['dispatch']=$this->postLedger((string)$gr->ledger_posting_id,'TRANSFER_DISPATCH',$userId,['delivery_order_id'=>$deliveryId,'goods_receipt_id'=>(string)$gr->id,'backfill_from_receive'=>true]);
        }
        $results['receive']=$this->postLedger($ledgerId,'TRANSFER_RECEIVE',$userId,['delivery_order_id'=>$deliveryId,'goods_receipt_id'=>(string)$gr->id]);
        return $results;
    }

    private function warehouseSaleByGoodsReceipt(string $warehouseId,string $grId,string $userId):array
    {
        return $this->warehouseSale($warehouseId,$grId,null,$userId);
    }

    private function warehouseSaleBySalesOrder(string $warehouseId,string $salesOrderId,string $userId):array
    {
        $prepareId=DB::table('wh_v3_logistics_prepare_requests')->where('warehouse_id',$warehouseId)->where('source_type','sales_order')->where('source_id',$salesOrderId)->value('id');
        $deliveryId=$prepareId?DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$prepareId)->value('id'):null;
        $grId=$deliveryId?DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->value('id'):null;
        if(!$grId) throw ValidationException::withMessages(['finance'=>['GR Sales Customer tidak ditemukan setelah completion.']]);
        return $this->warehouseSale($warehouseId,(string)$grId,$salesOrderId,$userId);
    }

    private function warehouseSale(string $warehouseId,string $grId,?string $salesOrderId,string $userId):array
    {
        $g=DB::table('wh_v3_goods_receipts')->where('id',$grId)->where('warehouse_id',$warehouseId)->first();
        if(!$g) abort(404,'Goods Receipt Warehouse tidak ditemukan.');
        $delivery=DB::table('wh_v3_delivery_orders')->where('id',$g->delivery_order_id)->first();
        if(!$delivery || (string)$delivery->source_type==='transfer_stock') return ['skipped'=>'transfer_no_sale'];
        $invoice=DB::table('wh_v3_outgoing_invoices')->where('goods_receipt_id',$grId)->where('warehouse_id',$warehouseId)->first();
        if(!$invoice) throw ValidationException::withMessages(['finance'=>['Outgoing Invoice Warehouse belum terbentuk setelah GR Complete.']]);
        $revenue=round((float)$invoice->grand_total,2);$cogs=round((float)$invoice->stock_valuation_total,2);
        if($revenue<=0 && $cogs<=0) return ['skipped'=>'zero_sale'];
        return $this->post($warehouseId,'WAREHOUSE_SALE',[
            'source_type'=>'WAREHOUSE_SALE','source_id'=>(string)$invoice->id,'source_key'=>'WHV4:SALE:INVOICE:'.$invoice->id,
            'reference_no'=>(string)$invoice->invoice_number,'business_date'=>(string)$invoice->invoice_date,'currency_code'=>(string)($invoice->currency_code ?: 'IDR'),
            'amounts'=>['revenue'=>$revenue,'cogs'=>$cogs],'description'=>'Pengakuan penjualan dan HPP Warehouse',
            'metadata'=>['goods_receipt_id'=>$grId,'warehouse_ledger_posting_id'=>(string)($g->ledger_posting_id??''),'source_type'=>(string)$delivery->source_type,'source_id'=>(string)$delivery->source_id,'sales_order_id'=>$salesOrderId,'auto_posting_version'=>5],
        ],$userId);
    }

    private function invoicePayment(string $warehouseId,string $direction,string $source,string $documentId,string $idempotencyKey,string $userId):array
    {
        $backfill=[];
        if($direction==='outgoing' && $source==='auto_outgoing'){
            $invoice=DB::table('wh_v3_outgoing_invoices')->where('id',$documentId)->where('warehouse_id',$warehouseId)->first();
            if($invoice?->goods_receipt_id) $backfill['sale']=$this->warehouseSale($warehouseId,(string)$invoice->goods_receipt_id,null,$userId);
        }
        if($direction==='incoming' && $source==='auto_incoming'){
            $invoice=DB::table('wh_supplier_invoices')->where('id',$documentId)->where('warehouse_id',$warehouseId)->first();
            if($invoice?->purchase_order_id) $backfill['purchase']=$this->purchaseStockReceipt($warehouseId,(string)$invoice->purchase_order_id,$userId);
        }
        $payment=DB::table('wh_v3_invoice_payments')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('document_source',$source)->where('document_id',$documentId)->where('idempotency_key',$idempotencyKey)->first();
        if(!$payment) throw ValidationException::withMessages(['finance'=>['Payment lifecycle selesai tetapi payment row untuk auto-posting tidak ditemukan.']]);
        $snapshot=$this->json($payment->payment_account_snapshot ?? null);
        $haystack=strtoupper(implode(' ',[(string)($snapshot['code']??''),(string)($snapshot['name']??''),(string)($snapshot['bank_name']??''),(string)($snapshot['account_number']??'')]));
        $isBank=trim((string)($snapshot['bank_name']??''))!==''||trim((string)($snapshot['account_number']??''))!==''||preg_match('/BANK|TRANSFER|GIRO|VIRTUAL|\bVA\b/',$haystack)===1;
        $template=$direction==='incoming'?($isBank?'INVOICE_PAYMENT_BANK':'INVOICE_PAYMENT_CASH'):($isBank?'CUSTOMER_PAYMENT_BANK':'CUSTOMER_PAYMENT_CASH');
        $journal=$this->post($warehouseId,$template,[
            'source_type'=>$direction==='incoming'?'INCOMING_PAYMENT':'OUTGOING_PAYMENT','source_id'=>(string)$payment->id,'source_key'=>'WHV4:PAYMENT:'.$payment->id,
            'reference_no'=>(string)$payment->payment_number,'business_date'=>(string)$payment->payment_date,'amounts'=>['payment'=>(float)$payment->amount],
            'description'=>$direction==='incoming'?'Pembayaran hutang Warehouse':'Penerimaan piutang Warehouse',
            'metadata'=>['direction'=>$direction,'document_source'=>$source,'document_id'=>$documentId,'payment_account'=>$snapshot,'cash_or_bank'=>$isBank?'BANK':'CASH','auto_posting_version'=>5],
        ],$userId);
        return ['payment'=>$journal,'backfill'=>$backfill];
    }

    private function ledgerAdjustment(string $warehouseId,string $idempotencyKey,string $userId):array
    {
        $p=DB::table('wh_ledger_postings')->where('warehouse_id',$warehouseId)->where('idempotency_key',$idempotencyKey)->first();
        if(!$p) throw ValidationException::withMessages(['finance'=>['Warehouse Ledger adjustment tidak ditemukan untuk auto-posting.']]);
        $template=match((string)$p->movement_type){'adjustment_in'=>'STOCK_ADJUSTMENT_IN','adjustment_out'=>'STOCK_ADJUSTMENT_OUT',default=>null};
        return $template?$this->postLedger((string)$p->id,$template,$userId,['manual_adjustment'=>true]):['skipped'=>'movement_not_finance_adjustment'];
    }

    private function ledgerReversal(string $warehouseId,string $originalLedgerId,string $reason,string $userId):array
    {
        $original=DB::table('wh_ledger_postings')->where('id',$originalLedgerId)->where('warehouse_id',$warehouseId)->first();
        if(!$original) abort(404,'Warehouse Ledger posting tidak ditemukan.');
        if(!in_array((string)$original->movement_type,['adjustment_in','adjustment_out'],true)){
            return ['skipped'=>'business_document_ledger_reversal_not_auto_reversed'];
        }
        $finance=DB::table('wh_v4_finance_general_postings')->where('source_type','STOCK_MOVEMENT')->where('source_id',$originalLedgerId)->where('status','POSTED')->first();
        if(!$finance) return ['skipped'=>'finance_posting_not_found'];
        return $this->engine->reverse((string)$finance->id,[$warehouseId],$reason,$userId);
    }

    private function postLedger(string $ledgerPostingId,string $template,string $userId,array $metadata=[]):array
    {
        $p=DB::table('wh_ledger_postings')->where('id',$ledgerPostingId)->first();
        if(!$p || (string)$p->status!=='posted') throw ValidationException::withMessages(['finance'=>['Warehouse Ledger harus POSTED sebelum General Posting dibuat.']]);
        $value=round((float)DB::table('wh_ledger_entries')->where('posting_id',$ledgerPostingId)->sum('total_cost'),2);
        if($value<=0) return ['skipped'=>'zero_inventory_value','ledger_posting_id'=>$ledgerPostingId];
        return $this->post((string)$p->warehouse_id,$template,[
            'source_type'=>'STOCK_MOVEMENT','source_id'=>$ledgerPostingId,'source_key'=>'WHV4:LEDGER:'.$ledgerPostingId.':'.$template,
            'reference_no'=>(string)$p->reference_type.':'.(string)$p->reference_id,'business_date'=>(string)$p->business_date,
            'amounts'=>['inventory_value'=>$value],'description'=>'Warehouse stock movement → General Posting',
            'metadata'=>array_merge($metadata,['warehouse_ledger_posting_id'=>$ledgerPostingId,'movement_type'=>(string)$p->movement_type,'reference_type'=>(string)$p->reference_type,'reference_id'=>(string)$p->reference_id,'auto_posting_version'=>5]),
        ],$userId);
    }

    private function post(string $warehouseId,string $template,array $payload,string $userId):array
    {
        $draft=$this->engine->createFromTemplate($warehouseId,$template,$payload,$userId);
        $id=(string)($draft['id']??'');
        if($id==='') throw ValidationException::withMessages(['finance'=>['General Posting draft tidak mengembalikan ID.']]);
        return $this->engine->post($id,[$warehouseId],$userId);
    }

    private function date(mixed $value):string
    {
        if($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        $s=trim((string)$value);return $s!==''?substr($s,0,10):now('Asia/Jakarta')->toDateString();
    }

    private function json(mixed $value):array
    {
        if(is_array($value)) return $value;if(!is_string($value)||trim($value)==='') return [];
        $d=json_decode($value,true);return is_array($d)?$d:[];
    }
}
