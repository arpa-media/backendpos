<?php
namespace App\Services\Purchasing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PurchasingLegacyReconciliationService
{
    private array $counts = [];
    private ?string $activeRunId = null;

    public function __construct(
        private readonly LegacyDocumentLinkService $links,
        private readonly FundRequestSourceBridgeService $fundBridge,
    ) {}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function run(array $options, ?User $actor = null): array
    {
        $mode = strtoupper((string)($options['mode'] ?? 'DRY_RUN')) === 'APPLY' ? 'APPLY' : 'DRY_RUN';
        $scope = strtoupper((string)($options['source_scope'] ?? 'ALL'));
        if (!in_array($scope, ['ALL','STOCK','WAREHOUSE'], true)) $scope = 'ALL';
        $limit = min(max((int)($options['limit'] ?? 500), 1), 5000);
        $runId = (string)Str::ulid();
        $runNumber = 'RECON-'.now()->format('Ymd-His').'-'.strtoupper(substr($runId, -5));
        $this->activeRunId = $runId;
        $this->counts = ['scanned'=>0,'linked'=>0,'created'=>0,'updated'=>0,'unresolved'=>0,'duplicates'=>0,'warnings'=>0,'errors'=>0];
        DB::table('pur_reconciliation_runs')->insert([
            'id'=>$runId,'run_number'=>$runNumber,'mode'=>$mode,'source_scope'=>$scope,'status'=>'RUNNING','row_limit'=>$limit,
            'options'=>json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'totals'=>null,
            'actor_user_id'=>$actor?->id,'started_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        try {
            if (in_array($scope, ['ALL','STOCK'], true)) $this->scanStock($runId, $mode, $limit);
            if (in_array($scope, ['ALL','WAREHOUSE'], true)) $this->scanWarehouse($runId, $mode, $limit, $actor);
            DB::table('pur_reconciliation_runs')->where('id',$runId)->update([
                'status'=>'COMPLETED','totals'=>json_encode($this->counts),'finished_at'=>now(),'updated_at'=>now(),
            ]);
        } catch (Throwable $e) {
            DB::table('pur_reconciliation_runs')->where('id',$runId)->update([
                'status'=>'FAILED','totals'=>json_encode($this->counts),'error_message'=>$e->getMessage(),'finished_at'=>now(),'updated_at'=>now(),
            ]);
            throw $e;
        }
        return $this->runSummary($runId);
    }

    private function scanStock(string $runId, string $mode, int $limit): void
    {
        if (!Schema::hasTable('stk_requests')) return;
        DB::table('stk_requests as s')->leftJoin('outlets as o','o.id','=','s.outlet_id')
            ->select('s.*','o.name as outlet_name')->orderBy('s.created_at')->limit($limit)->get()
            ->each(function($request) use($runId,$mode): void {
                $this->counts['scanned']++;
                try { $this->reconcileStockRequest($runId,$mode,$request); }
                catch(Throwable $e) { $this->issue($runId,'ERROR','STOCK_ROW_FAILED','STOCK','stk_requests',$request->id,$request->request_number,$e->getMessage()); }
            });
    }

    private function reconcileStockRequest(string $runId, string $mode, object $request): void
    {
        $sourceKey = 'STOCK_REQUEST:'.$request->id;
        $fund = null;
        if (!empty($request->canonical_fund_request_id)) $fund = DB::table('pur_fund_requests')->where('id',$request->canonical_fund_request_id)->first();
        if (!$fund) $fund = DB::table('pur_fund_requests')->where('source_key',$sourceKey)->first();
        if (!$fund) $fund = DB::table('pur_fund_requests')->where('source_type','STOCK_REQUEST')->where('source_id',$request->id)->first();
        if (!$fund) {
            $this->issue($runId,'ERROR','MISSING_CANONICAL_REQUEST','STOCK','stk_requests',$request->id,$request->request_number,'Stock Request belum mempunyai canonical Fund Request.');
            $this->link('STOCK','stk_requests',$request->id,$request->request_number,'FUND_REQUEST',null,null,null,'NONE',0);
        } else {
            $this->link('STOCK','stk_requests',$request->id,$request->request_number,'FUND_REQUEST',$fund->id,$fund->request_number,$fund->id,'SOURCE_KEY',1);
            if ($mode==='APPLY' && empty($request->canonical_fund_request_id)) {
                DB::table('stk_requests')->where('id',$request->id)->update(['canonical_fund_request_id'=>$fund->id,'updated_at'=>now()]);
                $this->counts['updated']++;
            }
        }

        $orders = DB::table('pur_purchase_orders')->whereNull('deleted_at')
            ->where(function($q) use($request,$fund): void {
                $q->where('stock_request_id',$request->id);
                if ($fund) $q->orWhere('fund_request_id',$fund->id);
                if (!empty($request->draft_purchase_order_id)) $q->orWhere('id',$request->draft_purchase_order_id);
            })->orderBy('created_at')->get();
        $order = $orders->first();
        if ($orders->count()>1) $this->issue($runId,'CRITICAL','DUPLICATE_ACTIVE_ORDER','STOCK','stk_requests',$request->id,$request->request_number,'Satu Stock Request terhubung ke lebih dari satu Purchase Order.', 'PURCHASE_ORDER', $order?->id, $fund?->id, ['order_ids'=>$orders->pluck('id')->all()]);
        if (!$order) $this->issue($runId,'WARNING','MISSING_PURCHASE_ORDER','STOCK','stk_requests',$request->id,$request->request_number,'Belum ada Purchase Order canonical untuk Stock Request.', null,null,$fund?->id);
        else {
            $this->link('STOCK','stk_requests',$request->id,$request->request_number,'PURCHASE_ORDER',$order->id,$order->po_number,$fund?->id,'FOREIGN_KEY',1);
            if ($mode==='APPLY') {
                $changes=[];
                if (empty($request->draft_purchase_order_id)) $changes['draft_purchase_order_id']=$order->id;
                if ($fund && empty($order->fund_request_id)) $changes['fund_request_id']=$fund->id;
                if (strtoupper((string)$order->order_type)!=='STOCK') $changes['order_type']='STOCK';
                if ($changes) { $changes['updated_at']=now(); DB::table('pur_purchase_orders')->where('id',$order->id)->update($changes); $this->counts['updated']++; }
            }
        }

        $fulfillment = Schema::hasTable('wh_fulfillments') ? DB::table('wh_fulfillments')->where('stock_request_id',$request->id)->first() : null;
        if ($fulfillment) $this->link('WAREHOUSE','wh_fulfillments',$fulfillment->id,null,'FULFILLMENT',$fulfillment->id,null,$fund?->id,'FOREIGN_KEY',1);
        $execution = $order && Schema::hasTable('pur_goods_receipts') ? DB::table('pur_goods_receipts')->where('order_kind','PURCHASE_ORDER')->where('order_id',$order->id)->whereNull('deleted_at')->first() : null;
        if ($execution) $this->link('PURCHASING','pur_goods_receipts',$execution->id,$execution->gr_number,'GOODS_RECEIPT',$execution->id,$execution->gr_number,$fund?->id,'FOREIGN_KEY',1);
        $receipt = null;
        if ($execution && Schema::hasTable('stk_goods_receipts')) {
            $receipt = DB::table('stk_goods_receipts')->where('supplier_document_number','PUR-EXEC:'.$execution->id)->first();
            if ($receipt) $this->link('STOCK','stk_goods_receipts',$receipt->id,$receipt->gr_number ?? null,'STOCK_RECEIPT',$receipt->id,$receipt->gr_number ?? null,$fund?->id,'IDEMPOTENCY_REFERENCE',1);
        }
        $invoice = $execution && Schema::hasTable('pur_invoices') ? DB::table('pur_invoices')->where('direction','INCOMING')->where('source_document_kind','GOODS_RECEIPT')->where('source_document_id',$execution->id)->whereNull('deleted_at')->first() : null;
        if ($invoice) $this->link('PURCHASING','pur_invoices',$invoice->id,$invoice->invoice_number,'INCOMING_INVOICE',$invoice->id,$invoice->invoice_number,$fund?->id,'SOURCE_DOCUMENT',1);

        $issues = $this->issueCounts($runId,'stk_requests',$request->id,$fund?->id);
        $this->snapshot($runId,[
            'source_system'=>'STOCK','source_table'=>'stk_requests','source_id'=>$request->id,'source_number'=>$request->request_number,
            'fund_request_id'=>$fund?->id,'fund_request_number'=>$fund?->request_number,'request_type'=>$fund?->request_type ?? 'STOCK','request_status'=>$fund?->status ?? $request->status,
            'chamber_code'=>$fund?->chamber_code ?? 'OUTLET','outlet_id'=>$request->outlet_id,'outlet_name'=>$request->outlet_name,
            'order_kind'=>'PURCHASE_ORDER','order_id'=>$order?->id,'order_number'=>$order?->po_number,'order_status'=>$order?->status,
            'fulfillment_id'=>$fulfillment?->id,'fulfillment_status'=>$fulfillment?->status,
            'execution_kind'=>'GOODS_RECEIPT','execution_id'=>$execution?->id,'execution_number'=>$execution?->gr_number,'execution_status'=>$execution?->status,
            'receipt_id'=>$receipt?->id,'receipt_number'=>$receipt?->gr_number,'receipt_status'=>$receipt?->status,
            'invoice_id'=>$invoice?->id,'invoice_number'=>$invoice?->invoice_number,'invoice_status'=>$invoice?->status,
            ...$issues,
        ]);
    }

    private function scanWarehouse(string $runId, string $mode, int $limit, ?User $actor): void
    {
        if (!Schema::hasTable('wh_purchase_requests')) return;
        DB::table('wh_purchase_requests as r')->leftJoin('outlets as o','o.id','=','r.warehouse_id')
            ->select('r.*','o.name as warehouse_name')->orderBy('r.created_at')->limit($limit)->get()
            ->each(function($request) use($runId,$mode,$actor): void {
                $this->counts['scanned']++;
                try { $this->reconcileWarehouseRequest($runId,$mode,$request,$actor); }
                catch(Throwable $e) { $this->issue($runId,'ERROR','WAREHOUSE_ROW_FAILED','WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,$e->getMessage()); }
            });
    }

    private function reconcileWarehouseRequest(string $runId,string $mode,object $request,?User $actor): void
    {
        $sourceKey='WAREHOUSE_PURCHASE_REQUEST:'.$request->id;
        $fund=null;
        if (!empty($request->canonical_fund_request_id)) $fund=DB::table('pur_fund_requests')->where('id',$request->canonical_fund_request_id)->first();
        if (!$fund) $fund=DB::table('pur_fund_requests')->where('source_key',$sourceKey)->first();
        if (!$fund && $mode==='APPLY') {
            $items=DB::table('wh_purchase_request_items as i')->leftJoin('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.base_uom_id')
                ->where('i.purchase_request_id',$request->id)->select('i.*','s.name as sku_name','u.symbol as uom_symbol')->get()->map(fn($i)=>[
                    'sku_id'=>$i->sku_id,'item_name'=>$i->sku_name ?: 'Legacy SKU '.$i->sku_id,'uom_text'=>$i->uom_symbol,
                    'qty'=>(float)($i->approved_qty_base ?: $i->requested_qty_base),'estimated_unit_price'=>(float)$i->estimated_unit_price,
                    'tax_mode'=>'NO_TAX','tax_percent'=>0,'notes'=>$i->notes,'source_line_key'=>'WH_PR_ITEM:'.$i->id,
                    'metadata'=>['legacy_supplier_source_id'=>$i->supplier_source_id],
                ])->all();
            if ($items) {
                $fundModel=$this->fundBridge->syncDraft([
                    'source_key'=>$sourceKey,'source_type'=>'WAREHOUSE_PURCHASE_REQUEST','source_id'=>$request->id,
                    'request_type'=>'PURCHASE','chamber_code'=>'WAREHOUSE','request_date'=>$request->request_date,
                    'needed_date'=>$request->needed_date ?: $request->request_date,'notes'=>$request->notes,
                    'source_payload'=>['warehouse_id'=>$request->warehouse_id,'legacy_pr_number'=>$request->pr_number],'items'=>$items,
                ],$actor);
                $mapped=$this->mapLegacyRequestStatus((string)$request->status);
                DB::table('pur_fund_requests')->where('id',$fundModel->id)->update([
                    'status'=>$mapped,'submitted_by_user_id'=>$request->submitted_by_user_id,'submitted_at'=>$request->submitted_at,
                    'approved_by_user_id'=>in_array($mapped,['REQUEST_APPROVED'],true)?$request->decided_by_user_id:null,
                    'approved_at'=>in_array($mapped,['REQUEST_APPROVED'],true)?$request->decided_at:null,
                    'rejected_by_user_id'=>$mapped==='REQUEST_REJECTED'?$request->decided_by_user_id:null,
                    'rejected_at'=>$mapped==='REQUEST_REJECTED'?$request->decided_at:null,'updated_at'=>now(),
                ]);
                DB::table('wh_purchase_requests')->where('id',$request->id)->update(['canonical_fund_request_id'=>$fundModel->id,'updated_at'=>now()]);
                $this->recordLegacyEvent($fundModel->id,'REQUEST',$fundModel->id,'LEGACY_REQUEST_IMPORTED','Legacy Request Imported',$mapped,$actor,'WAREHOUSE_PURCHASE_REQUEST',$request->id,$request->pr_number,$request->decided_at ?? $request->created_at);
                $fund=DB::table('pur_fund_requests')->where('id',$fundModel->id)->first(); $this->counts['created']++;
            }
        }
        if (!$fund) {
            $this->issue($runId,'WARNING','LEGACY_REQUEST_UNMAPPED','WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,'Legacy Warehouse Purchase Request belum mempunyai canonical Fund Request.');
            $this->link('WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,'FUND_REQUEST',null,null,null,'NONE',0);
        }
        else {
            $this->link('WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,'FUND_REQUEST',$fund->id,$fund->request_number,$fund->id,'SOURCE_KEY',1);
            if (in_array(strtoupper((string)$fund->status),['REQUEST_APPROVED','REQUEST_REJECTED'],true) && !DB::table('pur_fund_request_decisions')->where('fund_request_id',$fund->id)->exists()) {
                $this->issue($runId,'WARNING','LEGACY_REQUEST_APPROVAL_IMPORTED','WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,'Status approval request berasal dari workflow legacy dan tidak mempunyai decision canonical Executive.','FUND_REQUEST',$fund->id,$fund->id);
            }
        }

        $legacyOrders=DB::table('wh_supplier_purchase_orders')->where('purchase_request_id',$request->id)->orderBy('created_at')->get();
        if ($legacyOrders->count()>1 && $fund) {
            $this->issue($runId,'CRITICAL','MULTI_SUPPLIER_ORDER_REQUIRES_REVISION','WAREHOUSE','wh_purchase_requests',$request->id,$request->pr_number,'Legacy PR menghasilkan beberapa PO supplier, sedangkan canonical workflow saat ini satu request satu PO. Backfill otomatis PO dihentikan.',null,null,$fund->id,['legacy_po_ids'=>$legacyOrders->pluck('id')->all()]);
        }
        $canonicalOrder=null;
        foreach($legacyOrders as $legacyOrder) {
            $canonical=null;
            if (!empty($legacyOrder->canonical_purchase_order_id)) $canonical=DB::table('pur_purchase_orders')->where('id',$legacyOrder->canonical_purchase_order_id)->first();
            if (!$canonical) $canonical=DB::table('pur_purchase_orders')->where('po_number',$legacyOrder->po_number)->whereNull('deleted_at')->first();
            if (!$canonical && $mode==='APPLY' && $fund && $legacyOrders->count()===1) $canonical=$this->createCanonicalLegacyOrder($request,$legacyOrder,$fund,$actor);
            if ($canonical) {
                $canonicalOrder ??= $canonical;
                if (in_array(strtoupper((string)$canonical->status),['APPROVED','PARTIALLY_EXECUTED','EXECUTED'],true) && empty($canonical->finance_approved_1_at) && empty($canonical->finance_approved_2_at)) {
                    $this->issue($runId,'WARNING','LEGACY_APPROVAL_IMPORTED','WAREHOUSE','wh_supplier_purchase_orders',$legacyOrder->id,$legacyOrder->po_number,'Status approved berasal dari workflow legacy dan tidak membuktikan dua approval Finance canonical.','PURCHASE_ORDER',$canonical->id,$fund?->id);
                }
                $this->link('WAREHOUSE','wh_supplier_purchase_orders',$legacyOrder->id,$legacyOrder->po_number,'PURCHASE_ORDER',$canonical->id,$canonical->po_number,$fund?->id,'LEGACY_NUMBER',.95);
                if ($mode==='APPLY' && empty($legacyOrder->canonical_purchase_order_id)) { DB::table('wh_supplier_purchase_orders')->where('id',$legacyOrder->id)->update(['canonical_purchase_order_id'=>$canonical->id,'updated_at'=>now()]); $this->counts['updated']++; }
            } else {
                $this->issue($runId,'WARNING','LEGACY_ORDER_UNMAPPED','WAREHOUSE','wh_supplier_purchase_orders',$legacyOrder->id,$legacyOrder->po_number,'Legacy Supplier PO belum dapat dipetakan otomatis.','PURCHASE_ORDER',null,$fund?->id);
                $this->link('WAREHOUSE','wh_supplier_purchase_orders',$legacyOrder->id,$legacyOrder->po_number,'PURCHASE_ORDER',null,null,$fund?->id,'NONE',0);
            }
        }
        $stockIn=$legacyOrders->isNotEmpty() && Schema::hasTable('wh_stock_ins') ? DB::table('wh_stock_ins')->whereIn('purchase_order_id',$legacyOrders->pluck('id'))->orderByDesc('created_at')->first() : null;
        $execution=$canonicalOrder && Schema::hasTable('pur_goods_receipts') ? DB::table('pur_goods_receipts')->where('order_kind','PURCHASE_ORDER')->where('order_id',$canonicalOrder->id)->whereNull('deleted_at')->first() : null;
        if ($stockIn) {
            if ($execution) {
                $this->link('WAREHOUSE','wh_stock_ins',$stockIn->id,$stockIn->stock_in_number,'GOODS_RECEIPT',$execution->id,$execution->gr_number,$fund?->id,'ORDER_LINK',.9);
                if ($mode==='APPLY' && empty($stockIn->canonical_goods_receipt_id)) { DB::table('wh_stock_ins')->where('id',$stockIn->id)->update(['canonical_goods_receipt_id'=>$execution->id,'updated_at'=>now()]); $this->counts['updated']++; }
            } elseif (in_array(strtolower((string)$stockIn->status),['approved','completed','done'],true)) {
                $this->issue($runId,'WARNING','LEGACY_STOCK_IN_WITHOUT_CANONICAL_GR','WAREHOUSE','wh_stock_ins',$stockIn->id,$stockIn->stock_in_number,'Stock In legacy sudah selesai tetapi tidak memiliki Goods Receipt canonical. Sistem tidak mem-posting ulang stock.',null,null,$fund?->id);
            }
        }
        $attachment=$legacyOrders->isNotEmpty() && Schema::hasTable('wh_purchase_invoices') ? DB::table('wh_purchase_invoices')->whereIn('purchase_order_id',$legacyOrders->pluck('id'))->orderByDesc('uploaded_at')->first() : null;
        if ($attachment) $this->issue($runId,'INFO','LEGACY_ATTACHMENT_NOT_ACCOUNTING_INVOICE','WAREHOUSE','wh_purchase_invoices',$attachment->id,$attachment->original_name,'File invoice legacy diperlakukan sebagai attachment, bukan Invoice Masuk accounting.',null,null,$fund?->id);
        $invoice=$execution && Schema::hasTable('pur_invoices') ? DB::table('pur_invoices')->where('direction','INCOMING')->where('source_document_kind','GOODS_RECEIPT')->where('source_document_id',$execution->id)->whereNull('deleted_at')->first() : null;
        $issues=$this->issueCounts($runId,'wh_purchase_requests',$request->id,$fund?->id);
        $this->snapshot($runId,[
            'source_system'=>'WAREHOUSE','source_table'=>'wh_purchase_requests','source_id'=>$request->id,'source_number'=>$request->pr_number,
            'fund_request_id'=>$fund?->id,'fund_request_number'=>$fund?->request_number,'request_type'=>'PURCHASE','request_status'=>$fund?->status ?? strtoupper((string)$request->status),
            'chamber_code'=>'WAREHOUSE','outlet_id'=>$request->warehouse_id,'outlet_name'=>$request->warehouse_name,
            'order_kind'=>'PURCHASE_ORDER','order_id'=>$canonicalOrder?->id,'order_number'=>$canonicalOrder?->po_number,'order_status'=>$canonicalOrder?->status,
            'fulfillment_id'=>$stockIn?->id,'fulfillment_status'=>$stockIn?->status,
            'execution_kind'=>'GOODS_RECEIPT','execution_id'=>$execution?->id,'execution_number'=>$execution?->gr_number,'execution_status'=>$execution?->status,
            'invoice_id'=>$invoice?->id,'invoice_number'=>$invoice?->invoice_number,'invoice_status'=>$invoice?->status,
            ...$issues,
        ]);
    }

    private function createCanonicalLegacyOrder(object $request,object $legacyOrder,object $fund,?User $actor): object
    {
        return DB::transaction(function() use($request,$legacyOrder,$fund,$actor): object {
            $existing=DB::table('pur_purchase_orders')->where('fund_request_id',$fund->id)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($existing) return $existing;
            $id=(string)Str::ulid(); $status=$this->mapLegacyOrderStatus((string)$legacyOrder->status);
            DB::table('pur_purchase_orders')->insert([
                'id'=>$id,'po_number'=>$legacyOrder->po_number,'stock_request_id'=>null,'fund_request_id'=>$fund->id,
                'supplier_source_id'=>$legacyOrder->supplier_source_id,'counterparty_name'=>$this->supplierName($legacyOrder->supplier_source_id),
                'outlet_id'=>$request->warehouse_id,'chamber_code'=>'WAREHOUSE','source_type'=>'legacy_warehouse_procurement','order_type'=>'PURCHASE',
                'order_date'=>$request->request_date,'needed_date'=>$request->needed_date,'status'=>$status,'currency'=>$legacyOrder->currency ?: 'IDR',
                'subtotal'=>(float)($legacyOrder->actual_total ?: $legacyOrder->estimated_total),'tax_amount'=>0,
                'total_amount'=>(float)($legacyOrder->actual_total ?: $legacyOrder->estimated_total),'notes'=>'Backfill dari '.$legacyOrder->po_number,
                'lock_version'=>1,'submitted_by_user_id'=>$request->submitted_by_user_id,'submitted_at'=>$request->submitted_at,
                'finance_approved_1_by_user_id'=>null,'finance_approved_1_at'=>null,
                'finance_approved_2_by_user_id'=>null,'finance_approved_2_at'=>null,
                'approved_by_user_id'=>in_array($status,['APPROVED','PARTIALLY_EXECUTED','EXECUTED'],true)?$legacyOrder->purchase_approved_by_user_id:null,
                'approved_at'=>in_array($status,['APPROVED','PARTIALLY_EXECUTED','EXECUTED'],true)?$legacyOrder->purchase_approved_at:null,
                'created_by_user_id'=>$legacyOrder->created_by_user_id ?? $actor?->id,'updated_by_user_id'=>$actor?->id,
                'created_at'=>$legacyOrder->created_at ?? now(),'updated_at'=>now(),
            ]);
            $items=DB::table('wh_supplier_purchase_order_items as i')->leftJoin('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.base_uom_id')
                ->where('i.purchase_order_id',$legacyOrder->id)->select('i.*','s.name as sku_name','u.symbol as uom_symbol')->orderBy('i.created_at')->get();
            foreach($items as $index=>$item) {
                $qty=(float)($item->actual_qty_base ?: $item->ordered_qty_base); $price=(float)($item->actual_unit_price ?: $item->estimated_unit_price); $line=round($qty*$price,2);
                DB::table('pur_purchase_order_items')->insert([
                    'id'=>(string)Str::ulid(),'purchase_order_id'=>$id,'line_no'=>$index+1,'stock_request_item_id'=>null,'fund_request_item_id'=>null,
                    'sku_id'=>$item->sku_id,'item_name'=>$item->sku_name ?: 'Legacy SKU '.$item->sku_id,'uom_text'=>$item->uom_symbol,
                    'approved_qty'=>$qty,'unit_price'=>$price,'tax_mode'=>'NO_TAX','tax_percent'=>0,'subtotal'=>$line,'tax_amount'=>0,'line_total'=>$line,
                    'notes'=>$item->notes,'source_line_key'=>'WH_SPO_ITEM:'.$item->id,'metadata'=>json_encode(['legacy_po_item_id'=>$item->id]),
                    'created_at'=>$item->created_at ?? now(),'updated_at'=>now(),
                ]);
            }
            $this->recordLegacyEvent($fund->id,'PURCHASE_ORDER',$id,'LEGACY_ORDER_IMPORTED','Legacy Purchase Order Imported',$status,$actor,'WAREHOUSE_SUPPLIER_PURCHASE_ORDER',$legacyOrder->id,$legacyOrder->po_number,$legacyOrder->purchase_approved_at ?? $legacyOrder->created_at);
            $this->counts['created']++; return DB::table('pur_purchase_orders')->where('id',$id)->first();
        },3);
    }

    private function link(string $system,string $table,string $sourceId,?string $sourceNumber,string $type,?string $canonicalId,?string $canonicalNumber,?string $root,string $strategy,float $confidence): void
    {
        $row=$this->links->link(['source_system'=>$system,'source_table'=>$table,'source_id'=>$sourceId,'source_number'=>$sourceNumber,
            'canonical_type'=>$type,'canonical_id'=>$canonicalId,'canonical_number'=>$canonicalNumber,'root_fund_request_id'=>$root,
            'match_strategy'=>$strategy,'confidence'=>$confidence]);
        if (($row['link_status']??'')==='LINKED') $this->counts['linked']++;
        elseif (($row['link_status']??'')==='DUPLICATE') {
            $this->counts['duplicates']++;
            if ($this->activeRunId) $this->issue($this->activeRunId,'CRITICAL','DUPLICATE_SOURCE_DOCUMENT',$system,$table,$sourceId,$sourceNumber,'Source legacy mencoba menempati canonical slot yang sudah dimiliki source lain.',$type,$canonicalId,$root,['canonical_number'=>$canonicalNumber]);
        } else $this->counts['unresolved']++;
    }

    /** @param array<string,mixed> $metadata */
    private function issue(string $runId,string $severity,string $code,?string $system,?string $table,?string $sourceId,?string $number,string $message,?string $canonicalType=null,?string $canonicalId=null,?string $root=null,array $metadata=[]): void
    {
        $fingerprint=hash('sha256',implode('|',[$code,$system,$table,$sourceId,$canonicalType,$canonicalId]));
        $old=DB::table('pur_reconciliation_issues')->where('run_id',$runId)->where('fingerprint',$fingerprint)->first();
        DB::table('pur_reconciliation_issues')->updateOrInsert(['run_id'=>$runId,'fingerprint'=>$fingerprint],[
            'id'=>(string)($old->id??Str::ulid()),'run_id'=>$runId,'severity'=>$severity,'issue_code'=>$code,'source_system'=>$system,
            'source_table'=>$table,'source_id'=>$sourceId,'source_number'=>$number,'canonical_type'=>$canonicalType,'canonical_id'=>$canonicalId,
            'root_fund_request_id'=>$root,'message'=>$message,'resolution_status'=>$old->resolution_status??'OPEN',
            'resolution_notes'=>$old->resolution_notes??null,'resolved_by_user_id'=>$old->resolved_by_user_id??null,'resolved_at'=>$old->resolved_at??null,
            'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
            'created_at'=>$old->created_at??now(),'updated_at'=>now(),
        ]);
        if (in_array($severity,['ERROR','CRITICAL'],true)) $this->counts['errors']++; elseif ($severity==='WARNING') $this->counts['warnings']++;
    }

    /** @return array{issue_count:int,warning_count:int,error_count:int,overall_status:string} */
    private function issueCounts(string $runId,string $table,string $id,?string $root=null): array
    {
        $rows=DB::table('pur_reconciliation_issues')->where('run_id',$runId)->where(function($q) use($table,$id,$root): void {
            $q->where(fn($x)=>$x->where('source_table',$table)->where('source_id',$id));
            if($root) $q->orWhere('root_fund_request_id',$root);
        })->get();
        $errors=$rows->whereIn('severity',['ERROR','CRITICAL'])->count(); $warnings=$rows->where('severity','WARNING')->count();
        return ['issue_count'=>$rows->count(),'warning_count'=>$warnings,'error_count'=>$errors,'overall_status'=>$errors?'CONFLICT':($warnings?'WARNING':'LINKED')];
    }

    /** @param array<string,mixed> $data */
    private function snapshot(string $runId,array $data): void
    {
        $key=strtolower($data['source_system'].':'.$data['source_table'].':'.$data['source_id']); $old=DB::table('pur_reconciliation_snapshots')->where('run_id',$runId)->where('row_key',$key)->first();
        $columns=['source_system','source_table','source_id','source_number','fund_request_id','fund_request_number','request_type','request_status','chamber_code','outlet_id','outlet_name','order_kind','order_id','order_number','order_status','fulfillment_id','fulfillment_status','execution_kind','execution_id','execution_number','execution_status','receipt_id','receipt_number','receipt_status','invoice_id','invoice_number','invoice_status','overall_status','issue_count','warning_count','error_count'];
        $payload=['id'=>(string)($old->id??Str::ulid()),'run_id'=>$runId,'row_key'=>$key,'created_at'=>$old->created_at??now(),'updated_at'=>now()];
        foreach($columns as $c) $payload[$c]=$data[$c]??null;
        $payload['issue_count']=(int)($data['issue_count']??0); $payload['warning_count']=(int)($data['warning_count']??0); $payload['error_count']=(int)($data['error_count']??0);
        $payload['metadata']=isset($data['metadata'])?json_encode($data['metadata']):null;
        DB::table('pur_reconciliation_snapshots')->updateOrInsert(['run_id'=>$runId,'row_key'=>$key],$payload);
    }


    private function recordLegacyEvent(string $root,string $documentType,string $documentId,string $code,string $label,string $status,?User $actor,string $referenceType,string $referenceId,?string $referenceNumber,mixed $occurredAt): void
    {
        if (!Schema::hasTable('pur_document_events')) return;
        if (DB::table('pur_document_events')->where('root_request_id',$root)->where('document_type',$documentType)->where('document_id',$documentId)->where('event_code',$code)->exists()) return;
        DB::table('pur_document_events')->insert([
            'id'=>(string)Str::ulid(),'root_request_id'=>$root,'document_type'=>$documentType,'document_id'=>$documentId,
            'event_code'=>$code,'event_label'=>$label,'status'=>$status,'actor_user_id'=>$actor?->id,
            'actor_name_snapshot'=>$actor?->name ?: 'System Reconciliation','occurred_at'=>$occurredAt ?: now(),
            'notes'=>'Imported non-destructively from legacy workflow.','reference_type'=>$referenceType,'reference_id'=>$referenceId,
            'reference_number'=>$referenceNumber,'metadata'=>json_encode(['imported'=>true]),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function mapLegacyRequestStatus(string $status): string
    { return match(strtolower($status)) {'submitted'=>'AWAITING_REQUEST_APPROVAL','approved','partially_approved','completed'=>'REQUEST_APPROVED','rejected'=>'REQUEST_REJECTED',default=>'DRAFT'}; }
    private function mapLegacyOrderStatus(string $status): string
    { return match(strtolower($status)) {'rejected','cancelled'=>'REJECTED','purchased','purchase_approved','stock_in','stock_in_progress','completed'=>'APPROVED',default=>'DRAFT'}; }
    private function supplierName(?string $id): ?string { return $id&&Schema::hasTable('pur_supplier_sources')?DB::table('pur_supplier_sources')->where('id',$id)->value('name'):null; }
    /** @return array<string,mixed> */
    private function runSummary(string $id): array { $r=DB::table('pur_reconciliation_runs')->where('id',$id)->first(); return ['id'=>$r->id,'run_number'=>$r->run_number,'mode'=>$r->mode,'source_scope'=>$r->source_scope,'status'=>$r->status,'totals'=>json_decode((string)$r->totals,true)?:[],'started_at'=>$r->started_at,'finished_at'=>$r->finished_at]; }
}
