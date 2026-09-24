<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\StockInventory\PurchaseOrderItem;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\StockInventory\SupplierSource;
use App\Models\User;
use App\Models\Warehouse\WarehouseStockRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockRequestDraftPoBridgeService
{
    public const SOURCE_TYPE = 'STOCK_INVENTORY_REQUEST';

    public function __construct(
        private readonly FundRequestSourceBridgeService $sourceBridge,
        private readonly FundRequestService $fundRequestService,
        private readonly WarehouseCommercialPriceService $commercialPrice,
    ) {
    }

    public function submitForApproval(WarehouseStockRequest $stockRequest, User $actor): FundRequest
    {
        $stockRequest->loadMissing(['items.sku', 'outlet']);
        $supplier = $this->warehouseSupplier();

        $fundRequest = $this->sourceBridge->syncDraft([
            'source_key' => 'STOCK_REQUEST:' . (string) $stockRequest->id,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $stockRequest->id,
            'request_type' => FundRequest::TYPE_STOCK,
            'chamber_code' => 'OUTLET',
            'outlet_id' => (string) $stockRequest->outlet_id,
            'request_date' => $stockRequest->request_date?->toDateString() ?: now()->toDateString(),
            'needed_date' => $stockRequest->needed_date?->toDateString() ?: now()->toDateString(),
            'notes' => $stockRequest->notes,
            'source_payload' => [
                'stock_request_number' => (string) $stockRequest->request_number,
                'destination_warehouse_id' => (string) $stockRequest->destination_warehouse_id,
                'request_channel' => (string) $stockRequest->request_channel,
            ],
            'items' => $stockRequest->items->map(function ($item) use ($supplier, $stockRequest): array {
                $commercial = $this->commercialPrice->resolveForStockRequest($stockRequest, $item);

                return [
                    'sku_id' => (string) $item->sku_id,
                    'item_name' => (string) ($item->sku?->name ?: $item->sku_id),
                    'uom_text' => (string) $commercial['billing_uom_code'],
                    'qty' => round((float) $commercial['billing_qty'], 4),
                    'estimated_unit_price' => round((float) $commercial['billing_unit_price'], 2),
                    'tax_mode' => 'NO_TAX',
                    'tax_percent' => 0,
                    'notes' => $item->notes,
                    'source_line_key' => (string) $item->id,
                    'metadata' => [
                        'request_uom_id' => $item->request_uom_id ? (string) $item->request_uom_id : null,
                        'requested_qty_uom' => round((float) ($item->requested_qty_uom ?: $item->requested_qty), 4),
                        'conversion_factor' => round((float) ($item->conversion_factor_snapshot ?: 1), 8),
                        'stock_qty_base' => round((float) ($item->requested_qty_base ?: $item->requested_qty), 4),
                        'stock_base_uom_code' => (string) ($item->base_uom_code_snapshot ?: 'BASE'),
                        'warehouse_commercial_price' => $commercial,
                        'price_source' => $commercial['price_source'],
                        'price_reference_id' => $commercial['price_reference_id'],
                    ],
                ];
            })->values()->all(),
        ], $actor);

        return DB::transaction(function () use ($fundRequest, $stockRequest, $actor): FundRequest {
            $fundRequest = FundRequest::query()->lockForUpdate()->findOrFail($fundRequest->id);

            if ($fundRequest->status === FundRequest::STATUS_DRAFT) {
                $fundRequest->fill([
                    'status' => FundRequest::STATUS_AWAITING_APPROVAL,
                    'submitted_by_user_id' => $actor->id,
                    'submitted_at' => now(),
                    'updated_by_user_id' => $actor->id,
                    'lock_version' => (int) $fundRequest->lock_version + 1,
                ])->save();

                $this->fundRequestService->appendDocumentEvent(
                    $fundRequest,
                    'REQUEST',
                    (string) $fundRequest->id,
                    'REQUEST_SUBMITTED',
                    'Menunggu Approval SPV Outlet',
                    FundRequest::STATUS_AWAITING_APPROVAL,
                    $actor,
                    'Stock Request diajukan untuk Approval SPV outlet sebelum draft Purchase Order dibuat.',
                    ['source_stock_request_id' => (string) $stockRequest->id],
                    'STOCK_REQUEST',
                    (string) $stockRequest->id,
                    (string) $stockRequest->request_number,
                );
            }

            WarehouseStockRequest::query()->whereKey($stockRequest->id)->update([
                'canonical_fund_request_id' => (string) $fundRequest->id,
                'request_approval_status' => $fundRequest->status === FundRequest::STATUS_APPROVED ? 'approved1' : 'awaiting_approval1',
                'purchasing_handoff_status' => $fundRequest->status === FundRequest::STATUS_APPROVED ? 'awaiting_po_approval' : 'awaiting_request_approval',
                'updated_by_user_id' => (string) $actor->id,
                'updated_at' => now(),
            ]);

            return $fundRequest->fresh();
        }, 3);
    }


    public function approveStockRequest(WarehouseStockRequest $stockRequest, User $actor, ?string $notes = null): FundRequest
    {
        $fundRequest = FundRequest::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where(function ($query) use ($stockRequest): void {
                $query->where('source_id', (string) $stockRequest->id)
                    ->orWhere('source_key', 'STOCK_REQUEST:' . (string) $stockRequest->id);
            })
            ->latest('created_at')
            ->firstOrFail();

        if ($fundRequest->status !== FundRequest::STATUS_APPROVED) {
            $fundRequest->fill([
                'status' => FundRequest::STATUS_APPROVED,
                'approved_by_user_id' => (string) $actor->id,
                'approved_at' => now(),
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $fundRequest->lock_version + 1,
            ])->save();
        }

        $this->synchronizeDecision($fundRequest->fresh(), 'APPROVE', $actor, $notes);
        return $fundRequest->fresh();
    }

    public function synchronizeDecision(FundRequest $fundRequest, string $action, User $actor, ?string $notes = null): void
    {
        if ((string) $fundRequest->source_type !== self::SOURCE_TYPE) {
            return;
        }

        // The persisted canonical status is the source of truth for retries.
        // This prevents a reused idempotency key sent to the opposite endpoint
        // from making the Stock Request diverge from the Fund Request decision.
        $action = match ((string) $fundRequest->status) {
            FundRequest::STATUS_APPROVED => 'APPROVE',
            FundRequest::STATUS_REJECTED => 'REJECT',
            default => strtoupper(trim($action)),
        };

        if (! in_array($action, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Status canonical request belum menghasilkan keputusan Approval SPV yang valid.'],
            ]);
        }

        $stockRequestId = trim((string) $fundRequest->source_id);
        if ($stockRequestId === '' && str_starts_with((string) $fundRequest->source_key, 'STOCK_REQUEST:')) {
            $stockRequestId = substr((string) $fundRequest->source_key, strlen('STOCK_REQUEST:'));
        }
        if ($stockRequestId === '') {
            throw ValidationException::withMessages(['source_id' => ['Canonical Stock Request tidak memiliki source_id.']]);
        }

        DB::transaction(function () use ($stockRequestId, $fundRequest, $action, $actor, $notes): void {
            $stockRequest = WarehouseStockRequest::query()
                ->with(['items.sku'])
                ->lockForUpdate()
                ->findOrFail($stockRequestId);

            if (strtoupper($action) === 'REJECT') {
                if ($stockRequest->status === WarehouseStockRequest::STATUS_REJECTED && $stockRequest->request_approval_status === 'rejected') {
                    return;
                }

                $stockRequest->fill([
                    'status' => WarehouseStockRequest::STATUS_REJECTED,
                    'request_approval_status' => 'rejected',
                    'purchasing_handoff_status' => 'rejected',
                    'request_approved_by_user_id' => null,
                    'request_approved_at' => null,
                    'decided_by_user_id' => (string) $actor->id,
                    'decided_at' => now(),
                    'lock_version' => (int) $stockRequest->lock_version + 1,
                    'updated_by_user_id' => (string) $actor->id,
                ])->save();
                $stockRequest->items()->update(['status' => WarehouseStockRequest::STATUS_REJECTED]);
                $this->stockTimeline($stockRequest, 'request_rejected_1', WarehouseStockRequest::STATUS_REJECTED, 'Stock Request ditolak pada Approval SPV outlet.', $actor, ['notes' => $notes]);
                return;
            }

            if ($stockRequest->draft_purchase_order_id
                && $stockRequest->request_approval_status === 'approved1'
                && $stockRequest->purchasing_handoff_status === 'approved') {
                return;
            }

            // Stock Request internal ke Warehouse adalah pengecualian approval Finance.
            // Approval SPV Outlet menjadi final approval untuk PR dan PO canonical.
            $fundRequest->fill([
                'status' => FundRequest::STATUS_APPROVED,
                'approved_by_user_id' => (string) $actor->id,
                'approved_at' => $fundRequest->approved_at ?: now(),
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $fundRequest->lock_version + 1,
            ])->save();

            $po = $this->createApprovedPurchaseOrder($stockRequest, $fundRequest, $actor);

            $stockRequest->fill([
                'status' => WarehouseStockRequest::STATUS_REQUESTED,
                'canonical_fund_request_id' => (string) $fundRequest->id,
                'draft_purchase_order_id' => (string) $po->id,
                'request_approval_status' => 'approved1',
                'purchasing_handoff_status' => 'approved',
                'request_approved_by_user_id' => (string) $actor->id,
                'request_approved_at' => now(),
                'decided_by_user_id' => (string) $actor->id,
                'decided_at' => now(),
                'lock_version' => (int) $stockRequest->lock_version + 1,
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            $stockRequest->items()->update([
                'approved_qty' => DB::raw('requested_qty'),
                'status' => WarehouseStockRequest::STATUS_REQUESTED,
                'approved_by_user_id' => (string) $actor->id,
                'approved_at' => now(),
            ]);

            $this->stockTimeline($stockRequest, 'request_spv_auto_approved_pr_po', WarehouseStockRequest::STATUS_REQUESTED, 'Approval SPV selesai. PR dan PO Stock otomatis dibuat dan otomatis approved; Stock Request diteruskan ke Warehouse.', $actor, [
                'fund_request_id' => (string) $fundRequest->id,
                'fund_request_status' => FundRequest::STATUS_APPROVED,
                'purchase_order_id' => (string) $po->id,
                'po_number' => (string) $po->po_number,
                'purchase_order_status' => PurchaseOrder::STATUS_APPROVED,
                'finance_approval_bypassed' => true,
                'bypass_reason' => 'INTERNAL_STOCK_REQUEST_TO_WAREHOUSE',
            ]);

            $this->fundRequestService->appendDocumentEvent(
                $fundRequest,
                'PURCHASE_ORDER',
                (string) $po->id,
                'STOCK_PURCHASE_ORDER_AUTO_APPROVED',
                'Purchase Order otomatis approved',
                PurchaseOrder::STATUS_APPROVED,
                $actor,
                'PR dan PO Stock otomatis approved oleh kebijakan internal setelah approval SPV Outlet.',
                [
                    'stock_request_id' => (string) $stockRequest->id,
                    'finance_approval_bypassed' => true,
                    'bypass_reason' => 'INTERNAL_STOCK_REQUEST_TO_WAREHOUSE',
                ],
                'PURCHASE_ORDER',
                (string) $po->id,
                (string) $po->po_number,
            );
        }, 3);
    }

    public function assertPurchaseOrderApproved(WarehouseStockRequest $stockRequest): PurchaseOrder
    {
        $po = PurchaseOrder::query()
            ->where('stock_request_id', $stockRequest->id)
            ->latest('created_at')
            ->first();

        if (! $po || strtoupper((string) $po->status) !== PurchaseOrder::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'purchase_order' => ['Stock Request belum dapat masuk Warehouse. Purchase Order Stock belum otomatis approved setelah approval SPV Outlet.'],
            ]);
        }

        return $po;
    }

    private function createApprovedPurchaseOrder(WarehouseStockRequest $stockRequest, FundRequest $fundRequest, User $actor): PurchaseOrder
    {
        $supplier = $this->warehouseSupplier(true);

        $po = PurchaseOrder::query()
            ->where('stock_request_id', $stockRequest->id)
            ->where('supplier_source_id', $supplier->id)
            ->lockForUpdate()
            ->first();

        if (! $po) {
            $po = PurchaseOrder::query()->create([
                'po_number' => $this->nextPurchaseOrderNumber(),
                'stock_request_id' => (string) $stockRequest->id,
                'fund_request_id' => (string) $fundRequest->id,
                'supplier_source_id' => (string) $supplier->id,
                'outlet_id' => (string) $stockRequest->outlet_id,
                'source_type' => self::SOURCE_TYPE,
                'order_type' => 'STOCK',
                'status' => PurchaseOrder::STATUS_APPROVED,
                'total_amount' => 0,
                'currency' => 'IDR',
                'created_by_user_id' => (string) $actor->id,
                'updated_by_user_id' => (string) $actor->id,
            ]);
        }

        $total = 0.0;
        foreach ($stockRequest->items as $item) {
            $qty = round((float) ($item->requested_qty_base ?: $item->requested_qty), 4);
            $commercial = $this->commercialPrice->resolveForStockRequest($stockRequest, $item, $qty);
            // Canonical PO storage stays in Base UOM for Stock Inventory/GR compatibility.
            // UI/print uses warehouse_commercial_price from metadata so Harga/UOM matches Warehouse Invoice.
            $price = round((float) $commercial['base_equivalent_unit_price'], 2);
            $lineTotal = round((float) $commercial['line_total'], 2);
            $total += $lineTotal;

            $itemName = trim((string) ($item->sku?->name ?? $item->item_name_snapshot ?? ''));
            $skuCode = trim((string) ($item->sku?->sku_code ?? $item->sku_code_snapshot ?? ''));
            $uomText = trim((string) ($item->base_uom_code_snapshot ?? $item->request_uom_code_snapshot ?? 'UNIT'));

            $payload = [
                'sku_id' => (string) $item->sku_id,
                'approved_qty' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal,
            ];
            if (Schema::hasColumn('pur_purchase_order_items', 'fund_request_item_id')) {
                $payload['fund_request_item_id'] = DB::table('pur_fund_request_items')
                    ->where('fund_request_id', $fundRequest->id)
                    ->where('source_line_key', (string) $item->id)
                    ->value('id');
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'line_no')) {
                $payload['line_no'] = (int) ($item->line_no ?? 0) ?: ($po->items()->count() + 1);
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'item_name')) {
                $payload['item_name'] = $itemName !== '' ? $itemName : ($skuCode !== '' ? $skuCode : (string) $item->sku_id);
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'uom_text')) {
                $payload['uom_text'] = $uomText;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'subtotal')) {
                $payload['subtotal'] = $lineTotal;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'tax_mode')) {
                $payload['tax_mode'] = 'NO_TAX';
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'tax_percent')) {
                $payload['tax_percent'] = 0;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'tax_amount')) {
                $payload['tax_amount'] = 0;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'notes')) {
                $payload['notes'] = $item->notes;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'source_line_key')) {
                $payload['source_line_key'] = (string) $item->id;
            }
            if (Schema::hasColumn('pur_purchase_order_items', 'metadata')) {
                $payload['metadata'] = [
                    'sku_code' => $skuCode,
                    'item_name' => $itemName,
                    'uom_text' => $uomText,
                    'request_uom_id' => $item->request_uom_id ? (string) $item->request_uom_id : null,
                    'requested_qty_uom' => round((float) ($item->requested_qty_uom ?: $item->requested_qty), 4),
                    'conversion_factor' => round((float) ($item->conversion_factor_snapshot ?: 1), 8),
                    'warehouse_commercial_price' => $commercial,
                    'price_source' => $commercial['price_source'],
                    'price_reference_id' => $commercial['price_reference_id'],
                    'storage_basis' => 'BASE_UOM',
                ];
            }

            PurchaseOrderItem::query()->updateOrCreate(
                ['purchase_order_id' => $po->id, 'stock_request_item_id' => $item->id],
                $payload
            );
        }

        $header = [
            'fund_request_id' => (string) $fundRequest->id,
            'order_type' => 'STOCK',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'total_amount' => round($total, 2),
            'approved_by_user_id' => (string) $actor->id,
            'approved_at' => now(),
            'updated_by_user_id' => (string) $actor->id,
        ];
        $po->forceFill($header)->save();

        // Isi kolom workflow Iterasi 05 bila tersedia, tanpa mewajibkan schema tertentu.
        $workflow = [];
        foreach ([
            'submitted_by_user_id' => (string) $actor->id,
            'submitted_at' => now(),
            'finance_approved_1_by_user_id' => (string) $actor->id,
            'finance_approved_1_at' => now(),
            'finance_approved_2_by_user_id' => (string) $actor->id,
            'finance_approved_2_at' => now(),
        ] as $column => $value) {
            if (Schema::hasColumn('pur_purchase_orders', $column)) {
                $workflow[$column] = $value;
            }
        }
        if ($workflow !== []) {
            DB::table('pur_purchase_orders')->where('id', $po->id)->update($workflow + ['updated_at' => now()]);
        }

        return $po->fresh('items');
    }


    private function warehouseSupplier(bool $lock = false): SupplierSource
    {
        $query = SupplierSource::query()
            ->where('code', 'WAREHOUSE-MAIN')
            ->where('is_active', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $supplier = $query->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier' => ['Supplier sistem WAREHOUSE-MAIN tidak ditemukan.']]);
        }
        return $supplier;
    }

    private function nextPurchaseOrderNumber(): string
    {
        do {
            $number = 'PO-STOCK-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (PurchaseOrder::query()->where('po_number', $number)->exists());

        return $number;
    }

    /** @param array<string, mixed> $metadata */
    private function stockTimeline(WarehouseStockRequest $request, string $eventCode, string $status, string $message, User $actor, array $metadata = []): void
    {
        StockRequestTimeline::query()->create([
            'stock_request_id' => (string) $request->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'actor_user_id' => (string) $actor->id,
        ]);
    }
}
