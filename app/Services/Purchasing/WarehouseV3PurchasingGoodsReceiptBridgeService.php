<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WarehouseV3PurchasingGoodsReceiptBridgeService
{
    /**
     * Synchronize one completed Warehouse V3 Stock Request GR into the canonical
     * Purchasing Goods Receipt. Quantity comes from actual outlet receiving,
     * while commercial price follows the canonical Purchase Order.
     *
     * @return array<string,mixed>
     */
    public function sync(string $warehouseGoodsReceiptId, ?string $userId = null, bool $dryRun = false): array
    {
        $this->assertDependencies();

        $source = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->where('g.id', $warehouseGoodsReceiptId)
            ->first([
                'g.id', 'g.goods_receipt_number', 'g.status', 'g.receipt_date', 'g.completed_at',
                'g.destination_type', 'g.destination_id', 'g.delivery_order_id',
                'd.source_type', 'd.source_id', 'd.source_number',
            ]);

        if (! $source) {
            throw new RuntimeException("Warehouse V3 Goods Receipt {$warehouseGoodsReceiptId} tidak ditemukan.");
        }
        if ((string) $source->source_type !== 'stock_request' || (string) $source->destination_type !== 'outlet') {
            return ['warehouse_gr_id'=>$warehouseGoodsReceiptId, 'skipped'=>true, 'reason'=>'not_outlet_stock_request'];
        }
        if ((string) $source->status !== 'completed') {
            return ['warehouse_gr_id'=>$warehouseGoodsReceiptId, 'skipped'=>true, 'reason'=>'warehouse_gr_not_completed'];
        }

        $order = DB::table('pur_purchase_orders')
            ->where('stock_request_id', (string) $source->source_id)
            ->whereNull('deleted_at')
            ->whereRaw("UPPER(COALESCE(order_type,'')) = 'STOCK'")
            ->orderByDesc('created_at')
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'purchase_order' => ['Auto Purchase Order Stock tidak ditemukan untuk Stock Request '.$source->source_number.'.'],
            ]);
        }

        $lines = $this->sourceLines((string) $source->delivery_order_id, $warehouseGoodsReceiptId, (string) $order->id);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Warehouse V3 GR tidak memiliki actual received item yang dapat dipetakan ke Purchase Order.']]);
        }

        $subtotal = round((float) $lines->sum('line_total'), 2);
        $existing = DB::table('pur_goods_receipts')
            ->where('order_kind', 'PURCHASE_ORDER')
            ->where('order_id', (string) $order->id)
            ->whereNull('deleted_at')
            ->first();

        if ($existing && strtoupper((string) $existing->status) !== 'DRAFT') {
            return [
                'warehouse_gr_id'=>$warehouseGoodsReceiptId,
                'purchase_order_id'=>(string)$order->id,
                'purchasing_gr_id'=>(string)$existing->id,
                'purchasing_gr_number'=>(string)$existing->gr_number,
                'status'=>(string)$existing->status,
                'created'=>false,
                'synced'=>false,
                'locked'=>true,
            ];
        }

        if ($dryRun) {
            return [
                'warehouse_gr_id'=>$warehouseGoodsReceiptId,
                'warehouse_gr_number'=>(string)$source->goods_receipt_number,
                'purchase_order_id'=>(string)$order->id,
                'purchase_order_number'=>(string)$order->po_number,
                'purchasing_gr_id'=>$existing?->id ? (string)$existing->id : null,
                'status'=>$existing?->status ? (string)$existing->status : 'DRAFT',
                'created'=>!$existing,
                'synced'=>true,
                'line_count'=>$lines->count(),
                'actual_total'=>$subtotal,
                'dry_run'=>true,
            ];
        }

        return DB::transaction(function () use ($source, $order, $lines, $subtotal, $existing, $userId): array {
            $now = now();
            $current = $existing
                ? DB::table('pur_goods_receipts')->where('id', $existing->id)->lockForUpdate()->first()
                : DB::table('pur_goods_receipts')->where('order_kind','PURCHASE_ORDER')->where('order_id',(string)$order->id)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($current && strtoupper((string)$current->status) !== 'DRAFT') {
                return [
                    'warehouse_gr_id'=>(string)$source->id,
                    'purchase_order_id'=>(string)$order->id,
                    'purchasing_gr_id'=>(string)$current->id,
                    'purchasing_gr_number'=>(string)$current->gr_number,
                    'status'=>(string)$current->status,
                    'created'=>false,'synced'=>false,'locked'=>true,
                ];
            }

            $grId = $current?->id ? (string)$current->id : (string)Str::ulid();
            $grNumber = $current?->gr_number ? (string)$current->gr_number : $this->nextNumber();
            $metadataNote = sprintf(
                'Actual Receiving Warehouse V3 %s (%s) tersinkron. Menunggu proses/approval Purchasing.',
                (string)$source->goods_receipt_number,
                (string)$source->source_number,
            );

            $header = [
                'document_date'=>(string)($source->receipt_date ?: now('Asia/Jakarta')->toDateString()),
                'status'=>'DRAFT',
                'currency'=>(string)($order->currency ?: 'IDR'),
                'subtotal'=>$subtotal,
                'tax_amount'=>0,
                'total_amount'=>$subtotal,
                'external_reference'=>'WAREHOUSE_V3_GR:'.(string)$source->id,
                'notes'=>$metadataNote,
                'is_auto_generated'=>1,
                'auto_generated_at'=>$current?->auto_generated_at ?: $now,
                'auto_generation_source'=>'WAREHOUSE_V3_COMPLETED',
                'realization_date'=>(string)($source->receipt_date ?: now('Asia/Jakarta')->toDateString()),
                'actual_subtotal'=>$subtotal,
                'actual_tax_amount'=>0,
                'actual_total_amount'=>$subtotal,
                'evidence_required'=>0,
                'evidence_status'=>'NOT_REQUIRED',
                'realization_status'=>'PENDING',
                'updated_by_user_id'=>$userId,
                'updated_at'=>$now,
            ];

            if (! $current) {
                DB::table('pur_goods_receipts')->insert(array_merge([
                    'id'=>$grId,
                    'gr_number'=>$grNumber,
                    'order_kind'=>'PURCHASE_ORDER',
                    'order_id'=>(string)$order->id,
                    'fund_request_id'=>$order->fund_request_id ?? null,
                    'outlet_id'=>$source->destination_id ?: ($order->outlet_id ?? null),
                    'chamber_code'=>$order->chamber_code ?? 'OUTLET',
                    'lock_version'=>1,
                    'created_by_user_id'=>$userId,
                    'created_at'=>$now,
                ], $header));
            } else {
                $header['lock_version'] = ((int)($current->lock_version ?? 0)) + 1;
                DB::table('pur_goods_receipts')->where('id',$grId)->update($header);
            }

            DB::table('pur_goods_receipt_items')->where('document_id',$grId)->delete();
            foreach ($lines as $index => $line) {
                DB::table('pur_goods_receipt_items')->insert([
                    'id'=>(string)Str::ulid(),
                    'document_id'=>$grId,
                    'order_item_id'=>(string)$line->purchase_order_item_id,
                    'line_no'=>$index + 1,
                    'sku_id'=>(string)$line->sku_id,
                    'item_name'=>(string)$line->item_name,
                    'uom_text'=>(string)$line->uom_text,
                    'ordered_qty'=>(float)$line->ordered_qty,
                    'executed_qty'=>(float)$line->received_qty_base,
                    'unit_price'=>(float)$line->unit_price,
                    'tax_amount'=>0,
                    'line_total'=>(float)$line->line_total,
                    'notes'=>$line->notes,
                    'metadata'=>json_encode([
                        'source'=>'WAREHOUSE_V3_GR',
                        'warehouse_goods_receipt_id'=>(string)$source->id,
                        'warehouse_goods_receipt_item_id'=>(string)$line->warehouse_goods_receipt_item_id,
                        'warehouse_delivery_order_item_id'=>(string)$line->delivery_order_item_id,
                        'stock_request_item_id'=>(string)$line->source_item_id,
                        'actual_received_qty_base'=>(float)$line->received_qty_base,
                        'not_received_qty_base'=>(float)$line->not_received_qty_base,
                    ]),
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);
            }

            return [
                'warehouse_gr_id'=>(string)$source->id,
                'warehouse_gr_number'=>(string)$source->goods_receipt_number,
                'purchase_order_id'=>(string)$order->id,
                'purchase_order_number'=>(string)$order->po_number,
                'purchasing_gr_id'=>$grId,
                'purchasing_gr_number'=>$grNumber,
                'status'=>'DRAFT',
                'created'=>!$current,
                'synced'=>true,
                'line_count'=>$lines->count(),
                'actual_total'=>$subtotal,
            ];
        }, 5);
    }

    public function reconcile(?string $outletId = null, ?string $grId = null, bool $dryRun = false): array
    {
        $this->assertDependencies();
        $query = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->where('g.status','completed')
            ->where('g.destination_type','outlet')
            ->where('d.source_type','stock_request');
        if ($outletId) $query->where('g.destination_id',$outletId);
        if ($grId) $query->where('g.id',$grId);

        $ids = $query->orderBy('g.completed_at')->pluck('g.id');
        $summary = ['scanned'=>0,'created'=>0,'synced'=>0,'locked'=>0,'skipped'=>0,'errors'=>0];
        $details = [];
        foreach ($ids as $id) {
            $summary['scanned']++;
            try {
                $result = $this->sync((string)$id, null, $dryRun);
                $details[] = $result;
                if ($result['skipped'] ?? false) $summary['skipped']++;
                if ($result['created'] ?? false) $summary['created']++;
                if ($result['synced'] ?? false) $summary['synced']++;
                if ($result['locked'] ?? false) $summary['locked']++;
            } catch (\Throwable $e) {
                $summary['errors']++;
                $details[] = ['warehouse_gr_id'=>(string)$id,'error'=>$e->getMessage()];
            }
        }
        return ['mode'=>$dryRun?'dry-run':'repair','summary'=>$summary,'receipts'=>$details];
    }

    private function sourceLines(string $deliveryOrderId, string $warehouseGoodsReceiptId, string $purchaseOrderId)
    {
        $rows = DB::table('wh_v3_goods_receipt_items as gi')
            ->join('wh_v3_delivery_order_items as di','di.id','=','gi.delivery_order_item_id')
            ->join('stk_skus as sku','sku.id','=','gi.sku_id')
            ->leftJoin('stk_uoms as u','u.id','=','sku.base_uom_id')
            ->where('gi.goods_receipt_id',$warehouseGoodsReceiptId)
            ->where('di.delivery_order_id',$deliveryOrderId)
            ->where('gi.received_qty_base','>',0)
            ->orderBy('di.id')
            ->get([
                'gi.id as warehouse_goods_receipt_item_id','gi.sku_id','gi.received_qty_base','gi.not_received_qty_base','gi.notes',
                'di.id as delivery_order_item_id','di.source_item_id','sku.name as sku_name','u.code as base_uom_code',
            ]);

        return $rows->map(function ($row) use ($purchaseOrderId) {
            $poItem = null;
            if ($row->source_item_id) {
                $poItem = DB::table('pur_purchase_order_items')
                    ->where('purchase_order_id',$purchaseOrderId)
                    ->where('stock_request_item_id',(string)$row->source_item_id)
                    ->first();
            }
            if (! $poItem) {
                $poItem = DB::table('pur_purchase_order_items')
                    ->where('purchase_order_id',$purchaseOrderId)
                    ->where('sku_id',(string)$row->sku_id)
                    ->orderBy('line_no')
                    ->first();
            }
            if (! $poItem) {
                throw ValidationException::withMessages([
                    'items' => ['Item Warehouse GR '.$row->sku_name.' tidak dapat dipetakan ke item Purchase Order.'],
                ]);
            }
            $qty = round((float)$row->received_qty_base,4);
            $unit = round((float)$poItem->unit_price,2);
            return (object)[
                'warehouse_goods_receipt_item_id'=>(string)$row->warehouse_goods_receipt_item_id,
                'delivery_order_item_id'=>(string)$row->delivery_order_item_id,
                'source_item_id'=>(string)($row->source_item_id ?? ''),
                'purchase_order_item_id'=>(string)$poItem->id,
                'sku_id'=>(string)$row->sku_id,
                'item_name'=>(string)($poItem->item_name ?: $row->sku_name),
                'uom_text'=>(string)($poItem->uom_text ?: $row->base_uom_code ?: 'UNIT'),
                'ordered_qty'=>round((float)$poItem->approved_qty,4),
                'received_qty_base'=>$qty,
                'not_received_qty_base'=>round((float)$row->not_received_qty_base,4),
                'unit_price'=>$unit,
                'line_total'=>round($qty*$unit,2),
                'notes'=>$row->notes,
            ];
        })->values();
    }

    private function nextNumber(): string
    {
        $date = now('Asia/Jakarta')->format('Ymd');
        for ($i=1; $i<=9999; $i++) {
            $number='GR-'.$date.'-'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
            if (! DB::table('pur_goods_receipts')->where('gr_number',$number)->exists()) return $number;
        }
        return 'GR-'.$date.'-'.Str::upper(Str::random(6));
    }

    private function assertDependencies(): void
    {
        foreach ([
            'wh_v3_goods_receipts','wh_v3_goods_receipt_items','wh_v3_delivery_orders','wh_v3_delivery_order_items',
            'pur_purchase_orders','pur_purchase_order_items','pur_goods_receipts','pur_goods_receipt_items','stk_skus',
        ] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("Hotfix membutuhkan tabel {$table}.");
        }
    }
}
