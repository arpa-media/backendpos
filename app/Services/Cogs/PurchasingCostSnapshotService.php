<?php

namespace App\Services\Cogs;

use App\Models\Cogs\PurchasingCostSnapshot;
use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\InventoryMovement;
use Illuminate\Support\Facades\DB;

class PurchasingCostSnapshotService
{
    public function __construct(private readonly CanonicalReceiptIdentityService $receiptIdentity)
    {
    }

    public function captureReceipt(GoodsReceipt $receipt, ?string $userId = null): int
    {
        $receipt->loadMissing([
            'outlet:id,code,name',
            'supplierSource:id,code,name,source_type',
            'purchaseOrder:id,po_number,shipment_code,status,stock_request_id',
            'purchaseOrder.request:id,request_number',
            'items.sku.baseUom',
            'items.purchaseOrderItem',
        ]);

        $captured = 0;
        foreach ($receipt->items as $item) {
            $movement = InventoryMovement::query()
                ->where('reference_type', 'stk_goods_receipt')
                ->where('reference_line_id', $item->id)
                ->first();

            $existing = PurchasingCostSnapshot::query()
                ->where('goods_receipt_item_id', $item->id)
                ->first();

            if ($existing) {
                // Snapshot labels and prices are immutable. Only repair trace/identity fields that were
                // unavailable during a legacy backfill.
                $trace = $this->movementTrace($movement, (float) $item->received_qty, (float) $item->line_total);
                $updates = [];
                foreach (['inventory_movement_id', 'balance_qty_after', 'average_cost_before', 'average_cost_after', 'inventory_value_after'] as $field) {
                    if ($existing->{$field} === null && $trace[$field] !== null) {
                        $updates[$field] = $trace[$field];
                    }
                }
                if ($this->receiptIdentity->identityColumnsAvailable()) {
                    foreach ($this->receiptIdentity->stockReceiptSnapshotIdentity($receipt, $item) as $field => $value) {
                        if (($existing->{$field} ?? null) != $value) {
                            $updates[$field] = $value;
                        }
                    }
                }
                if ($updates !== []) {
                    $existing->forceFill($updates)->save();
                }
                continue;
            }

            $trace = $this->movementTrace($movement, (float) $item->received_qty, (float) $item->line_total);
            $sku = $item->sku;
            $uom = $sku?->baseUom;
            $supplier = $receipt->supplierSource;
            $po = $receipt->purchaseOrder;
            $stockRequest = $po?->request;

            $payload = [
                'goods_receipt_id' => (string) $receipt->id,
                'goods_receipt_item_id' => (string) $item->id,
                'inventory_movement_id' => $trace['inventory_movement_id'],
                'outlet_id' => (string) $receipt->outlet_id,
                'sku_id' => (string) $item->sku_id,
                'supplier_source_id' => $receipt->supplier_source_id ? (string) $receipt->supplier_source_id : null,
                'purchase_order_id' => $receipt->purchase_order_id ? (string) $receipt->purchase_order_id : null,
                'purchase_order_item_id' => $item->purchase_order_item_id ? (string) $item->purchase_order_item_id : null,
                'stock_request_id' => $po?->stock_request_id ? (string) $po->stock_request_id : null,
                'stock_request_item_id' => $item->purchaseOrderItem?->stock_request_item_id ? (string) $item->purchaseOrderItem->stock_request_item_id : null,
                'gr_number_snapshot' => (string) $receipt->gr_number,
                'receipt_type_snapshot' => (string) $receipt->receipt_type,
                'receipt_date' => $receipt->receipt_date?->toDateString() ?: now()->toDateString(),
                'status_snapshot' => (string) $receipt->status,
                'outlet_code_snapshot' => (string) ($receipt->outlet?->code ?? ''),
                'outlet_name_snapshot' => (string) ($receipt->outlet?->name ?? ''),
                'supplier_code_snapshot' => $supplier?->code,
                'supplier_name_snapshot' => $supplier?->name,
                'supplier_type_snapshot' => $supplier?->source_type ?: $receipt->receipt_type,
                'request_number_snapshot' => $stockRequest?->request_number,
                'po_number_snapshot' => $po?->po_number,
                'shipment_code_snapshot' => $receipt->shipment_code ?: $po?->shipment_code,
                'supplier_document_number_snapshot' => $receipt->supplier_document_number,
                'sku_code_snapshot' => (string) ($sku?->sku_code ?? ''),
                'sku_name_snapshot' => (string) ($sku?->name ?? ''),
                'base_uom_code_snapshot' => $uom?->code,
                'base_uom_symbol_snapshot' => $uom?->symbol,
                'ordered_qty' => $item->ordered_qty,
                'received_qty' => $item->received_qty,
                'unit_cost' => $item->unit_cost,
                'line_total' => $item->line_total,
                'currency' => (string) ($receipt->currency ?: 'IDR'),
                'price_source_snapshot' => $receipt->receipt_type === GoodsReceipt::TYPE_WAREHOUSE ? 'purchase_order' : 'manual_supplier',
                'balance_qty_after' => $trace['balance_qty_after'],
                'average_cost_before' => $trace['average_cost_before'],
                'average_cost_after' => $trace['average_cost_after'],
                'inventory_value_after' => $trace['inventory_value_after'],
                'released_at' => $receipt->released_at ?: now(),
                'released_by_user_id' => $receipt->released_by_user_id ?: $userId,
                'source_snapshot' => [
                    'goods_receipt_id' => (string) $receipt->id,
                    'goods_receipt_item_id' => (string) $item->id,
                    'purchase_order_id' => $receipt->purchase_order_id ? (string) $receipt->purchase_order_id : null,
                    'purchase_order_item_id' => $item->purchase_order_item_id ? (string) $item->purchase_order_item_id : null,
                    'stock_request_id' => $po?->stock_request_id ? (string) $po->stock_request_id : null,
                    'stock_request_item_id' => $item->purchaseOrderItem?->stock_request_item_id ? (string) $item->purchaseOrderItem->stock_request_item_id : null,
                    'supplier_source_id' => $receipt->supplier_source_id ? (string) $receipt->supplier_source_id : null,
                    'shipment_code' => $receipt->shipment_code,
                    'supplier_document_number' => $receipt->supplier_document_number,
                ],
            ];
            if ($this->receiptIdentity->identityColumnsAvailable()) {
                $payload = array_merge($payload, $this->receiptIdentity->stockReceiptSnapshotIdentity($receipt, $item));
            }
            PurchasingCostSnapshot::query()->create($payload);
            $captured++;
        }

        return $captured;
    }

    public function backfillReleasedReceipts(): array
    {
        $receiptCount = 0;
        $snapshotCount = 0;

        GoodsReceipt::query()
            ->where('status', GoodsReceipt::STATUS_RELEASED)
            ->orderBy('id')
            ->chunkById(100, function ($receipts) use (&$receiptCount, &$snapshotCount): void {
                foreach ($receipts as $receipt) {
                    $receiptCount++;
                    $snapshotCount += $this->captureReceipt($receipt, $receipt->released_by_user_id);
                }
            }, 'id');

        return ['receipts' => $receiptCount, 'snapshots_created' => $snapshotCount];
    }

    public function repairMovementValues(): int
    {
        return InventoryMovement::query()
            ->whereNull('inventory_value_after')
            ->update([
                'inventory_value_after' => DB::raw('ROUND(balance_qty_after * average_cost_after, 2)'),
            ]);
    }

    private function movementTrace(?InventoryMovement $movement, float $receivedQty, float $lineTotal): array
    {
        if (! $movement) {
            return [
                'inventory_movement_id' => null,
                'balance_qty_after' => null,
                'average_cost_before' => null,
                'average_cost_after' => null,
                'inventory_value_after' => null,
            ];
        }

        $afterQty = (float) $movement->balance_qty_after;
        $afterAverage = (float) $movement->average_cost_after;
        $afterValue = $movement->inventory_value_after !== null
            ? (float) $movement->inventory_value_after
            : round($afterQty * $afterAverage, 2);
        $beforeQty = round($afterQty - $receivedQty, 4);
        $beforeValue = round($afterValue - $lineTotal, 2);
        $beforeAverage = $beforeQty > 0.0000001 ? round($beforeValue / $beforeQty, 4) : 0.0;

        return [
            'inventory_movement_id' => (string) $movement->id,
            'balance_qty_after' => round($afterQty, 4),
            'average_cost_before' => $beforeAverage,
            'average_cost_after' => round($afterAverage, 4),
            'inventory_value_after' => round($afterValue, 2),
        ];
    }
}
