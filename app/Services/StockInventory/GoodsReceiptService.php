<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\GoodsReceiptItem;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\SupplierSource;
use App\Services\Cogs\PurchasingCostSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly PurchasingCostSnapshotService $costSnapshots,
        private readonly StockReceivingWindowService $receivingWindow,
    ) {
    }

    public function findOrCreateWarehouseReceipt(string $code, string $outletId, string $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($code, $outletId, $userId) {
            $normalized = strtoupper(trim($code));
            $po = PurchaseOrder::query()
                ->with(['items.sku.baseUom', 'supplierSource', 'outlet'])
                ->where('source_type', 'warehouse')
                ->where('outlet_id', $outletId)
                ->where(function ($query) use ($normalized) {
                    $query->whereRaw('UPPER(po_number) = ?', [$normalized])
                        ->orWhereRaw('UPPER(shipment_code) = ?', [$normalized]);
                })
                ->lockForUpdate()
                ->first();

            if (! $po) {
                throw ValidationException::withMessages(['shipment_code' => ['Kode pengiriman tidak ditemukan untuk outlet terpilih.']]);
            }

            if (! in_array((string) $po->status, ['approved', 'partially_received', 'received'], true)) {
                throw ValidationException::withMessages(['shipment_code' => ['Purchase Order belum berstatus approved.']]);
            }

            if (! $po->shipment_code) {
                $po->forceFill(['shipment_code' => 'SHIP-'.strtoupper((string) $po->id)])->save();
            }

            $existing = GoodsReceipt::query()->where('purchase_order_id', $po->id)->first();
            if ($existing) {
                if ($existing->status === GoodsReceipt::STATUS_DRAFT) {
                    $this->receivingWindow->applySnapshot($existing);
                    $existing->save();
                }

                return $existing->fresh($this->relations());
            }

            $receipt = GoodsReceipt::query()->create([
                'gr_number' => $this->nextNumber('GR-WH'),
                'receipt_type' => GoodsReceipt::TYPE_WAREHOUSE,
                'outlet_id' => $po->outlet_id,
                'purchase_order_id' => $po->id,
                'supplier_source_id' => $po->supplier_source_id,
                'shipment_code' => $po->shipment_code,
                'receipt_date' => now()->toDateString(),
                'status' => GoodsReceipt::STATUS_DRAFT,
                'currency' => $po->currency ?: 'IDR',
                'total_amount' => 0,
                'received_by_user_id' => $userId,
                'received_at' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $this->receivingWindow->applySnapshot($receipt);
            $notBefore = $receipt->delivery_not_before_at?->copy()->timezone('Asia/Jakarta');
            $nowJakarta = now('Asia/Jakarta');
            if (! $notBefore || $nowJakarta->greaterThanOrEqualTo($notBefore)) {
                $receipt->forceFill([
                    'received_at' => $nowJakarta->copy()->utc(),
                    'receipt_date' => $nowJakarta->toDateString(),
                    'received_at_input_by_user_id' => $userId,
                    'received_at_input_at' => now(),
                ])->save();
            } else {
                $receipt->save();
            }

            foreach ($po->items as $poItem) {
                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'sku_id' => $poItem->sku_id,
                    'ordered_qty' => $poItem->approved_qty,
                    'received_qty' => $poItem->approved_qty,
                    'unit_cost' => $poItem->unit_price,
                    'line_total' => round((float) $poItem->approved_qty * (float) $poItem->unit_price, 2),
                ]);
            }

            $this->recalculate($receipt);
            return $receipt->fresh($this->relations());
        });
    }

    public function createManual(array $data, string $outletId, string $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $outletId, $userId) {
            $supplier = SupplierSource::query()
                ->whereKey($data['supplier_source_id'])
                ->where('source_type', 'other_supplier')
                ->where('is_active', true)
                ->firstOrFail();

            $receipt = GoodsReceipt::query()->create([
                'gr_number' => $this->nextNumber('GR-MN'),
                'receipt_type' => GoodsReceipt::TYPE_MANUAL,
                'outlet_id' => $outletId,
                'supplier_source_id' => $supplier->id,
                'supplier_document_number' => $data['supplier_document_number'] ?? null,
                'receipt_date' => $data['receipt_date'],
                'status' => GoodsReceipt::STATUS_DRAFT,
                'currency' => 'IDR',
                'notes' => $data['notes'] ?? null,
                'received_by_user_id' => $userId,
                'received_at' => now(),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            foreach ($data['items'] as $row) {
                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'sku_id' => $row['sku_id'],
                    'received_qty' => round((float) $row['received_qty'], 4),
                    'unit_cost' => round((float) $row['unit_cost'], 4),
                    'line_total' => round((float) $row['received_qty'] * (float) $row['unit_cost'], 2),
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            $this->recalculate($receipt);
            return $receipt->fresh($this->relations());
        });
    }

    public function updateDraft(GoodsReceipt $receipt, array $data, string $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $userId) {
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== GoodsReceipt::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => ['Goods Receipt yang sudah direlease tidak dapat diedit.']]);
            }

            $items = $receipt->items()->get()->keyBy(fn ($item) => (string) $item->id);
            foreach ($data['items'] as $row) {
                $item = $items->get((string) $row['item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => ['Item Goods Receipt tidak valid.']]);
                }
                $qty = round((float) $row['received_qty'], 4);
                if ($receipt->receipt_type === GoodsReceipt::TYPE_WAREHOUSE && $qty > (float) $item->ordered_qty) {
                    throw ValidationException::withMessages(['items' => ['Qty diterima tidak boleh melebihi qty PO.']]);
                }
                $cost = array_key_exists('unit_cost', $row) ? round((float) $row['unit_cost'], 4) : (float) $item->unit_cost;
                $item->forceFill([
                    'received_qty' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => round($qty * $cost, 2),
                    'notes' => $row['notes'] ?? $item->notes,
                ])->save();
            }

            if ($receipt->receipt_type === GoodsReceipt::TYPE_WAREHOUSE) {
                $this->receivingWindow->applySnapshot($receipt);
                if (array_key_exists('received_at', $data) && $data['received_at']) {
                    $receivedAt = $this->receivingWindow->validateAndNormalize($receipt, $data['received_at']);
                    $receipt->received_at = $receivedAt->utc();
                    $receipt->receipt_date = $receivedAt->toDateString();
                    $receipt->received_at_input_by_user_id = $userId;
                    $receipt->received_at_input_at = now();
                }
            }

            $receipt->forceFill([
                'supplier_document_number' => $data['supplier_document_number'] ?? $receipt->supplier_document_number,
                'receipt_date' => $receipt->receipt_date ?: ($data['receipt_date'] ?? now('Asia/Jakarta')->toDateString()),
                'notes' => $data['notes'] ?? $receipt->notes,
                'updated_by_user_id' => $userId,
                'lock_version' => ((int) $receipt->lock_version) + 1,
            ])->save();
            $this->recalculate($receipt);

            return $receipt->fresh($this->relations());
        });
    }

    public function release(GoodsReceipt $receipt, string $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId) {
            $receipt = GoodsReceipt::query()->with('items')->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === GoodsReceipt::STATUS_RELEASED) {
                $this->costSnapshots->captureReceipt($receipt, $userId);
                return $receipt->fresh($this->relations());
            }
            if ($receipt->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Goods Receipt tidak memiliki item.']]);
            }

            foreach ($receipt->items as $item) {
                if ((float) $item->received_qty <= 0) {
                    throw ValidationException::withMessages(['items' => ['Semua item wajib memiliki qty diterima lebih dari 0.']]);
                }
                if ($receipt->receipt_type === GoodsReceipt::TYPE_WAREHOUSE && abs((float) $item->ordered_qty - (float) $item->received_qty) > 0.0001) {
                    throw ValidationException::withMessages(['items' => ['Release GR hanya dapat dilakukan setelah seluruh qty PO diterima.']]);
                }
            }

            if ($receipt->receipt_type === GoodsReceipt::TYPE_WAREHOUSE) {
                $this->receivingWindow->applySnapshot($receipt);
                $receivedAt = $this->receivingWindow->validateAndNormalize($receipt, $receipt->received_at);
                $receipt->received_at = $receivedAt->utc();
                $receipt->receipt_date = $receivedAt->toDateString();
                if (! $receipt->received_at_input_by_user_id) {
                    $receipt->received_at_input_by_user_id = $userId;
                    $receipt->received_at_input_at = now();
                }
            }

            $receipt->forceFill([
                'status' => GoodsReceipt::STATUS_RELEASED,
                'released_by_user_id' => $userId,
                'released_at' => now(),
                'updated_by_user_id' => $userId,
                'lock_version' => ((int) $receipt->lock_version) + 1,
            ])->save();

            $this->ledger->releaseGoodsReceipt($receipt, $userId);
            $this->costSnapshots->captureReceipt($receipt, $userId);

            if ($receipt->purchase_order_id) {
                PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->update([
                    'status' => 'received',
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }

            return $receipt->fresh($this->relations());
        });
    }

    public function summary(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing([
            'outlet:id,code,name',
            'supplierSource:id,code,name,source_type',
            'purchaseOrder:id,po_number,shipment_code,status,stock_request_id,outlet_id',
        ]);

        return [
            'id' => (string) $receipt->id,
            'gr_number' => (string) $receipt->gr_number,
            'receipt_type' => (string) $receipt->receipt_type,
            'status' => (string) $receipt->status,
            'outlet' => $receipt->outlet ? [
                'id' => (string) $receipt->outlet->id,
                'code' => $receipt->outlet->code,
                'name' => $receipt->outlet->name,
            ] : null,
            'purchase_order' => $receipt->purchaseOrder ? [
                'id' => (string) $receipt->purchaseOrder->id,
                'po_number' => $receipt->purchaseOrder->po_number,
                'shipment_code' => $receipt->purchaseOrder->shipment_code ?? $receipt->shipment_code,
            ] : null,
            'supplier' => $receipt->supplierSource ? [
                'id' => (string) $receipt->supplierSource->id,
                'code' => $receipt->supplierSource->code,
                'name' => $receipt->supplierSource->name,
            ] : null,
            'shipment_code' => $receipt->shipment_code,
            'supplier_document_number' => $receipt->supplier_document_number,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'currency' => $receipt->currency,
            'total_amount' => (float) $receipt->total_amount,
            'item_count' => isset($receipt->items_count)
                ? (int) $receipt->items_count
                : $receipt->items()->count(),
            'received_at' => $receipt->received_at?->toIso8601String(),
            'received_at_local' => $receipt->received_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'delivery_not_before_at' => $receipt->delivery_not_before_at?->toIso8601String(),
            'delivery_not_before_local' => $receipt->delivery_not_before_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'delivery_reference_number' => $receipt->delivery_reference_number,
            'released_at' => $receipt->released_at?->toIso8601String(),
        ];
    }

    public function serialize(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing($this->relations());
        return [
            'id' => (string) $receipt->id,
            'gr_number' => (string) $receipt->gr_number,
            'receipt_type' => (string) $receipt->receipt_type,
            'status' => (string) $receipt->status,
            'outlet' => $receipt->outlet ? ['id' => (string) $receipt->outlet->id, 'code' => $receipt->outlet->code, 'name' => $receipt->outlet->name] : null,
            'purchase_order' => $receipt->purchaseOrder ? ['id' => (string) $receipt->purchaseOrder->id, 'po_number' => $receipt->purchaseOrder->po_number, 'shipment_code' => $receipt->purchaseOrder->shipment_code ?? $receipt->shipment_code] : null,
            'supplier' => $receipt->supplierSource ? ['id' => (string) $receipt->supplierSource->id, 'code' => $receipt->supplierSource->code, 'name' => $receipt->supplierSource->name] : null,
            'shipment_code' => $receipt->shipment_code,
            'supplier_document_number' => $receipt->supplier_document_number,
            'receipt_date' => $receipt->receipt_date?->toDateString(),
            'currency' => $receipt->currency,
            'total_amount' => (float) $receipt->total_amount,
            'notes' => $receipt->notes,
            'received_at' => $receipt->received_at?->toIso8601String(),
            'received_at_local' => $receipt->received_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'delivery_not_before_at' => $receipt->delivery_not_before_at?->toIso8601String(),
            'delivery_not_before_local' => $receipt->delivery_not_before_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'delivery_reference_type' => $receipt->delivery_reference_type,
            'delivery_reference_id' => $receipt->delivery_reference_id,
            'delivery_reference_number' => $receipt->delivery_reference_number,
            'received_at_input_at' => $receipt->received_at_input_at?->toIso8601String(),
            'released_at' => $receipt->released_at?->toIso8601String(),
            'items' => $receipt->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => $item->sku?->sku_code,
                'sku_name' => $item->sku?->name,
                'uom_symbol' => $item->sku?->baseUom?->symbol,
                'ordered_qty' => $item->ordered_qty === null ? null : (float) $item->ordered_qty,
                'received_qty' => (float) $item->received_qty,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
                'notes' => $item->notes,
            ])->values()->all(),
        ];
    }

    private function recalculate(GoodsReceipt $receipt): void
    {
        $receipt->forceFill(['total_amount' => round((float) $receipt->items()->sum('line_total'), 2)])->save();
    }

    private function nextNumber(string $prefix): string
    {
        do {
            $number = sprintf('%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(6)));
        } while (GoodsReceipt::query()->where('gr_number', $number)->exists());

        return $number;
    }

    private function relations(): array
    {
        return ['outlet:id,code,name', 'supplierSource:id,code,name,source_type', 'purchaseOrder:id,po_number,shipment_code,status,stock_request_id,outlet_id', 'items.sku.baseUom'];
    }
}
