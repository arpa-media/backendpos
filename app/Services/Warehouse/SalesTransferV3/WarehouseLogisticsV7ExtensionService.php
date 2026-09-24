<?php

namespace App\Services\Warehouse\SalesTransferV3;

use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseStorage;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseLogisticsV7ExtensionService
{
    public function __construct(
        private readonly WarehouseLogisticsV3Service $legacy,
        private readonly WarehouseLedgerService $ledger,
    ) {}

    public function generateDeliveryOrder(string $warehouseId,string $prepareId,array $payload,string $userId):array
    {
        $result = $this->legacy->generateDeliveryOrder($warehouseId,$prepareId,$payload,$userId);
        $prepare = DB::table('wh_v3_logistics_prepare_requests')->where('id',$prepareId)->first();
        if (! $prepare) return $result;

        if ((string) $prepare->source_type === 'transfer_stock') {
            $deliveryId = (string) ($result['id'] ?? '');
            if ($deliveryId === '') {
                $deliveryId = (string) DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$prepareId)->value('id');
            }
            if ($deliveryId !== '') {
                $posting = $this->ensureTransferOutForDelivery($warehouseId,$deliveryId,$userId,false);
                $this->markIteration07DispatchAudit($deliveryId,(string)$posting->id);
                $result['transfer_out_posting_id'] = (string) $posting->id;
            }
        }
        $this->setSourceStatus((string)$prepare->source_type,(string)$prepare->source_id,'on-delivery');
        return $result;
    }

    public function completeGoodsReceipt(string $warehouseId,string $grId,string $userId,?string $notes=null):array
    {
        $row = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->where('g.warehouse_id',$warehouseId)->where('g.id',$grId)
            ->first(['g.id','g.status','d.source_type','d.source_id','d.id as delivery_id']);
        if (! $row) abort(404);

        if ((string)$row->source_type !== 'transfer_stock') {
            $result = $this->legacy->completeGoodsReceipt($warehouseId,$grId,$userId,$notes);
            if ((string)$row->source_type === 'sales_order') $this->setSourceStatus('sales_order',(string)$row->source_id,'completed',true);
            return $result;
        }

        if ((string)$row->status !== 'completed') {
            throw ValidationException::withMessages([
                'status' => ['Transfer Stock tidak di-Complete dari Warehouse origin. Finalisasi hanya melalui Logistic → Receiving Stock pada Warehouse tujuan.'],
            ]);
        }
        return $this->legacy->goodsReceiptDetail($warehouseId,$grId);
    }

    public function receiveCustomerGoodsReceipt(string $warehouseId,string $grId,array $payload,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$grId,$payload,$userId):void{
            $g=DB::table('wh_v3_goods_receipts')->where('warehouse_id',$warehouseId)->where('id',$grId)->lockForUpdate()->first();if(!$g)abort(404);
            $d=DB::table('wh_v3_delivery_orders')->where('id',$g->delivery_order_id)->lockForUpdate()->first();if(!$d||$d->source_type!=='sales_order'||$d->destination_type!=='customer')throw ValidationException::withMessages(['source_type'=>['Goods Receipt ini bukan Sales Order Customer.']]);
            if(in_array((string)$g->status,['submitted','completed'],true))return;
            if($g->status!=='draft')throw ValidationException::withMessages(['status'=>['Goods Receipt customer tidak dapat diterima pada status saat ini.']]);
            $inputs=collect($payload['items']??[])->keyBy(fn($x)=>(string)($x['item_id']??''));$items=DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$grId)->lockForUpdate()->get();
            foreach($items as $item){$input=$inputs->get((string)$item->id);if(!$input)throw ValidationException::withMessages(['items'=>['Seluruh item customer receiving wajib diisi.']]);$sent=round((float)$item->sent_qty_uom,4);$received=round((float)($input['received_qty_uom']??0),4);$not=round((float)($input['not_received_qty_uom']??($sent-$received)),4);if($received<0||$not<0||abs(($received+$not)-$sent)>0.0001)throw ValidationException::withMessages(['items'=>["Qty Diterima + Tidak Diterima harus sama dengan Qty Dikirim pada item {$item->id}."]]);$factor=$sent>0?(float)$item->sent_qty_base/$sent:1.0;DB::table('wh_v3_goods_receipt_items')->where('id',$item->id)->update(['received_qty_uom'=>$received,'received_qty_base'=>round($received*$factor,4),'not_received_qty_uom'=>$not,'not_received_qty_base'=>round($not*$factor,4),'notes'=>$input['notes']??null,'updated_at'=>now()]);}
            DB::table('wh_v3_goods_receipts')->where('id',$grId)->update(['status'=>'submitted','receipt_date'=>$payload['receipt_date']??now('Asia/Jakarta')->toDateString(),'received_at'=>now(),'receiver_user_id'=>$userId,'receiver_name_snapshot'=>trim((string)$payload['receiver_name']),'receiver_signed_at'=>now(),'notes'=>$payload['notes']??null,'updated_at'=>now()]);
            DB::table('wh_v3_delivery_orders')->where('id',$d->id)->update(['status'=>'receiving','updated_at'=>now()]);$this->setSourceStatus('sales_order',(string)$d->source_id,'goods-receipt');$this->logEvent('goods_receipt',$grId,'customer_received','draft','submitted','Penerimaan customer dicatat. Goods Receipt siap di-Complete.',$userId,['receiver_name'=>$payload['receiver_name']]);
        },5);
        return $this->legacy->goodsReceiptDetail($warehouseId,$grId);
    }


    public function warehouseDeliveries(string $warehouseId,array $filters):array
    {
        $q = DB::table('wh_v3_delivery_orders as d')
            ->join('outlets as origin','origin.id','=','d.warehouse_id')
            ->leftJoin('wh_v3_goods_receipts as g','g.delivery_order_id','=','d.id')
            ->where('d.source_type','transfer_stock')
            ->where('d.destination_type','warehouse')
            ->where('d.destination_id',$warehouseId)
            ->whereIn('d.status',['dispatched','receiving','received','completed'])
            ->select('d.*','origin.code as origin_code','origin.name as origin_name','g.id as goods_receipt_id','g.goods_receipt_number','g.status as goods_receipt_status','g.ledger_posting_id','g.destination_ledger_posting_id','g.received_at','g.completed_at')
            ->selectSub(fn($s)=>$s->from('wh_v3_delivery_order_items as i')->whereColumn('i.delivery_order_id','d.id')->selectRaw('COUNT(*)'),'line_count');
        if (!empty($filters['q'])) {
            $term='%'.trim((string)$filters['q']).'%';
            $q->where(fn($x)=>$x->where('d.delivery_number','like',$term)->orWhere('d.source_number','like',$term)->orWhere('origin.name','like',$term));
        }
        if (!empty($filters['status'])) $q->where('g.status',$filters['status']);
        $p = $q->orderByRaw("CASE WHEN g.status='draft' THEN 0 WHEN g.status='submitted' THEN 1 ELSE 2 END")
            ->orderByDesc('d.dispatched_at')->paginate((int)($filters['per_page']??30));
        return $this->paginated($p,fn($r)=>[
            'id'=>(string)$r->id,'delivery_number'=>(string)$r->delivery_number,'source_type'=>(string)$r->source_type,
            'source_number'=>$r->source_number,'origin'=>['id'=>(string)$r->warehouse_id,'code'=>$r->origin_code,'name'=>$r->origin_name],
            'status'=>(string)$r->status,'estimated_delivery_date'=>$r->estimated_delivery_date,'estimated_delivery_time'=>$r->estimated_delivery_time,
            'goods_receipt_id'=>$r->goods_receipt_id,'goods_receipt_number'=>$r->goods_receipt_number,'goods_receipt_status'=>$r->goods_receipt_status,
            'origin_ledger_posting_id'=>$r->ledger_posting_id,'destination_ledger_posting_id'=>$r->destination_ledger_posting_id,
            'line_count'=>(int)$r->line_count,'received_at'=>$r->received_at,'completed_at'=>$r->completed_at,
        ]);
    }

    public function warehouseDeliveryDetail(string $warehouseId,string $deliveryId):array
    {
        $d = DB::table('wh_v3_delivery_orders as d')
            ->join('outlets as origin','origin.id','=','d.warehouse_id')
            ->leftJoin('users as sender','sender.id','=','d.sender_user_id')
            ->where('d.source_type','transfer_stock')->where('d.destination_type','warehouse')->where('d.destination_id',$warehouseId)->where('d.id',$deliveryId)
            ->first(['d.*','origin.code as origin_code','origin.name as origin_name','sender.name as sender_name','sender.nisj as sender_nisj']);
        if (! $d) abort(404);
        $g = DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->first();
        $items = DB::table('wh_v3_delivery_order_items as i')->join('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.uom_id')
            ->where('i.delivery_order_id',$deliveryId)->orderBy('s.name')->get(['i.*','s.sku_code','s.name as item_name','u.code as uom_code'])
            ->map(fn($i)=>[
                'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,
                'uom_code'=>(string)($i->uom_code?:'UNIT'),'requested_qty_uom'=>(float)$i->requested_qty_uom,'approved_qty_uom'=>(float)$i->approved_qty_uom,'sent_qty_uom'=>(float)$i->sent_qty_uom,
            ])->values()->all();
        $grItems = $g ? DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$g->id)->get()->keyBy('delivery_order_item_id') : collect();
        foreach ($items as &$item) {
            $x=$grItems->get($item['id']);
            $item['goods_receipt_item_id']=$x?->id;
            $item['received_qty_uom']=$x?(float)$x->received_qty_uom:0;
            $item['not_received_qty_uom']=$x?(float)$x->not_received_qty_uom:0;
            $item['destination_storage_id']=$x?->destination_storage_id;
        }
        unset($item);
        $storages = WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->orderBy('code')->get(['id','code','name'])
            ->map(fn($s)=>['id'=>(string)$s->id,'code'=>$s->code,'name'=>$s->name])->values()->all();
        return [
            'id'=>(string)$d->id,'delivery_number'=>(string)$d->delivery_number,'source_type'=>(string)$d->source_type,'source_id'=>(string)$d->source_id,'source_number'=>$d->source_number,
            'origin'=>['id'=>(string)$d->warehouse_id,'code'=>$d->origin_code,'name'=>$d->origin_name],
            'destination'=>['id'=>$warehouseId,'code'=>$d->destination_code_snapshot,'name'=>$d->destination_name_snapshot],
            'status'=>(string)$d->status,'estimated_delivery_date'=>$d->estimated_delivery_date,'estimated_delivery_time'=>$d->estimated_delivery_time,
            'sender'=>['name'=>$d->sender_name,'nisj'=>$d->sender_nisj],'items'=>$items,'storages'=>$storages,
            'goods_receipt'=>$g?[
                'id'=>(string)$g->id,'goods_receipt_number'=>(string)$g->goods_receipt_number,'status'=>(string)$g->status,'received_at'=>$g->received_at,
                'receiver_name'=>$g->receiver_name_snapshot,'origin_ledger_posting_id'=>$g->ledger_posting_id,'destination_ledger_posting_id'=>$g->destination_ledger_posting_id,
                'outgoing_invoice_id'=>$g->outgoing_invoice_id,
            ]:null,
        ];
    }

    public function receiveAtWarehouse(string $warehouseId,string $deliveryId,array $payload,string $userId):array
    {
        $delivery = DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->first();
        if (! $delivery || (string)$delivery->source_type !== 'transfer_stock' || (string)$delivery->destination_type !== 'warehouse' || (string)$delivery->destination_id !== $warehouseId) abort(404);

        // Compatibility for DO dispatched before Iteration 03: ensure the source OUT exists before destination IN.
        $originPosting = $this->ensureTransferOutForDelivery((string)$delivery->warehouse_id,$deliveryId,$userId,true);

        DB::transaction(function() use ($warehouseId,$deliveryId,$payload,$userId,$originPosting): void {
            $d = DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->lockForUpdate()->first();
            $g = DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->lockForUpdate()->first();
            if (! $d || ! $g) throw ValidationException::withMessages(['goods_receipt'=>['Draft Goods Receipt tidak ditemukan.']]);
            if ((string)$g->status === 'completed' && $g->destination_ledger_posting_id) return;
            if (! in_array((string)$g->status,['draft','submitted'],true)) throw ValidationException::withMessages(['status'=>['Goods Receipt tidak dapat diterima pada status saat ini.']]);

            $inputs = collect($payload['items']??[])->keyBy(fn($x)=>(string)($x['item_id']??''));
            $items = DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$g->id)->lockForUpdate()->get();
            $inLines=[];
            foreach ($items as $item) {
                $input=$inputs->get((string)$item->id);
                if (! $input) throw ValidationException::withMessages(['items'=>['Seluruh item wajib diisi.']]);
                $sentUom=round((float)$item->sent_qty_uom,4);
                $receivedUom=round((float)($input['received_qty_uom']??0),4);
                $notUom=round((float)($input['not_received_qty_uom']??0),4);
                if (abs($receivedUom-$sentUom)>0.0001 || abs($notUom)>0.0001) {
                    throw ValidationException::withMessages(['items'=>["Iterasi 03 memakai full-receipt policy. Received harus sama dengan Sent dan Not Received harus 0 untuk item {$item->id}."]]);
                }
                $storage=$this->resolveStorage($warehouseId,$input['destination_storage_id']??null,$userId);
                $factor=$sentUom>0?(float)$item->sent_qty_base/$sentUom:1.0;
                $receivedBase=round($receivedUom*$factor,4);

                $originEntries = DB::table('wh_ledger_entries')->where('posting_id',$originPosting->id)
                    ->where('line_key','like','TRANSFER-OUT:'.$item->delivery_order_item_id.':%')->orderBy('entry_order')->get();
                $entryQty = round((float)$originEntries->sum('quantity_base'),4);
                if (abs($entryQty-$receivedBase)>0.0001) {
                    throw ValidationException::withMessages(['lineage'=>["Batch lineage origin tidak balance untuk item {$item->id}. OUT {$entryQty}, Receive {$receivedBase}."]]);
                }

                $firstBatchId=null;
                foreach ($originEntries as $entry) {
                    $sourceBatch=WarehouseBatch::query()->withTrashed()->find($entry->batch_id);
                    if (! $sourceBatch) throw ValidationException::withMessages(['batch'=>['Batch origin transfer tidak ditemukan.']]);
                    $destBatch=$this->destinationBatch($warehouseId,$storage,$sourceBatch,$entry,(string)$g->id,(string)$item->id,(string)$d->source_id,$userId);
                    $firstBatchId ??= (string)$destBatch->id;
                    $inLines[]=[
                        'line_key'=>'TRANSFER-IN:'.$item->id.':'.$entry->id,
                        'sku_id'=>(string)$item->sku_id,'batch_id'=>(string)$destBatch->id,'storage_id'=>(string)$storage->id,
                        'direction'=>'IN','quantity_base'=>(float)$entry->quantity_base,'unit_cost'=>(float)$entry->unit_cost,
                        'metadata'=>[
                            'flow_version'=>4,'source_type'=>'transfer_stock','origin_ledger_posting_id'=>(string)$originPosting->id,
                            'origin_ledger_entry_id'=>(string)$entry->id,'origin_batch_id'=>(string)$entry->batch_id,'goods_receipt_item_id'=>(string)$item->id,
                        ],
                    ];
                }
                DB::table('wh_v3_goods_receipt_items')->where('id',$item->id)->update([
                    'received_qty_uom'=>$receivedUom,'received_qty_base'=>$receivedBase,'not_received_qty_uom'=>0,'not_received_qty_base'=>0,
                    'destination_storage_id'=>$storage->id,'destination_batch_id'=>$firstBatchId,'notes'=>$input['notes']??null,'updated_at'=>now(),
                ]);
            }
            if (! $inLines) throw ValidationException::withMessages(['items'=>['Tidak ada Qty transfer yang dapat diterima.']]);

            $posting = $this->ledger->post([
                'warehouse_id'=>$warehouseId,'idempotency_key'=>'WAREHOUSE-V4-TRANSFER-IN:'.$g->id,'movement_type'=>'transfer_in',
                'reference_type'=>'wh_v3_goods_receipt','reference_id'=>(string)$g->id,'business_date'=>$payload['receipt_date']??now('Asia/Jakarta')->toDateString(),
                'reason'=>'Transfer Stock Receipt '.$g->goods_receipt_number,
                'metadata'=>['flow_version'=>4,'source_type'=>'transfer_stock','origin_warehouse_id'=>(string)$d->warehouse_id,'origin_ledger_posting_id'=>(string)$originPosting->id,'receiving_trigger'=>'destination_warehouse'],
                'user_id'=>$userId,'lines'=>$inLines,
            ]);
            $user=DB::table('users')->where('id',$userId)->first(['name']);
            DB::table('wh_v3_goods_receipts')->where('id',$g->id)->update([
                'status'=>'completed','receipt_date'=>$payload['receipt_date']??now('Asia/Jakarta')->toDateString(),'received_at'=>now(),'receiver_user_id'=>$userId,
                'receiver_name_snapshot'=>$user?->name?:'Receiver','receiver_signed_at'=>now(),'ledger_posting_id'=>$originPosting->id,
                'destination_ledger_posting_id'=>$posting->id,'destination_inventory_posted_at'=>now(),'outgoing_invoice_id'=>null,
                'completed_by_user_id'=>$userId,'completed_at'=>now(),'notes'=>$payload['notes']??null,'updated_at'=>now(),
            ]);
            DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->update(['status'=>'completed','updated_at'=>now()]);
            DB::table('wh_v3_logistics_prepare_requests')->where('id',$d->prepare_request_id)->update(['status'=>'completed','updated_at'=>now()]);
            DB::table('wh_v3_logistics_prepare_items')->where('prepare_request_id',$d->prepare_request_id)->update(['status'=>'completed','updated_at'=>now()]);
            $this->setSourceStatus('transfer_stock',(string)$d->source_id,'completed',true);
            $this->logEvent('goods_receipt',(string)$g->id,'transfer_received','draft','completed','Warehouse tujuan menerima Transfer Stock. transfer_in diposting dan transfer selesai tanpa invoice.',$userId,[
                'origin_ledger_posting_id'=>(string)$originPosting->id,'destination_ledger_posting_id'=>(string)$posting->id,'outgoing_invoice'=>false,'full_receipt_policy'=>true,
            ]);
        },5);

        return $this->warehouseDeliveryDetail($warehouseId,$deliveryId);
    }

    private function markIteration07DispatchAudit(string $deliveryId,string $postingId):void
    {
        if(!Schema::hasTable('wh_v3_delivery_orders')) return;
        $delivery=DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->first(); if(!$delivery) return;
        $payload=['updated_at'=>now()];
        if(Schema::hasColumn('wh_v3_delivery_orders','dispatch_ledger_posting_id')) $payload['dispatch_ledger_posting_id']=$postingId;
        DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->update($payload);
        if(Schema::hasColumn('wh_v3_delivery_orders','stock_dispatched_at')) {
            DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->whereNull('stock_dispatched_at')->update(['stock_dispatched_at'=>$delivery->dispatched_at ?: now(),'updated_at'=>now()]);
        }
    }

    private function ensureTransferOutForDelivery(string $originWarehouseId,string $deliveryId,string $userId,bool $compatibilityBackfill): object
    {
        $d=DB::table('wh_v3_delivery_orders')->where('warehouse_id',$originWarehouseId)->where('id',$deliveryId)->first();
        if (! $d || (string)$d->source_type !== 'transfer_stock' || (string)$d->destination_type !== 'warehouse') {
            throw ValidationException::withMessages(['source_type'=>['Delivery Order bukan Transfer Stock antar-Warehouse.']]);
        }
        $g=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->first();
        if (! $g) throw ValidationException::withMessages(['goods_receipt'=>['Draft Goods Receipt Transfer tidak ditemukan.']]);
        if ($g->ledger_posting_id) {
            $existing=DB::table('wh_ledger_postings')->where('id',$g->ledger_posting_id)->where('movement_type','transfer_out')->first();
            if ($existing) return $existing;
        }

        $lines=[];
        foreach (DB::table('wh_v3_delivery_order_items')->where('delivery_order_id',$deliveryId)->get() as $item) {
            $sent=round((float)$item->sent_qty_base,4);
            if ($sent<=0) continue;
            $lines=array_merge($lines,$this->allocateOrigin($originWarehouseId,(string)$item->sku_id,$sent,'TRANSFER-OUT:'.$item->id));
        }
        if (! $lines) throw ValidationException::withMessages(['items'=>['Tidak ada Qty Transfer yang dapat diposting OUT.']]);
        $businessDate=$d->dispatched_at ? substr((string)$d->dispatched_at,0,10) : now('Asia/Jakarta')->toDateString();
        $posting=$this->ledger->post([
            'warehouse_id'=>$originWarehouseId,'idempotency_key'=>'WAREHOUSE-V4-TRANSFER-OUT:'.$deliveryId,'movement_type'=>'transfer_out',
            'reference_type'=>'wh_v3_delivery_order','reference_id'=>$deliveryId,'business_date'=>$businessDate,
            'reason'=>'Transfer Stock Dispatch '.$d->delivery_number,
            'metadata'=>[
                'flow_version'=>4,'source_type'=>'transfer_stock','destination_warehouse_id'=>(string)$d->destination_id,
                'dispatch_trigger'=>true,'compatibility_backfill_at_receiving'=>$compatibilityBackfill,
            ],
            'user_id'=>$userId,'allow_negative'=>false,'lines'=>$lines,
        ]);
        DB::table('wh_v3_goods_receipts')->where('id',$g->id)->update(['ledger_posting_id'=>$posting->id,'updated_at'=>now()]);
        $this->logEvent('delivery_order',$deliveryId,'transfer_out_posted','dispatched','dispatched','Dispatch Transfer Stock mem-post transfer_out di Warehouse origin.',$userId,[
            'ledger_posting_id'=>(string)$posting->id,'compatibility_backfill'=>$compatibilityBackfill,
        ]);
        return $posting;
    }

    private function allocateOrigin(string $warehouseId,string $skuId,float $quantity,string $prefix):array
    {
        $remaining=round($quantity,4);$lines=[];
        $rows=DB::table('wh_batch_balances as b')->join('wh_batches as x','x.id','=','b.batch_id')
            ->where('b.warehouse_id',$warehouseId)->where('b.sku_id',$skuId)->whereNull('x.deleted_at')
            ->whereRaw('(b.on_hand_qty-b.reserved_qty-b.quarantine_qty)>0')
            ->orderByRaw('CASE WHEN x.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('x.expiry_date')->orderBy('x.received_at')
            ->lockForUpdate()->get(['b.batch_id','b.storage_id','b.on_hand_qty','b.reserved_qty','b.quarantine_qty','b.average_unit_cost']);
        foreach($rows as $b){
            if($remaining<=0.0001)break;
            $available=round((float)$b->on_hand_qty-(float)$b->reserved_qty-(float)$b->quarantine_qty,4);
            if($available<=0)continue;
            $take=min($remaining,$available);
            $lines[]=['line_key'=>$prefix.':'.$b->batch_id.':'.$b->storage_id,'sku_id'=>$skuId,'batch_id'=>(string)$b->batch_id,'storage_id'=>(string)$b->storage_id,'direction'=>'OUT','quantity_base'=>$take,'unit_cost'=>(float)$b->average_unit_cost,'metadata'=>['allocation'=>'FEFO/FIFO','flow_version'=>4]];
            $remaining=round($remaining-$take,4);
        }
        if($remaining>0.0001)throw ValidationException::withMessages(['stock'=>[sprintf('Stock origin tidak mencukupi untuk SKU %s. Kekurangan %.4f.',$skuId,$remaining)]]);
        return $lines;
    }

    private function destinationBatch(string $warehouseId,WarehouseStorage $storage,WarehouseBatch $sourceBatch,object $entry,string $grId,string $grItemId,string $transferId,string $userId):WarehouseBatch
    {
        $batch=WarehouseBatch::query()->withTrashed()->where('warehouse_id',$warehouseId)
            ->where('source_reference_type','wh_ledger_entry')->where('source_reference_id',$entry->id)->first();
        if ($batch) { if ($batch->trashed()) $batch->restore(); return $batch; }
        $code='TRF4-'.strtoupper(substr(preg_replace('/[^A-Z0-9]/','', (string)$sourceBatch->batch_code),-20)).'-'.strtoupper(substr((string)$entry->id,-8));
        return WarehouseBatch::query()->create([
            'warehouse_id'=>$warehouseId,'sku_id'=>$entry->sku_id,'storage_id'=>$storage->id,'batch_code'=>Str::limit($code,100,''),
            'supplier_batch_code'=>$sourceBatch->supplier_batch_code ?: $sourceBatch->batch_code,'source_type'=>'TRANSFER_V4','source_reference_type'=>'wh_ledger_entry','source_reference_id'=>$entry->id,'source_reference_line_id'=>$grItemId,
            'received_at'=>now(),'production_date'=>$sourceBatch->production_date,'expiry_date'=>$sourceBatch->expiry_date,'quantity_received_base'=>0,
            'actual_unit_cost'=>(float)$entry->unit_cost,'price_min'=>(float)$entry->unit_cost,'price_avg'=>(float)$entry->unit_cost,'price_max'=>(float)$entry->unit_cost,'status'=>'draft',
            'metadata'=>['flow_version'=>4,'transfer_order_id'=>$transferId,'goods_receipt_id'=>$grId,'origin_batch_id'=>(string)$sourceBatch->id,'origin_ledger_entry_id'=>(string)$entry->id,'origin_warehouse_id'=>(string)$entry->warehouse_id],
            'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
        ]);
    }

    private function resolveStorage(string $warehouseId,?string $storageId,?string $userId):WarehouseStorage
    {
        $storageId=trim((string)$storageId);
        if($storageId!==''){
            $s=WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->find($storageId);
            if(!$s)throw ValidationException::withMessages(['storage_id'=>['Storage tujuan tidak aktif atau bukan milik Warehouse tujuan.']]);
            return $s;
        }
        $s=WarehouseStorage::query()->withTrashed()->where('warehouse_id',$warehouseId)->where('code','UNCATEGORIZED')->first();
        if($s){if($s->trashed())$s->restore();if(!$s->is_active)$s->forceFill(['is_active'=>true,'updated_by_user_id'=>$userId])->save();return $s;}
        return WarehouseStorage::query()->create(['warehouse_id'=>$warehouseId,'code'=>'UNCATEGORIZED','name'=>'Uncategorized','storage_type'=>'other','position_description'=>'Default storage Transfer Stock Warehouse v4.','is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId]);
    }

    private function setSourceStatus(string $type,string $id,string $status,bool $complete=false):void
    {
        if($type==='sales_order')DB::table('wh_v3_sales_orders')->where('id',$id)->update(['status'=>$status,'completed_at'=>$complete?now():null,'updated_at'=>now()]);
        if($type==='transfer_stock')DB::table('wh_v3_transfer_orders')->where('id',$id)->update(['status'=>$status,'completed_at'=>$complete?now():null,'updated_at'=>now()]);
    }

    private function logEvent(string $type,string $id,string $event,?string $from,?string $to,string $message,?string $userId,array $metadata=[]):void
    {
        DB::table('wh_v3_logistics_events')->insert(['id'=>(string)Str::ulid(),'document_type'=>$type,'document_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>$metadata?json_encode($metadata):null,'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function paginated(LengthAwarePaginator $p,callable $map):array
    {
        return ['items'=>collect($p->items())->map($map)->values()->all(),'pagination'=>['current_page'=>$p->currentPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'last_page'=>$p->lastPage()]];
    }
}
