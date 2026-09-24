<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\Purchasing\PurchaseOrderDocument;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\User;
use App\Models\Warehouse\WarehouseStockRequest;
use App\Models\Warehouse\WarehouseStockRequestHandoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOrderWarehouseHandoffService
{
    public function __construct(private readonly FundRequestService $fundRequestService)
    {
    }

    public function handoff(PurchaseOrderDocument $order, User $actor): WarehouseStockRequestHandoff
    {
        if (strtoupper((string) $order->order_type) !== FundRequest::TYPE_STOCK
            || strtoupper((string) $order->status) !== 'APPROVED') {
            throw ValidationException::withMessages([
                'status' => ['Warehouse handoff hanya dapat dibuat dari Purchase Order Stock berstatus APPROVED.'],
            ]);
        }

        return DB::transaction(function () use ($order, $actor): WarehouseStockRequestHandoff {
            /** @var PurchaseOrderDocument $order */
            $order = PurchaseOrderDocument::query()
                ->with(['items', 'fundRequest'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            /** @var WarehouseStockRequest $stockRequest */
            $stockRequest = WarehouseStockRequest::query()
                ->with(['items', 'chainSupply'])
                ->lockForUpdate()
                ->findOrFail((string) $order->stock_request_id);

            if (! $stockRequest->destination_warehouse_id) {
                throw ValidationException::withMessages([
                    'destination_warehouse_id' => ['Stock Request belum memiliki Warehouse tujuan.'],
                ]);
            }

            $payload = [
                'stock_request_id' => (string) $stockRequest->id,
                'warehouse_id' => (string) $stockRequest->destination_warehouse_id,
                'chain_supply_id' => $stockRequest->chain_supply_id ? (string) $stockRequest->chain_supply_id : null,
                'purchase_order_id' => (string) $order->id,
                'purchase_order_number' => (string) $order->po_number,
                'needed_date' => $stockRequest->needed_date?->toDateString(),
                'items' => $order->items->map(fn ($item): array => [
                    'line_no' => (int) ($item->line_no ?? 0),
                    'sku_id' => $item->sku_id ? (string) $item->sku_id : null,
                    'qty_base' => round((float) $item->approved_qty, 4),
                    'unit_price' => round((float) $item->unit_price, 2),
                ])->values()->all(),
            ];
            $fingerprint = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $idempotencyKey = 'WH-STOCK-PO:' . (string) $order->id;

            /** @var WarehouseStockRequestHandoff|null $handoff */
            $handoff = WarehouseStockRequestHandoff::query()
                ->where('stock_request_id', $stockRequest->id)
                ->lockForUpdate()
                ->first();

            if ($handoff && $handoff->status === WarehouseStockRequestHandoff::STATUS_GENERATED) {
                if ((string) $handoff->purchase_order_id !== (string) $order->id) {
                    throw ValidationException::withMessages([
                        'handoff' => ['Stock Request sudah memiliki Warehouse handoff dari Purchase Order lain.'],
                    ]);
                }

                // Continue reconciliation so an interrupted previous request
                // also restores PO handoff timestamp and canonical events.
            }

            if (! $handoff) {
                $handoff = WarehouseStockRequestHandoff::query()->create([
                    'stock_request_id' => (string) $stockRequest->id,
                    'warehouse_id' => (string) $stockRequest->destination_warehouse_id,
                    'chain_supply_id' => $stockRequest->chain_supply_id ? (string) $stockRequest->chain_supply_id : null,
                    'handoff_mode' => WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO,
                    'status' => WarehouseStockRequestHandoff::STATUS_GENERATED,
                    'purchase_order_id' => (string) $order->id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_fingerprint' => $fingerprint,
                    'metadata' => $payload,
                    'generated_by_user_id' => (string) $actor->id,
                    'generated_at' => now(),
                ]);
            } else {
                $handoff->fill([
                    'warehouse_id' => (string) $stockRequest->destination_warehouse_id,
                    'chain_supply_id' => $stockRequest->chain_supply_id ? (string) $stockRequest->chain_supply_id : null,
                    'handoff_mode' => WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO,
                    'status' => WarehouseStockRequestHandoff::STATUS_GENERATED,
                    'purchase_order_id' => (string) $order->id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_fingerprint' => $fingerprint,
                    'error_message' => null,
                    'metadata' => $payload,
                    'generated_by_user_id' => (string) $actor->id,
                    'generated_at' => now(),
                ])->save();
            }

            $this->synchronizeStockRequest($stockRequest, $order, $actor);

            $order->fill([
                'warehouse_handoff_key' => $idempotencyKey,
                'warehouse_handoff_at' => now(),
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            if ($order->fundRequest) {
                $eventExists = DB::table('pur_document_events')
                    ->where('root_request_id', $order->fundRequest->id)
                    ->where('document_type', 'PURCHASE_ORDER')
                    ->where('document_id', $order->id)
                    ->where('event_code', 'WAREHOUSE_HANDOFF_CREATED')
                    ->exists();

                if (! $eventExists) {
                    $this->fundRequestService->appendDocumentEvent(
                        $order->fundRequest,
                        'PURCHASE_ORDER',
                        (string) $order->id,
                        'WAREHOUSE_HANDOFF_CREATED',
                        'Goods Receipt',
                        'PENDING',
                        $actor,
                        'Purchase Order Stock telah disetujui Approver Finance dan dikirim otomatis ke Warehouse Inbox.',
                        [
                            'stock_request_id' => (string) $stockRequest->id,
                            'warehouse_id' => (string) $stockRequest->destination_warehouse_id,
                            'handoff_id' => (string) $handoff->id,
                        ],
                        'WAREHOUSE_HANDOFF',
                        (string) $handoff->id,
                        (string) $order->po_number,
                    );
                }
            }

            return $handoff->fresh();
        }, 3);
    }

    private function synchronizeStockRequest(WarehouseStockRequest $stockRequest, PurchaseOrderDocument $order, User $actor): void
    {
        $stockRequest->fill([
            'status' => WarehouseStockRequest::STATUS_REQUESTED,
            'purchasing_handoff_status' => 'generated',
            'draft_purchase_order_id' => (string) $order->id,
            'updated_by_user_id' => (string) $actor->id,
            'lock_version' => (int) $stockRequest->lock_version + 1,
        ])->save();

        $stockRequest->items()->update([
            'status' => WarehouseStockRequest::STATUS_REQUESTED,
        ]);

        $exists = StockRequestTimeline::query()
            ->where('stock_request_id', $stockRequest->id)
            ->where('event_code', 'finance_po_approved_and_handed_off')
            ->exists();

        if (! $exists) {
            StockRequestTimeline::query()->create([
                'stock_request_id' => (string) $stockRequest->id,
                'event_code' => 'finance_po_approved_and_handed_off',
                'status' => WarehouseStockRequest::STATUS_REQUESTED,
                'message' => 'Approver Finance selesai. Purchase Order Stock otomatis masuk Warehouse Inbox.',
                'metadata' => [
                    'purchase_order_id' => (string) $order->id,
                    'po_number' => (string) $order->po_number,
                    'po_status' => (string) $order->status,
                ],
                'actor_user_id' => (string) $actor->id,
            ]);
        }
    }
}
