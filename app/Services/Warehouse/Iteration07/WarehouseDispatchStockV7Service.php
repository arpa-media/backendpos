<?php

namespace App\Services\Warehouse\Iteration07;

use App\Models\Warehouse\WarehouseLedgerPosting;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseDispatchStockV7Service
{
    public function __construct(private readonly WarehouseLedgerService $ledger) {}

    public function ensurePosted(string $warehouseId,string $deliveryId,string $userId): WarehouseLedgerPosting
    {
        $delivery=DB::table('wh_v3_delivery_orders')->where('warehouse_id',$warehouseId)->where('id',$deliveryId)->lockForUpdate()->first();
        if(!$delivery) abort(404);

        $linked=trim((string)($delivery->dispatch_ledger_posting_id ?? ''));
        if($linked!=='' && ($existing=WarehouseLedgerPosting::query()->find($linked))) {
            $this->mark($delivery,$existing->id); return $existing->load(['entries.sku','entries.batch','entries.storage']);
        }

        $key='WAREHOUSE-V7-DO-DISPATCH-OUT:'.$deliveryId;
        $existing=WarehouseLedgerPosting::query()->where('warehouse_id',$warehouseId)->where('idempotency_key',$key)->first();
        if($existing){ $this->mark($delivery,$existing->id); return $existing->load(['entries.sku','entries.batch','entries.storage']); }

        $gr=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->lockForUpdate()->first();
        if(!$gr) throw ValidationException::withMessages(['goods_receipt'=>['Draft Goods Receipt audit belum terbentuk pada DO Dispatch.']]);

        $items=DB::table('wh_v3_delivery_order_items')->where('delivery_order_id',$deliveryId)->where('sent_qty_base','>',0)->lockForUpdate()->get();
        if($items->isEmpty()) throw ValidationException::withMessages(['items'=>['Delivery Order tidak mempunyai Qty Dikirim untuk diposting.']]);

        $lines=[];
        foreach($items as $item) $lines=array_merge($lines,$this->allocate($warehouseId,(string)$item->sku_id,(float)$item->sent_qty_base,'DOITEM-'.(string)$item->id));

        $movement=$this->movementType($delivery);
        $posting=$this->ledger->post([
            'warehouse_id'=>$warehouseId,'idempotency_key'=>$key,'movement_type'=>$movement,
            // Keep historical GR reference contract for valuation/reset consumers.
            'reference_type'=>'wh_v3_goods_receipt','reference_id'=>(string)$gr->id,
            'business_date'=>$delivery->dispatched_at ? substr((string)$delivery->dispatched_at,0,10) : now('Asia/Jakarta')->toDateString(),
            'reason'=>'DO Dispatch '.($delivery->delivery_number ?: $deliveryId),
            'metadata'=>['flow_version'=>7,'stock_point'=>'delivery_order_dispatch','source_type'=>$delivery->source_type,'source_id'=>$delivery->source_id,'destination_type'=>$delivery->destination_type,'destination_id'=>$delivery->destination_id],
            'user_id'=>$userId,'allow_negative'=>false,'lines'=>$lines,
        ]);
        $this->mark($delivery,$posting->id);
        return $posting;
    }

    private function movementType(object $delivery): string
    {
        if((string)$delivery->source_type==='transfer_stock') return 'transfer_out';
        $customer=(string)$delivery->source_type==='sales_order' && Schema::hasColumn('wh_v3_sales_orders','sales_channel')
            && DB::table('wh_v3_sales_orders')->where('id',$delivery->source_id)->where('sales_channel','customer_manual')->exists();
        return $customer ? 'customer_sale_out' : 'request_out';
    }

    private function allocate(string $warehouseId,string $skuId,float $qty,string $prefix): array
    {
        $remaining=round($qty,4); $lines=[];
        $balances=DB::table('wh_batch_balances as balance')->join('wh_batches as batch','batch.id','=','balance.batch_id')
            ->where('balance.warehouse_id',$warehouseId)->where('balance.sku_id',$skuId)->whereNull('batch.deleted_at')
            ->whereRaw('(balance.on_hand_qty-balance.reserved_qty-balance.quarantine_qty)>0')
            ->orderByRaw('CASE WHEN batch.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('batch.expiry_date')->orderBy('batch.received_at')->orderBy('batch.created_at')
            ->lockForUpdate()->get(['balance.batch_id','balance.storage_id','balance.on_hand_qty','balance.reserved_qty','balance.quarantine_qty','balance.average_unit_cost']);
        foreach($balances as $b){
            if($remaining<=0.0001) break;
            $available=round((float)$b->on_hand_qty-(float)$b->reserved_qty-(float)$b->quarantine_qty,4); if($available<=0) continue;
            $take=min($remaining,$available);
            $lines[]=['line_key'=>$prefix.'-'.$b->batch_id.'-'.$b->storage_id,'sku_id'=>$skuId,'batch_id'=>(string)$b->batch_id,'storage_id'=>(string)$b->storage_id,'quantity_base'=>round($take,4),'unit_cost'=>(float)$b->average_unit_cost,'metadata'=>['allocation'=>'FEFO/FIFO','stock_point'=>'delivery_order_dispatch','flow_version'=>7]];
            $remaining=round($remaining-$take,4);
        }
        if($remaining>0.0001) throw ValidationException::withMessages(['stock'=>[sprintf('Stock Warehouse tidak mencukupi untuk SKU %s saat DO Dispatch. Kekurangan %.4f base unit.',$skuId,$remaining)]]);
        return $lines;
    }

    private function mark(object $delivery,string $postingId): void
    {
        $payload=['updated_at'=>now()];
        if(Schema::hasColumn('wh_v3_delivery_orders','dispatch_ledger_posting_id')) $payload['dispatch_ledger_posting_id']=$postingId;
        DB::table('wh_v3_delivery_orders')->where('id',$delivery->id)->update($payload);
        if(Schema::hasColumn('wh_v3_delivery_orders','stock_dispatched_at')) {
            DB::table('wh_v3_delivery_orders')->where('id',$delivery->id)->whereNull('stock_dispatched_at')->update(['stock_dispatched_at'=>$delivery->dispatched_at ?: now(),'updated_at'=>now()]);
        }
        if(Schema::hasTable('wh_v3_goods_receipts') && Schema::hasColumn('wh_v3_goods_receipts','ledger_posting_id')) {
            DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$delivery->id)->update(['ledger_posting_id'=>$postingId,'updated_at'=>now()]);
        }
    }
}
