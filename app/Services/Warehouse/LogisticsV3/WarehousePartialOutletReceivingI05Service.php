<?php

namespace App\Services\Warehouse\LogisticsV3;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use App\Services\Warehouse\Iteration07\WarehouseDispatchStockV7Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * I05: append-only receiving batches; never overwrites I04 DO/GR service code.
 * A partially received DO remains GR draft. The final batch submits that GR.
 */
final class WarehousePartialOutletReceivingI05Service
{
    public function __construct(
        private readonly WarehouseLogisticsV3Service $logistics,
        private readonly WarehouseDispatchStockV7Service $dispatchStock,
    ) {}

    public function detail(string $outletId, string $deliveryId): array
    {
        $detail = $this->logistics->outletDeliveryDetail($outletId, $deliveryId);
        $g = $detail['goods_receipt'] ?? null;
        $items = $g['items'] ?? [];
        $outstanding = 0.0;
        foreach ($items as $item) {
            $outstanding += max(0, round((float) $item['sent_qty_base'] - (float) $item['received_qty_base'] - (float) $item['not_received_qty_base'], 4));
        }
        $count = $g ? DB::table('wh_v3_partial_receipt_batches')->where('goods_receipt_id', $g['id'])->count() : 0;
        $type = DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->value('source_type');
        $detail['source_type'] = (string) $type;
        $detail['receiving_progress'] = [
            'batch_count' => $count,
            'outstanding_qty_base' => round($outstanding,4),
            'is_partial' => $count > 0 && $g['status'] === 'draft',
            'batches' => $g ? DB::table('wh_v3_partial_receipt_batches')->where('goods_receipt_id', $g['id'])->orderBy('created_at')->get(['id','receipt_date','close_shortage','notes','created_at'])->map(fn ($r) => (array) $r)->all() : [],
        ];
        return $detail;
    }

    public function hasPartialBatches(string $deliveryId): bool
    {
        return DB::table('wh_v3_partial_receipt_batches')->where('delivery_order_id', $deliveryId)->exists();
    }

    public function receive(string $outletId, string $deliveryId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($outletId, $deliveryId, $payload, $userId): void {
            // Consistent lock order with legacy receiving: DO then GR then GR items.
            $d = DB::table('wh_v3_delivery_orders')->where('id', $deliveryId)
                ->where('destination_type','outlet')->where('destination_id',$outletId)->lockForUpdate()->first();
            if (! $d) abort(404);
            if ($d->source_type !== 'stock_request') throw ValidationException::withMessages(['source_type'=>['Penerimaan bertahap I05 hanya untuk Stock Request outlet. Gunakan receiving existing untuk alur lainnya.']]);
            $g = DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->lockForUpdate()->first();
            if (! $g) throw ValidationException::withMessages(['goods_receipt'=>['Draft Goods Receipt tidak tersedia.']]);
            $key = trim((string) $payload['idempotency_key']);
            $byId = collect($payload['items'])->keyBy(fn ($row) => (string) $row['item_id']);
            // Sort and normalize exact values for stable retries regardless of UI item order.
            $normalized = $byId->map(fn ($row) => number_format((float) $row['received_qty_uom'],4,'.',''))->sortKeys()->all();
            $hash = hash('sha256', json_encode(['items'=>$normalized,'receipt_date'=>$payload['receipt_date'],'close_shortage'=>(bool)($payload['close_shortage']??false),'notes'=>(string)($payload['notes']??'')], JSON_THROW_ON_ERROR));
            $existing = DB::table('wh_v3_partial_receipt_batches')->where('delivery_order_id',$deliveryId)->where('idempotency_key',$key)->first();
            if ($existing) {
                if (! hash_equals((string)$existing->payload_hash,$hash)) throw ValidationException::withMessages(['idempotency_key'=>['Idempotency key sudah dipakai untuk isi penerimaan lain.']]);
                return; // Exact retry; no stock mutation.
            }
            if (! in_array((string)$d->status,['dispatched','receiving'],true) || $g->status !== 'draft') {
                throw ValidationException::withMessages(['status'=>['DO/GR sudah final atau belum dispatch. Batch baru ditolak.']]);
            }
            $receiptDate = (string) $payload['receipt_date'];
            $minDate = $d->source_id ? DB::table('stk_requests')->where('id',$d->source_id)->value('request_date') : null;
            $minDate = (string)($minDate ?: substr((string)($d->dispatched_at ?: $d->created_at),0,10));
            if ($receiptDate < $minDate || $receiptDate > now('Asia/Jakarta')->toDateString()) throw ValidationException::withMessages(['receipt_date'=>['Tanggal terima di luar rentang yang diizinkan.']]);
            $items = DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$g->id)->orderBy('sku_id')->orderBy('id')->lockForUpdate()->get();
            if ($items->count() !== $byId->count() || $items->isEmpty()) throw ValidationException::withMessages(['items'=>['Semua item GR harus dikirim, termasuk item dengan qty 0.']]);
            $precision = DB::table('stk_uoms')->whereIn('id',$items->pluck('uom_id')->filter()->unique()->all())->pluck('decimal_places','id');
            $receivedBatch = []; $hasPositive = false; $hasOutstanding = false;
            foreach ($items as $item) {
                $row = $byId->get((string)$item->id);
                if (! $row) throw ValidationException::withMessages(['items'=>['Item tidak cocok dengan GR.']]);
                $raw = (float)$row['received_qty_uom'];
                if (abs($raw - round($raw,4)) > 0.00000001) throw ValidationException::withMessages(['items'=>['Maksimal empat desimal untuk qty penerimaan.']]);
                $delta = round($raw,4);
                $p = max(0,min(4,(int)($precision->get((string)$item->uom_id)??4)));
                $scale = 10 ** $p;
                if (abs(round($delta*$scale)-$delta*$scale)>0.00001) throw ValidationException::withMessages(['items'=>["Presisi qty tidak valid untuk SKU {$item->sku_id}."]]);
                $already = round((float)$item->received_qty_uom,4);
                $sent = round((float)$item->sent_qty_uom,4);
                $remaining = round($sent-$already,4);
                if ($delta < 0 || $delta > $remaining+0.0001) throw ValidationException::withMessages(['items'=>["Qty tahap ini melebihi outstanding SKU {$item->sku_id}."]]);
                $factor = $sent>0 ? (float)$item->sent_qty_base/$sent : 1.0;
                $totalBase = round(($already+$delta)*$factor,4);
                // Delta is the difference between cumulative base snapshots, not
                // individually rounded components; prevents multi-batch drift.
                $base = round($totalBase - (float)$item->received_qty_base,4);
                if ($totalBase > (float)$item->sent_qty_base+0.0001) throw ValidationException::withMessages(['items'=>['Total Qty base melewati pengiriman.']]);
                if ($delta>0) $hasPositive = true;
                if ($sent-$already-$delta>0.0001) $hasOutstanding = true;
                $receivedBatch[] = [$item,$delta,$base,$already,$sent,$factor];
            }
            $closeShortage = (bool)($payload['close_shortage']??false);
            if (! $hasPositive && ! ($closeShortage && $this->hasPartialBatches($deliveryId))) {
                throw ValidationException::withMessages(['items'=>['Minimal satu item harus diterima. Penutupan selisih hanya boleh setelah ada batch penerimaan.']]);
            }
            $isFinal = $closeShortage || ! $hasOutstanding;
            $posting = $this->dispatchStock->ensurePosted((string)$d->warehouse_id,$deliveryId,$userId);
            $batchId = (string)Str::ulid();
            DB::table('wh_v3_partial_receipt_batches')->insert([
                'id'=>$batchId,'delivery_order_id'=>$deliveryId,'goods_receipt_id'=>$g->id,
                'idempotency_key'=>$key,'payload_hash'=>$hash,'receipt_date'=>$receiptDate,
                'close_shortage'=>$closeShortage,'notes'=>$payload['notes']??null,
                'received_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach ($receivedBatch as [$item,$delta,$base,$already,$sent,$factor]) {
                $updated = [
                    'received_qty_uom'=>round($already+$delta,4),
                    'received_qty_base'=>round(($already+$delta)*$factor,4),
                    'not_received_qty_uom'=>$isFinal?round($sent-$already-$delta,4):0,
                    'not_received_qty_base'=>$isFinal?round(((float)$item->sent_qty_base)-round(($already+$delta)*$factor,4),4):0,
                    'updated_at'=>now(),
                ];
                DB::table('wh_v3_goods_receipt_items')->where('id',$item->id)->update($updated);
                if ($delta<=0) continue;
                $alreadyPosted = InventoryMovement::query()->where('movement_type','goods_receipt')->where('reference_type','wh_v3_goods_receipt')->where('reference_line_id',$item->id)->exists();
                $refLineId = $alreadyPosted ? (string)Str::ulid() : (string)$item->id;
                $lineId = (string)Str::ulid();
                DB::table('wh_v3_partial_receipt_items')->insert([
                    'id'=>$lineId,'batch_id'=>$batchId,'goods_receipt_item_id'=>$item->id,
                    'movement_reference_line_id'=>$refLineId,'received_qty_uom'=>$delta,'received_qty_base'=>$base,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                $this->postBatchStock($g,$item,$refLineId,$base,(string)$posting->id,$userId,$receiptDate,$batchId);
            }
            $receiver = DB::table('users')->where('id',$userId)->first(['name']);
            DB::table('wh_v3_goods_receipts')->where('id',$g->id)->update([
                'status'=>$isFinal?'submitted':'draft',
                'receipt_date'=>$receiptDate,'received_at'=>now(),
                'receiver_user_id'=>$userId,'receiver_name_snapshot'=>$receiver?->name ?: 'Receiver',
                'receiver_signed_at'=>now(),'outlet_inventory_posted_at'=>now(),
                'ledger_posting_id'=>$posting->id,'notes'=>$payload['notes']??$g->notes,'updated_at'=>now(),
            ]);
            DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->update(['status'=>'receiving','updated_at'=>now()]);
            DB::table('wh_v3_logistics_events')->insert([
                'id'=>(string)Str::ulid(),'document_type'=>'goods_receipt','document_id'=>$g->id,
                'event_type'=>$isFinal?'i05_receiving_final':'i05_receiving_partial',
                'from_status'=>'draft','to_status'=>$isFinal?'submitted':'draft',
                'message'=>$isFinal?'Penerimaan bertahap selesai, GR siap Complete.':'Penerimaan sebagian dicatat, outstanding menunggu pengiriman berikutnya.',
                'metadata'=>json_encode(['batch_id'=>$batchId,'partial'=>!$isFinal,'close_shortage'=>$closeShortage]),
                'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            // Parent request remains in-delivery while final GR/other DOs are outstanding.
        }, 5);
        return $this->detail($outletId,$deliveryId);
    }

    private function postBatchStock(object $g, object $item, string $refLineId, float $qty, string $postingId, string $userId, string $date, string $batchId): void
    {
        $costRow = DB::table('wh_ledger_entries')->where('posting_id',$postingId)
            ->where('line_key','like','DOITEM-'.$item->delivery_order_item_id.'-%')
            ->selectRaw('COALESCE(SUM(total_cost),0) total_cost,COALESCE(SUM(quantity_base),0) qty')->first();
        if (! $costRow || abs((float)$costRow->qty)<0.000001) throw ValidationException::withMessages(['stock_ledger'=>['Tidak ada dasar valuasi dispatch untuk SKU '.$item->sku_id]]);
        $unitCost = round(abs((float)$costRow->total_cost/(float)$costRow->qty),4);
        $balance = InventoryBalance::query()->where('outlet_id',$g->destination_id)->where('sku_id',$item->sku_id)->lockForUpdate()->first();
        if (! $balance) {
            DB::table('stk_inventory_balances')->insertOrIgnore([
                'id'=>(string)Str::ulid(),'outlet_id'=>$g->destination_id,'sku_id'=>$item->sku_id,
                'on_hand_qty'=>0,'average_unit_cost'=>0,'inventory_value'=>0,
                'last_movement_at'=>null,'lock_version'=>1,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $balance = InventoryBalance::query()->where('outlet_id',$g->destination_id)->where('sku_id',$item->sku_id)->lockForUpdate()->firstOrFail();
        }
        $oldQty=round((float)$balance->on_hand_qty,4); $oldValue=round((float)$balance->inventory_value,2);
        $value=round($qty*$unitCost,2);$newQty=round($oldQty+$qty,4);$newValue=round($oldValue+$value,2);
        $average=$newQty>0?round($newValue/$newQty,4):0;
        $balance->forceFill(['on_hand_qty'=>$newQty,'average_unit_cost'=>$average,'inventory_value'=>$newValue,
            'last_movement_at'=>now(),'lock_version'=>((int)$balance->lock_version)+1])->save();
        InventoryMovement::query()->create([
            'outlet_id'=>$g->destination_id,'sku_id'=>$item->sku_id,'movement_type'=>'goods_receipt',
            'reference_type'=>'wh_v3_goods_receipt','reference_id'=>$g->id,'reference_line_id'=>$refLineId,
            'business_date'=>$date,'quantity'=>$qty,'unit_cost'=>$unitCost,'total_cost'=>$value,
            'balance_qty_after'=>$newQty,'average_cost_after'=>$average,'inventory_value_after'=>$newValue,
            'metadata'=>['warehouse_logistics_v3'=>true,'i05_partial_receipt'=>true,'batch_id'=>$batchId,'goods_receipt_item_id'=>$item->id,'warehouse_ledger_posting_id'=>$postingId],
            'created_by_user_id'=>$userId,
        ]);
    }
}
