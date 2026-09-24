<?php

namespace App\Services\Warehouse;

use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\GoodsReceiptItem;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\Warehouse\WarehouseDeliveryOrder;
use App\Models\Warehouse\WarehouseDeliveryOrderItem;
use App\Models\Warehouse\WarehouseFulfillment;
use App\Models\Warehouse\WarehouseFulfillmentAllocation;
use App\Models\Warehouse\WarehouseReceiving;
use App\Models\Warehouse\WarehouseReceivingItem;
use App\Models\Warehouse\WarehouseReceivingUnit;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseStockRequest;
use App\Models\Warehouse\WarehouseStockUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseReceivingService
{
    public function __construct(
        private readonly WarehouseOutletReceiptInventoryService $outletInventory,
        private readonly WarehouseLedgerService $warehouseLedger,
    ) {
    }

    public function listForOutlet(string $outletId, array $filters): array
    {
        $query = $this->deliveryOrderForOutletQuery($outletId)
            ->whereIn('status', ['dispatched', 'receiving', 'goods_received'])
            ->with(['warehouse:id,code,name,address', 'outlet:id,code,name,address', 'request:id,request_number,needed_date,status', 'sender:id,name,nisj'])
            ->orderByDesc('dispatched_at');

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === 'pending') {
                $query->whereIn('status', ['dispatched', 'receiving']);
            } elseif ($status === 'completed') {
                $query->where('status', 'goods_received');
            }
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('delivery_number', 'like', $term)
                    ->orWhereHas('request', fn (Builder $request) => $request->where('request_number', 'like', $term))
                    ->orWhereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 20));
        $receivings = WarehouseReceiving::query()
            ->whereIn('delivery_order_id', collect($paginator->items())->pluck('id')->all())
            ->get()->keyBy('delivery_order_id');

        return $this->paginated($paginator, collect($paginator->items())->map(
            fn (WarehouseDeliveryOrder $order) => $this->serializeOrderSummary($order, $receivings->get($order->id))
        )->all());
    }

    public function listForWarehouse(string $warehouseId, array $filters): array
    {
        $query = WarehouseReceiving::query()
            ->where('warehouse_id', $warehouseId)
            ->with(['deliveryOrder:id,delivery_number,status,estimated_delivery_date,estimated_delivery_time,dispatched_at,sender_user_id', 'deliveryOrder.sender:id,name,nisj', 'request:id,request_number,status,needed_date', 'outlet:id,code,name,address', 'receivedBy:id,name,nisj', 'goodsReceipt:id,gr_number,status,total_amount,released_at'])
            ->withCount([
                'units as received_unit_count' => fn (Builder $q) => $q->where('status', 'received'),
                'units as return_open_count' => fn (Builder $q) => $q->where('status', 'return_pending'),
                'units as return_closed_count' => fn (Builder $q) => $q->where('status', 'returned'),
                'units as missing_open_count' => fn (Builder $q) => $q->where('status', 'not_received'),
                'units as missing_closed_count' => fn (Builder $q) => $q->where('status', 'missing_closed'),
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['discrepancy'])) {
            if ($filters['discrepancy'] === 'open') {
                $query->whereHas('units', fn (Builder $q) => $q->whereIn('status', ['return_pending', 'not_received']));
            } elseif ($filters['discrepancy'] === 'closed') {
                $query->whereDoesntHave('units', fn (Builder $q) => $q->whereIn('status', ['return_pending', 'not_received']));
            }
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('receiving_number', 'like', $term)
                    ->orWhereHas('deliveryOrder', fn (Builder $order) => $order->where('delivery_number', 'like', $term))
                    ->orWhereHas('request', fn (Builder $request) => $request->where('request_number', 'like', $term))
                    ->orWhereHas('outlet', fn (Builder $outlet) => $outlet->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 20));
        return $this->paginated($paginator, collect($paginator->items())->map(fn (WarehouseReceiving $row) => $this->serializeReceivingSummary($row))->all());
    }

    public function start(string $deliveryOrderId, string $outletId, string $userId): array
    {
        $receiving = DB::transaction(function () use ($deliveryOrderId, $outletId, $userId): WarehouseReceiving {
            $order = $this->deliveryOrderForOutletQuery($outletId)->lockForUpdate()->findOrFail($deliveryOrderId);
            // Repair legacy/mismatched projection: Stock Request outlet is the source of truth.
            if ((string) $order->outlet_id !== $outletId) {
                $order->forceFill(['outlet_id' => $outletId])->save();
            }
            if (! in_array((string) $order->status, ['dispatched', 'receiving', 'goods_received'], true)) {
                throw ValidationException::withMessages(['delivery_order' => ['Delivery Order belum dapat diterima oleh outlet.']]);
            }

            $receiving = WarehouseReceiving::query()->where('delivery_order_id', $order->id)->lockForUpdate()->first();
            if (! $receiving) {
                $receiving = WarehouseReceiving::query()->create([
                    'delivery_order_id' => $order->id,
                    'stock_request_id' => $order->stock_request_id,
                    'warehouse_id' => $order->warehouse_id,
                    'outlet_id' => $outletId,
                    'receiving_number' => $this->nextReceivingNumber(),
                    'status' => 'in_progress',
                    'started_by_user_id' => $userId,
                    'started_at' => now(),
                    'lock_version' => 1,
                ]);
                $this->bootstrapReceivingRows($receiving, $order);
            } elseif ((string) $receiving->status === 'pending') {
                $receiving->forceFill([
                    'status' => 'in_progress',
                    'started_by_user_id' => $receiving->started_by_user_id ?: $userId,
                    'started_at' => $receiving->started_at ?: now(),
                    'lock_version' => ((int) $receiving->lock_version) + 1,
                ])->save();
            }

            if ((string) $order->status === 'dispatched') {
                $order->forceFill(['status' => 'receiving'])->save();
            }

            return $receiving;
        }, 5);

        return $this->showForOutlet((string) $receiving->delivery_order_id, $outletId);
    }

    public function showForOutlet(string $deliveryOrderId, string $outletId): array
    {
        $order = $this->deliveryOrderForOutletQuery($outletId)->findOrFail($deliveryOrderId);
        $receiving = WarehouseReceiving::query()->where('delivery_order_id', $order->id)->first();
        if (! $receiving) {
            return [
                'order' => $this->serializeOrderSummary($order->load(['warehouse:id,code,name,address', 'outlet:id,code,name,address', 'request:id,request_number,needed_date,status', 'sender:id,name,nisj']), null),
                'receiving' => null,
            ];
        }
        return ['order' => $this->serializeOrderSummary($order->load(['warehouse:id,code,name,address', 'outlet:id,code,name,address', 'request:id,request_number,needed_date,status', 'sender:id,name,nisj']), $receiving), 'receiving' => $this->serializeReceivingDetail($this->loadReceiving($receiving))];
    }

    public function showForWarehouse(string $receivingId, string $warehouseId): array
    {
        $receiving = WarehouseReceiving::query()->where('warehouse_id', $warehouseId)->findOrFail($receivingId);
        return $this->serializeReceivingDetail($this->loadReceiving($receiving));
    }

    public function scan(string $deliveryOrderId, string $outletId, string $barcode, string $idempotencyKey, string $userId): array
    {
        $barcode = strtoupper(trim($barcode));
        $idempotencyKey = trim($idempotencyKey);
        if ($barcode === '' || $idempotencyKey === '') {
            throw ValidationException::withMessages(['barcode' => ['Barcode dan idempotency key wajib diisi.']]);
        }

        $currentReceiving = WarehouseReceiving::query()
            ->where('delivery_order_id', $deliveryOrderId)
            ->where('outlet_id', $outletId)
            ->first();
        if (! $currentReceiving) {
            throw ValidationException::withMessages(['receiving' => ['Klik Mulai Receiving terlebih dahulu.']]);
        }

        $existingEvent = WarehouseScanEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existingEvent) {
            if ((string) $existingEvent->barcode !== $barcode
                || (string) $existingEvent->context_type !== 'stock_request_receiving'
                || (string) $existingEvent->context_id !== (string) $currentReceiving->id) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah digunakan pada scan atau dokumen berbeda.']]);
            }
            $payload = $this->showForOutlet($deliveryOrderId, $outletId);
            $payload['scan_result'] = [
                'accepted' => (string) $existingEvent->result === 'accepted',
                'message' => (string) ($existingEvent->message ?: 'Scan idempotent diproses.'),
                'event_id' => (string) $existingEvent->id,
            ];
            return $payload;
        }

        $scanResult = DB::transaction(function () use ($deliveryOrderId, $outletId, $barcode, $idempotencyKey, $userId): array {
            $receiving = $this->lockReceivingByOrder($deliveryOrderId, $outletId);
            $this->assertReceivingEditable($receiving);

            // Recheck after acquiring the receiving lock so concurrent retries are truly idempotent.
            $lockedExistingEvent = WarehouseScanEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($lockedExistingEvent) {
                if ((string) $lockedExistingEvent->barcode !== $barcode
                    || (string) $lockedExistingEvent->context_type !== 'stock_request_receiving'
                    || (string) $lockedExistingEvent->context_id !== (string) $receiving->id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah digunakan pada scan atau dokumen berbeda.']]);
                }
                return [
                    'accepted' => (string) $lockedExistingEvent->result === 'accepted',
                    'message' => (string) ($lockedExistingEvent->message ?: 'Scan idempotent diproses.'),
                    'event_id' => (string) $lockedExistingEvent->id,
                ];
            }

            $unit = WarehouseStockUnit::query()->where('barcode', $barcode)->first();
            $expected = $unit ? WarehouseReceivingUnit::query()
                ->where('receiving_id', $receiving->id)
                ->where('stock_unit_id', $unit->id)
                ->lockForUpdate()->first() : null;

            if (! $unit || ! $expected) {
                return $this->rejectScan($receiving, $barcode, $unit, $idempotencyKey, $userId, 'Barcode tidak termasuk dalam Delivery Order ini.');
            }
            if ((string) $expected->status !== 'pending') {
                return $this->rejectScan($receiving, $barcode, $unit, $idempotencyKey, $userId, 'Barcode sudah diselesaikan pada proses receiving ini.');
            }
            if (! in_array((string) $unit->status, ['in_transit', 'receiving'], true)) {
                return $this->rejectScan($receiving, $barcode, $unit, $idempotencyKey, $userId, 'Status barcode bukan in-transit.');
            }

            $event = WarehouseScanEvent::query()->create([
                'warehouse_id' => $receiving->warehouse_id,
                'context_type' => 'stock_request_receiving',
                'context_id' => (string) $receiving->id,
                'stock_unit_id' => $unit->id,
                'barcode' => $barcode,
                'expected_sku_id' => $expected->item()->value('sku_id'),
                'actual_sku_id' => $unit->sku_id,
                'result' => 'accepted',
                'message' => 'Barcode diterima oleh outlet.',
                'idempotency_key' => $idempotencyKey,
                'scanned_by_user_id' => $userId,
                'scanned_at' => now(),
                'metadata' => ['workflow' => 'outlet_receiving', 'receiving_unit_id' => (string) $expected->id],
            ]);

            $expected->forceFill([
                'status' => 'received',
                'scan_event_id' => $event->id,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ])->save();
            $unit->forceFill([
                'status' => 'receiving',
                'updated_by_user_id' => $userId,
                'metadata' => array_merge((array) $unit->metadata, ['receiving_id' => (string) $receiving->id, 'received_scan_at' => now()->toIso8601String()]),
            ])->save();
            $this->evaluateReceiving($receiving, $userId);

            return ['accepted' => true, 'message' => 'Barcode diterima oleh outlet.', 'event_id' => (string) $event->id];
        }, 5);

        $payload = $this->showForOutlet($deliveryOrderId, $outletId);
        $payload['scan_result'] = $scanResult;
        return $payload;
    }

    public function resolveUnit(string $deliveryOrderId, string $outletId, string $unitId, string $disposition, string $reason, string $userId): array
    {
        $disposition = strtolower(trim($disposition));
        $reason = trim($reason);
        if (! in_array($disposition, ['return', 'not_received'], true)) {
            throw ValidationException::withMessages(['disposition' => ['Disposition harus return atau not_received.']]);
        }
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Keterangan discrepancy wajib diisi.']]);
        }

        DB::transaction(function () use ($deliveryOrderId, $outletId, $unitId, $disposition, $reason, $userId): void {
            $receiving = $this->lockReceivingByOrder($deliveryOrderId, $outletId);
            $this->assertReceivingEditable($receiving);
            $unit = WarehouseReceivingUnit::query()->where('receiving_id', $receiving->id)->lockForUpdate()->findOrFail($unitId);
            $allowedStatuses = $disposition === 'return' ? ['pending', 'received'] : ['pending'];
            if (! in_array((string) $unit->status, $allowedStatuses, true)) {
                $message = $disposition === 'not_received'
                    ? 'Barcode yang sudah discan sebagai received tidak dapat diubah menjadi not received. Gunakan return.'
                    : 'Barcode sudah memiliki penyelesaian discrepancy.';
                throw ValidationException::withMessages(['unit' => [$message]]);
            }

            $newStatus = $disposition === 'return' ? 'return_pending' : 'not_received';
            $unit->forceFill([
                'status' => $newStatus,
                'disposition_reason' => $reason,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ])->save();
            $unit->stockUnit()->lockForUpdate()->first()?->forceFill([
                'status' => $disposition === 'return' ? 'return_in_transit' : 'missing_in_transit',
                'updated_by_user_id' => $userId,
            ])->save();
            $this->evaluateReceiving($receiving, $userId);
        }, 5);

        return $this->showForOutlet($deliveryOrderId, $outletId);
    }

    public function generateGoodsReceipt(string $deliveryOrderId, string $outletId, array $payload, string $userId): array
    {
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $actualDeliveryAt = trim((string) ($payload['actual_delivery_at'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        if ($idempotencyKey === '' || $actualDeliveryAt === '') {
            throw ValidationException::withMessages(['goods_receipt' => ['Idempotency key dan actual delivery time wajib diisi.']]);
        }
        $actualDelivery = Carbon::parse($actualDeliveryAt)->setTimezone((string) config('app.timezone', 'Asia/Jakarta'));
        $fingerprint = hash('sha256', json_encode([
            'delivery_order_id' => $deliveryOrderId,
            'outlet_id' => $outletId,
            'actual_delivery_at' => $actualDelivery->toIso8601String(),
            'notes' => $notes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $receiving = DB::transaction(function () use ($deliveryOrderId, $outletId, $idempotencyKey, $fingerprint, $actualDelivery, $notes, $userId): WarehouseReceiving {
            $receiving = $this->lockReceivingByOrder($deliveryOrderId, $outletId);
            if ($receiving->stock_goods_receipt_id) {
                $this->assertGoodsReceiptIdempotency($receiving, $idempotencyKey, $fingerprint);
                return $receiving;
            }
            if ((string) $receiving->status !== 'resolved') {
                throw ValidationException::withMessages(['receiving' => ['Goods Receipt belum dapat dibuat sebelum seluruh barcode received/return/not received.']]);
            }
            if ($receiving->units()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['receiving' => ['Masih ada barcode yang belum diselesaikan.']]);
            }

            $order = WarehouseDeliveryOrder::query()->lockForUpdate()->findOrFail($receiving->delivery_order_id);
            $items = WarehouseReceivingItem::query()->where('receiving_id', $receiving->id)->with('units')->lockForUpdate()->get();
            foreach ($items as $item) {
                $expected = round((float) $item->expected_qty_base, 4);
                $resolved = round((float) $item->units->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
                if (abs($expected - $resolved) > 0.0001) {
                    throw ValidationException::withMessages(['items' => ['Total barcode tidak sama dengan qty Delivery Order.']]);
                }
            }

            $po = DB::table('pur_purchase_orders')->where('stock_request_id', $receiving->stock_request_id)->orderBy('created_at')->first();
            $currency = ($po && property_exists($po, 'currency') && $po->currency)
                ? (string) $po->currency
                : 'IDR';
            $receipt = GoodsReceipt::query()->create([
                'gr_number' => $this->nextGoodsReceiptNumber(),
                'receipt_type' => 'stock_request',
                'outlet_id' => $outletId,
                'purchase_order_id' => $po?->id,
                'supplier_source_id' => $po?->supplier_source_id,
                'shipment_code' => $order->delivery_number,
                'supplier_document_number' => $order->delivery_number,
                'receipt_date' => $actualDelivery->toDateString(),
                'status' => GoodsReceipt::STATUS_RELEASED,
                'currency' => $currency,
                'total_amount' => 0,
                'lock_version' => 1,
                'notes' => $notes !== '' ? $notes : 'Penerimaan Stock Request '.$order->delivery_number,
                'received_by_user_id' => $userId,
                'received_at' => $actualDelivery,
                'released_by_user_id' => $userId,
                'released_at' => now(),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $total = 0.0;
            foreach ($items as $item) {
                $receivedQty = round((float) $item->units->where('status', 'received')->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
                $returnQty = round((float) $item->units->whereIn('status', ['return_pending', 'returned'])->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
                $missingQty = round((float) $item->units->whereIn('status', ['not_received', 'missing_closed'])->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
                $lineTotal = round($receivedQty * (float) $item->unit_cost_snapshot, 2);
                $poItemId = $po ? DB::table('pur_purchase_order_items')->where('purchase_order_id', $po->id)->where('sku_id', $item->sku_id)->value('id') : null;
                $grItem = GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItemId,
                    'sku_id' => $item->sku_id,
                    'ordered_qty' => $item->expected_qty_base,
                    'received_qty' => $receivedQty,
                    'unit_cost' => $item->unit_cost_snapshot,
                    'line_total' => $lineTotal,
                    'notes' => sprintf('Received %.4f; Return %.4f; Not received %.4f', $receivedQty, $returnQty, $missingQty),
                ]);
                $grItem->forceFill(['warehouse_receiving_item_id' => $item->id])->save();
                $item->forceFill([
                    'received_qty_base' => $receivedQty,
                    'return_qty_base' => $returnQty,
                    'not_received_qty_base' => $missingQty,
                    'received_value' => $lineTotal,
                    'status' => 'goods_receipt',
                ])->save();
                $total += $lineTotal;
            }
            $receipt->forceFill(['total_amount' => round($total, 2)])->save();
            $this->outletInventory->post($receipt, $userId);

            $units = WarehouseReceivingUnit::query()->where('receiving_id', $receiving->id)->with(['stockUnit', 'allocation'])->lockForUpdate()->get();
            foreach ($units as $unit) {
                if ($unit->status === 'received') {
                    if ($unit->stockUnit) {
                        $unit->stockUnit->forceFill([
                            'status' => 'received',
                            'updated_by_user_id' => $userId,
                            'metadata' => array_merge((array) $unit->stockUnit->metadata, [
                                'outlet_received_id' => $outletId,
                                'warehouse_receiving_id' => (string) $receiving->id,
                                'goods_receipt_id' => (string) $receipt->id,
                                'goods_receipt_number' => (string) $receipt->gr_number,
                                'goods_receipt_at' => now()->toIso8601String(),
                            ]),
                        ])->save();
                    }
                    $unit->allocation?->forceFill(['status' => 'received'])->save();
                } elseif ($unit->status === 'return_pending') {
                    $unit->stockUnit?->forceFill(['status' => 'return_in_transit', 'updated_by_user_id' => $userId])->save();
                    $unit->allocation?->forceFill(['status' => 'return_pending'])->save();
                } elseif ($unit->status === 'not_received') {
                    $unit->stockUnit?->forceFill(['status' => 'missing_in_transit', 'updated_by_user_id' => $userId])->save();
                    $unit->allocation?->forceFill(['status' => 'not_received'])->save();
                }
            }

            $receiving->forceFill([
                'status' => 'goods_receipt',
                'received_by_user_id' => $userId,
                'actual_delivery_at' => $actualDelivery,
                'completed_by_user_id' => $userId,
                'completed_at' => now(),
                'stock_goods_receipt_id' => $receipt->id,
                'goods_receipt_idempotency_key' => $idempotencyKey,
                'goods_receipt_payload_fingerprint' => $fingerprint,
                'notes' => $notes ?: $receiving->notes,
                'lock_version' => ((int) $receiving->lock_version) + 1,
            ])->save();
            $order->forceFill(['status' => 'goods_received'])->save();
            WarehouseFulfillment::query()->whereKey($order->fulfillment_id)->update(['status' => 'goods_receipt', 'updated_by_user_id' => $userId, 'updated_at' => now()]);
            $request = WarehouseStockRequest::query()->lockForUpdate()->findOrFail($receiving->stock_request_id);
            $request->forceFill(['status' => 'goods-receipt', 'lock_version' => ((int) $request->lock_version) + 1, 'updated_by_user_id' => $userId])->save();
            $request->items()->update(['status' => 'goods-receipt', 'fulfillment_status' => 'goods-receipt']);
            $this->timeline($request, 'goods_receipt_generated', 'goods-receipt', 'Outlet menyelesaikan receiving dan Goods Receipt berhasil diposting.', $userId, [
                'receiving_id' => (string) $receiving->id,
                'receiving_number' => (string) $receiving->receiving_number,
                'goods_receipt_id' => (string) $receipt->id,
                'gr_number' => (string) $receipt->gr_number,
                'actual_delivery_at' => $actualDelivery->toIso8601String(),
                'received_value' => round($total, 2),
            ]);

            return $receiving;
        }, 5);

        return $this->showForOutlet((string) $receiving->delivery_order_id, $outletId);
    }

    public function confirmReturn(string $receivingId, string $unitId, string $warehouseId, string $userId, string $notes): array
    {
        DB::transaction(function () use ($receivingId, $unitId, $warehouseId, $userId, $notes): void {
            $receiving = WarehouseReceiving::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($receivingId);
            if (! $receiving->stock_goods_receipt_id) {
                throw ValidationException::withMessages(['receiving' => ['Goods Receipt outlet belum dibuat.']]);
            }
            $unit = WarehouseReceivingUnit::query()->where('receiving_id', $receiving->id)->with(['stockUnit', 'allocation'])->lockForUpdate()->findOrFail($unitId);
            if ($unit->status === 'returned' && $unit->warehouse_resolution_posting_id) {
                return;
            }
            if ((string) $unit->status !== 'return_pending') {
                throw ValidationException::withMessages(['unit' => ['Hanya barcode return_pending yang dapat diterima kembali.']]);
            }

            $posting = $this->warehouseLedger->post([
                'warehouse_id' => $warehouseId,
                'idempotency_key' => 'RECEIVING-RETURN:'.$unit->id,
                'movement_type' => 'return_in',
                'reference_type' => 'wh_receiving_unit_return',
                'reference_id' => (string) $unit->id,
                'business_date' => now()->toDateString(),
                'reason' => 'Return Stock Request '.$receiving->receiving_number,
                'metadata' => ['receiving_id' => (string) $receiving->id, 'goods_receipt_id' => (string) $receiving->stock_goods_receipt_id, 'notes' => $notes],
                'user_id' => $userId,
                'lines' => [[
                    'line_key' => 'RETURN-'.$unit->id,
                    'sku_id' => (string) $unit->item()->value('sku_id'),
                    'batch_id' => (string) $unit->batch_id,
                    'storage_id' => (string) $unit->storage_id,
                    'direction' => 'IN',
                    'quantity_base' => (float) $unit->qty_base,
                    'unit_cost' => (float) $unit->unit_cost_snapshot,
                    'metadata' => ['receiving_unit_id' => (string) $unit->id, 'barcode' => (string) $unit->stockUnit?->barcode],
                ]],
            ]);

            $unit->forceFill([
                'status' => 'returned',
                'warehouse_resolution_posting_id' => $posting->id,
                'warehouse_resolution_notes' => trim($notes),
                'warehouse_resolved_by_user_id' => $userId,
                'warehouse_resolved_at' => now(),
            ])->save();
            $unit->stockUnit?->forceFill([
                'status' => 'available',
                'warehouse_id' => $warehouseId,
                'storage_id' => $unit->storage_id,
                'updated_by_user_id' => $userId,
            ])->save();
            $unit->allocation?->forceFill(['status' => 'returned'])->save();
        }, 5);

        return $this->showForWarehouse($receivingId, $warehouseId);
    }

    public function closeMissing(string $receivingId, string $unitId, string $warehouseId, string $userId, string $notes): array
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => ['Catatan penyelesaian not received wajib diisi.']]);
        }
        DB::transaction(function () use ($receivingId, $unitId, $warehouseId, $userId, $notes): void {
            $receiving = WarehouseReceiving::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($receivingId);
            if (! $receiving->stock_goods_receipt_id) {
                throw ValidationException::withMessages(['receiving' => ['Goods Receipt outlet belum dibuat.']]);
            }
            $unit = WarehouseReceivingUnit::query()->where('receiving_id', $receiving->id)->with(['stockUnit', 'allocation'])->lockForUpdate()->findOrFail($unitId);
            if ($unit->status === 'missing_closed') {
                return;
            }
            if ((string) $unit->status !== 'not_received') {
                throw ValidationException::withMessages(['unit' => ['Hanya barcode not_received yang dapat ditutup investigasinya.']]);
            }
            $unit->forceFill([
                'status' => 'missing_closed',
                'warehouse_resolution_notes' => $notes,
                'warehouse_resolved_by_user_id' => $userId,
                'warehouse_resolved_at' => now(),
            ])->save();
            $unit->stockUnit?->forceFill(['status' => 'missing', 'updated_by_user_id' => $userId])->save();
            $unit->allocation?->forceFill(['status' => 'missing_closed'])->save();
        }, 5);
        return $this->showForWarehouse($receivingId, $warehouseId);
    }

    public function markPrinted(string $receivingId, ?string $outletId, ?string $warehouseId, string $userId): array
    {
        $query = WarehouseReceiving::query()->whereKey($receivingId);
        if ($outletId) $query->where('outlet_id', $outletId);
        if ($warehouseId) $query->where('warehouse_id', $warehouseId);
        $receiving = $query->firstOrFail();
        if (! $receiving->stock_goods_receipt_id) {
            throw ValidationException::withMessages(['goods_receipt' => ['Goods Receipt belum tersedia untuk dicetak.']]);
        }
        $receiving->forceFill([
            'print_count' => ((int) $receiving->print_count) + 1,
            'last_printed_at' => now(),
            'last_printed_by_user_id' => $userId,
        ])->save();
        return $this->serializeReceivingDetail($this->loadReceiving($receiving));
    }

    private function bootstrapReceivingRows(WarehouseReceiving $receiving, WarehouseDeliveryOrder $order): void
    {
        $order->loadMissing(['items.fulfillmentItem.allocations.stockUnit']);
        foreach ($order->items as $doItem) {
            $allocations = collect($doItem->fulfillmentItem?->allocations ?? collect())
                ->where('status', 'dispatched')
                ->values();
            $allocatedQty = round((float) $allocations->sum(fn ($allocation) => (float) $allocation->qty_base), 4);
            $deliveredQty = round((float) $doItem->delivered_qty_base, 4);
            if (abs($allocatedQty - $deliveredQty) > 0.0001) {
                throw ValidationException::withMessages([
                    'delivery_order' => [sprintf(
                        'Barcode dispatched untuk item %s tidak sama dengan qty Delivery Order (barcode %.4f, DO %.4f).',
                        (string) $doItem->sku_id,
                        $allocatedQty,
                        $deliveredQty,
                    )],
                ]);
            }

            $item = WarehouseReceivingItem::query()->create([
                'receiving_id' => $receiving->id,
                'delivery_order_item_id' => $doItem->id,
                'stock_request_item_id' => $doItem->stock_request_item_id,
                'sku_id' => $doItem->sku_id,
                'expected_qty_base' => $doItem->delivered_qty_base,
                'unit_cost_snapshot' => $doItem->unit_cost_snapshot,
                'status' => (float) $doItem->delivered_qty_base > 0 ? 'pending' : 'resolved',
                'request_uom_code_snapshot' => $doItem->request_uom_code_snapshot,
                'base_uom_code_snapshot' => $doItem->base_uom_code_snapshot,
                'metadata' => ['requested_qty_base' => (float) $doItem->requested_qty_base, 'ready_qty_base' => (float) $doItem->ready_qty_base],
            ]);
            foreach ($allocations as $allocation) {
                WarehouseReceivingUnit::query()->create([
                    'receiving_id' => $receiving->id,
                    'receiving_item_id' => $item->id,
                    'fulfillment_allocation_id' => $allocation->id,
                    'stock_unit_id' => $allocation->stock_unit_id,
                    'batch_id' => $allocation->batch_id,
                    'storage_id' => $allocation->storage_id,
                    'qty_base' => $allocation->qty_base,
                    'unit_cost_snapshot' => $allocation->unit_cost_snapshot,
                    'status' => 'pending',
                ]);
            }
        }
    }

    private function evaluateReceiving(WarehouseReceiving $receiving, string $userId): void
    {
        $items = WarehouseReceivingItem::query()->where('receiving_id', $receiving->id)->with('units')->lockForUpdate()->get();
        $allResolved = true;
        foreach ($items as $item) {
            $received = round((float) $item->units->where('status', 'received')->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
            $returns = round((float) $item->units->whereIn('status', ['return_pending', 'returned'])->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
            $missing = round((float) $item->units->whereIn('status', ['not_received', 'missing_closed'])->sum(fn (WarehouseReceivingUnit $unit) => (float) $unit->qty_base), 4);
            $pending = $item->units->where('status', 'pending')->count();
            $allResolved = $allResolved && $pending === 0;
            $item->forceFill([
                'received_qty_base' => $received,
                'return_qty_base' => $returns,
                'not_received_qty_base' => $missing,
                'received_value' => round($received * (float) $item->unit_cost_snapshot, 2),
                'status' => $pending === 0 ? 'resolved' : (($received + $returns + $missing) > 0 ? 'in_progress' : 'pending'),
            ])->save();
        }
        $receiving->forceFill([
            'status' => $allResolved ? 'resolved' : 'in_progress',
            'lock_version' => ((int) $receiving->lock_version) + 1,
            'metadata' => array_merge((array) $receiving->metadata, ['last_receiving_actor_user_id' => $userId, 'last_evaluated_at' => now()->toIso8601String()]),
        ])->save();
    }

    private function lockReceivingByOrder(string $deliveryOrderId, string $outletId): WarehouseReceiving
    {
        $receiving = WarehouseReceiving::query()
            ->where('delivery_order_id', $deliveryOrderId)
            ->where('outlet_id', $outletId)
            ->lockForUpdate()->first();
        if (! $receiving) {
            throw ValidationException::withMessages(['receiving' => ['Klik Mulai Receiving terlebih dahulu.']]);
        }
        return $receiving;
    }

    private function assertReceivingEditable(WarehouseReceiving $receiving): void
    {
        if (in_array((string) $receiving->status, ['goods_receipt', 'cancelled'], true)) {
            throw ValidationException::withMessages(['receiving' => ['Receiving sudah final dan tidak dapat diubah.']]);
        }
    }

    private function rejectScan(WarehouseReceiving $receiving, string $barcode, ?WarehouseStockUnit $unit, string $idempotencyKey, string $userId, string $message): array
    {
        $event = WarehouseScanEvent::query()->create([
            'warehouse_id' => $receiving->warehouse_id,
            'context_type' => 'stock_request_receiving',
            'context_id' => (string) $receiving->id,
            'stock_unit_id' => null,
            'barcode' => $barcode,
            'expected_sku_id' => null,
            'actual_sku_id' => $unit?->sku_id,
            'result' => 'rejected',
            'message' => $message,
            'idempotency_key' => $idempotencyKey,
            'scanned_by_user_id' => $userId,
            'scanned_at' => now(),
            'metadata' => ['workflow' => 'outlet_receiving', 'rejected_stock_unit_id' => $unit?->id],
        ]);

        return ['accepted' => false, 'message' => $message, 'event_id' => (string) $event->id];
    }

    private function assertGoodsReceiptIdempotency(WarehouseReceiving $receiving, string $key, string $fingerprint): void
    {
        if ((string) $receiving->goods_receipt_idempotency_key !== $key || (string) $receiving->goods_receipt_payload_fingerprint !== $fingerprint) {
            throw ValidationException::withMessages(['idempotency_key' => ['Goods Receipt sudah dibuat menggunakan payload berbeda.']]);
        }
    }

    private function loadReceiving(WarehouseReceiving $receiving): WarehouseReceiving
    {
        return $receiving->load([
            'deliveryOrder.sender:id,name,nisj',
            'request:id,request_number,status,needed_date',
            'warehouse:id,code,name,address', 'outlet:id,code,name,address',
            'startedBy:id,name,nisj', 'receivedBy:id,name,nisj', 'completedBy:id,name,nisj', 'lastPrintedBy:id,name,nisj',
            'goodsReceipt:id,gr_number,receipt_date,status,total_amount,received_at,released_at,notes',
            'goodsReceipt.items:id,goods_receipt_id,warehouse_receiving_item_id,sku_id,ordered_qty,received_qty,unit_cost,line_total,notes',
            'items.sku:id,sku_code,name,base_uom_id', 'items.sku.baseUom:id,code,name,symbol',
            'items.units.stockUnit:id,barcode,status', 'items.units.batch:id,batch_code,expiry_date', 'items.units.storage:id,code,name',
            'items.units.resolvedBy:id,name,nisj', 'items.units.warehouseResolvedBy:id,name,nisj',
        ]);
    }

    private function serializeOrderSummary(WarehouseDeliveryOrder $order, ?WarehouseReceiving $receiving): array
    {
        return [
            'id' => (string) $order->id,
            'delivery_number' => (string) $order->delivery_number,
            'request_number' => (string) ($order->request?->request_number ?? ''),
            'stock_request_id' => (string) $order->stock_request_id,
            'warehouse' => $this->outletPayload($order->warehouse),
            'outlet' => $this->outletPayload($order->outlet),
            'sender' => $this->userPayload($order->sender),
            'estimated_delivery_date' => $order->estimated_delivery_date?->toDateString(),
            'estimated_delivery_time' => substr((string) $order->estimated_delivery_time, 0, 5),
            'dispatched_at' => $order->dispatched_at?->toIso8601String(),
            'status' => (string) $order->status,
            'receiving_id' => $receiving?->id ? (string) $receiving->id : null,
            'receiving_number' => $receiving?->receiving_number,
            'receiving_status' => $receiving?->status,
            'goods_receipt_id' => $receiving?->stock_goods_receipt_id ? (string) $receiving->stock_goods_receipt_id : null,
        ];
    }

    private function serializeReceivingSummary(WarehouseReceiving $receiving): array
    {
        return [
            'id' => (string) $receiving->id,
            'receiving_number' => (string) $receiving->receiving_number,
            'status' => (string) $receiving->status,
            'delivery_number' => (string) ($receiving->deliveryOrder?->delivery_number ?? ''),
            'request_number' => (string) ($receiving->request?->request_number ?? ''),
            'outlet' => $this->outletPayload($receiving->outlet),
            'actual_delivery_at' => $receiving->actual_delivery_at?->toIso8601String(),
            'received_by' => $this->userPayload($receiving->receivedBy),
            'gr_number' => $receiving->goodsReceipt?->gr_number,
            'goods_receipt_total' => round((float) ($receiving->goodsReceipt?->total_amount ?? 0), 2),
            'received_unit_count' => (int) ($receiving->received_unit_count ?? 0),
            'return_open_count' => (int) ($receiving->return_open_count ?? 0),
            'return_closed_count' => (int) ($receiving->return_closed_count ?? 0),
            'missing_open_count' => (int) ($receiving->missing_open_count ?? 0),
            'missing_closed_count' => (int) ($receiving->missing_closed_count ?? 0),
        ];
    }

    private function serializeReceivingDetail(WarehouseReceiving $receiving): array
    {
        $units = $receiving->items->flatMap->units;
        $received = round((float) $units->where('status', 'received')->sum(fn (WarehouseReceivingUnit $u) => (float) $u->qty_base), 4);
        $returns = round((float) $units->whereIn('status', ['return_pending', 'returned'])->sum(fn (WarehouseReceivingUnit $u) => (float) $u->qty_base), 4);
        $missing = round((float) $units->whereIn('status', ['not_received', 'missing_closed'])->sum(fn (WarehouseReceivingUnit $u) => (float) $u->qty_base), 4);
        $pending = round((float) $units->where('status', 'pending')->sum(fn (WarehouseReceivingUnit $u) => (float) $u->qty_base), 4);
        return [
            'id' => (string) $receiving->id,
            'receiving_number' => (string) $receiving->receiving_number,
            'status' => (string) $receiving->status,
            'delivery_order_id' => (string) $receiving->delivery_order_id,
            'delivery_number' => (string) ($receiving->deliveryOrder?->delivery_number ?? ''),
            'request_number' => (string) ($receiving->request?->request_number ?? ''),
            'request_status' => (string) ($receiving->request?->status ?? ''),
            'warehouse' => $this->outletPayload($receiving->warehouse),
            'outlet' => $this->outletPayload($receiving->outlet),
            'sender' => $this->userPayload($receiving->deliveryOrder?->sender),
            'started_by' => $this->userPayload($receiving->startedBy),
            'received_by' => $this->userPayload($receiving->receivedBy),
            'started_at' => $receiving->started_at?->toIso8601String(),
            'actual_delivery_at' => $receiving->actual_delivery_at?->toIso8601String(),
            'completed_at' => $receiving->completed_at?->toIso8601String(),
            'notes' => $receiving->notes,
            'print_count' => (int) $receiving->print_count,
            'last_printed_at' => $receiving->last_printed_at?->toIso8601String(),
            'goods_receipt' => $receiving->goodsReceipt ? [
                'id' => (string) $receiving->goodsReceipt->id,
                'gr_number' => (string) $receiving->goodsReceipt->gr_number,
                'receipt_date' => $receiving->goodsReceipt->receipt_date?->toDateString(),
                'status' => (string) $receiving->goodsReceipt->status,
                'total_amount' => round((float) $receiving->goodsReceipt->total_amount, 2),
                'released_at' => $receiving->goodsReceipt->released_at?->toIso8601String(),
            ] : null,
            'totals' => [
                'expected_qty_base' => round((float) $receiving->items->sum(fn (WarehouseReceivingItem $i) => (float) $i->expected_qty_base), 4),
                'received_qty_base' => $received,
                'return_qty_base' => $returns,
                'not_received_qty_base' => $missing,
                'pending_qty_base' => $pending,
                'received_value' => round((float) $receiving->items->sum(fn (WarehouseReceivingItem $i) => (float) $i->received_value), 2),
                'open_discrepancy_count' => $units->whereIn('status', ['return_pending', 'not_received'])->count(),
            ],
            'items' => $receiving->items->map(fn (WarehouseReceivingItem $item) => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) ($item->sku?->sku_code ?? ''),
                'item_name' => (string) ($item->sku?->name ?? ''),
                'base_uom_code' => (string) ($item->base_uom_code_snapshot ?: $item->sku?->baseUom?->code),
                'expected_qty_base' => round((float) $item->expected_qty_base, 4),
                'received_qty_base' => round((float) $item->received_qty_base, 4),
                'return_qty_base' => round((float) $item->return_qty_base, 4),
                'not_received_qty_base' => round((float) $item->not_received_qty_base, 4),
                'unit_cost' => round((float) $item->unit_cost_snapshot, 6),
                'received_value' => round((float) $item->received_value, 2),
                'status' => (string) $item->status,
                'units' => $item->units->map(fn (WarehouseReceivingUnit $unit) => [
                    'id' => (string) $unit->id,
                    'barcode' => (string) ($unit->stockUnit?->barcode ?? ''),
                    'stock_unit_status' => (string) ($unit->stockUnit?->status ?? ''),
                    'qty_base' => round((float) $unit->qty_base, 4),
                    'status' => (string) $unit->status,
                    'batch_code' => (string) ($unit->batch?->batch_code ?? ''),
                    'storage_code' => (string) ($unit->storage?->code ?? ''),
                    'disposition_reason' => $unit->disposition_reason,
                    'resolved_by' => $this->userPayload($unit->resolvedBy),
                    'resolved_at' => $unit->resolved_at?->toIso8601String(),
                    'warehouse_resolution_posting_id' => $unit->warehouse_resolution_posting_id ? (string) $unit->warehouse_resolution_posting_id : null,
                    'warehouse_resolution_notes' => $unit->warehouse_resolution_notes,
                    'warehouse_resolved_by' => $this->userPayload($unit->warehouseResolvedBy),
                    'warehouse_resolved_at' => $unit->warehouse_resolved_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function timeline(WarehouseStockRequest $request, string $eventCode, ?string $status, string $message, ?string $actorUserId, array $metadata = []): void
    {
        StockRequestTimeline::query()->create([
            'stock_request_id' => $request->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function nextReceivingNumber(): string
    {
        return 'RCV-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function nextGoodsReceiptNumber(): string
    {
        return 'GR-SR-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function outletPayload($outlet): ?array
    {
        return $outlet ? ['id' => (string) $outlet->id, 'code' => (string) $outlet->code, 'name' => (string) $outlet->name, 'address' => (string) ($outlet->address ?? '')] : null;
    }

    private function userPayload($user): ?array
    {
        return $user ? ['id' => (string) $user->id, 'name' => (string) $user->name, 'nisj' => (string) $user->nisj] : null;
    }

    private function deliveryOrderForOutletQuery(string $outletId): Builder
    {
        return WarehouseDeliveryOrder::query()
            ->where(function (Builder $query) use ($outletId): void {
                $query->where('outlet_id', $outletId)
                    ->orWhereHas('request', fn (Builder $request) => $request->where('outlet_id', $outletId));
            });
    }

    private function paginated(LengthAwarePaginator $paginator, array $rows): array
    {
        return ['items' => $rows, 'pagination' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }
}
