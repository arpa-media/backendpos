<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\Purchasing\FundRequestDecision;
use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NonWarehouseStockRequestFundBridgeService
{
    public const SOURCE_TYPE = 'NON_WAREHOUSE_STOCK_REQUEST';
    public const SOURCE_KEY_PREFIX = 'NON_WAREHOUSE_STOCK_REQUEST:';
    public const CHANNEL = 'non_warehouse_procurement';

    public function __construct(
        private readonly FundRequestSourceBridgeService $sourceBridge,
        private readonly FundRequestService $fundRequestService,
    ) {
    }

    public function submitForApproval(StockRequest $stockRequest, User $actor): FundRequest
    {
        if ((string) $stockRequest->request_channel !== self::CHANNEL) {
            throw ValidationException::withMessages([
                'request_channel' => ['Dokumen bukan Non-Warehouse Stock Request canonical.'],
            ]);
        }

        $stockRequest->loadMissing(['items.sku', 'outlet', 'nonWarehouseSupplierSource']);

        $fundRequest = $this->sourceBridge->syncDraft([
            'source_key' => self::SOURCE_KEY_PREFIX . (string) $stockRequest->id,
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
                'request_channel' => self::CHANNEL,
                'supplier_name' => (string) ($stockRequest->supplier_name_snapshot ?? ''),
                'supplier_contact' => $stockRequest->supplier_contact_snapshot,
                'supplier_phone' => $stockRequest->supplier_phone_snapshot,
                'matched_supplier_source_id' => $stockRequest->non_warehouse_supplier_source_id
                    ? (string) $stockRequest->non_warehouse_supplier_source_id
                    : null,
                'matched_supplier_code' => $stockRequest->nonWarehouseSupplierSource?->code,
            ],
            'items' => $stockRequest->items->map(function ($item) use ($stockRequest): array {
                $qtyUom = round((float) ($item->requested_qty_uom ?: $item->requested_qty), 4);
                $qtyBase = round((float) ($item->requested_qty_base ?: $item->requested_qty), 4);
                $factor = round((float) ($item->conversion_factor_snapshot ?: 1), 8);
                $uomText = trim((string) ($item->request_uom_code_snapshot ?: $item->base_uom_code_snapshot ?: 'UNIT'));

                return [
                    'sku_id' => (string) $item->sku_id,
                    'item_name' => (string) ($item->sku?->name ?: $item->sku_id),
                    'uom_text' => $uomText,
                    'qty' => $qtyUom,
                    'estimated_unit_price' => 0,
                    'tax_mode' => 'NO_TAX',
                    'tax_percent' => 0,
                    'notes' => $item->notes,
                    'source_line_key' => (string) $item->id,
                    'metadata' => [
                        'stock_request_item_id' => (string) $item->id,
                        'request_uom_id' => $item->request_uom_id ? (string) $item->request_uom_id : null,
                        'request_uom_code' => $item->request_uom_code_snapshot,
                        'base_uom_id' => $item->base_uom_id_snapshot ? (string) $item->base_uom_id_snapshot : null,
                        'base_uom_code' => $item->base_uom_code_snapshot,
                        'qty_uom' => $qtyUom,
                        'conversion_factor' => $factor,
                        'qty_base' => $qtyBase,
                        'supplier_name' => (string) ($stockRequest->supplier_name_snapshot ?? ''),
                    ],
                ];
            })->values()->all(),
        ], $actor);

        return DB::transaction(function () use ($fundRequest, $stockRequest, $actor): FundRequest {
            /** @var FundRequest $locked */
            $locked = FundRequest::query()->lockForUpdate()->findOrFail($fundRequest->id);

            if ($locked->status === FundRequest::STATUS_DRAFT) {
                $locked->fill([
                    'status' => FundRequest::STATUS_AWAITING_APPROVAL,
                    'submitted_by_user_id' => (string) $actor->id,
                    'submitted_at' => now(),
                    'updated_by_user_id' => (string) $actor->id,
                    'lock_version' => (int) $locked->lock_version + 1,
                ])->save();

                $this->fundRequestService->appendDocumentEvent(
                    $locked,
                    'REQUEST',
                    (string) $locked->id,
                    'NON_WAREHOUSE_STOCK_REQUEST_SUBMITTED',
                    'Menunggu Approval SPV Outlet',
                    FundRequest::STATUS_AWAITING_APPROVAL,
                    $actor,
                    'Non-Warehouse Stock Request diajukan ke Purchasing tanpa menambah Actual Stock.',
                    [
                        'source_stock_request_id' => (string) $stockRequest->id,
                        'supplier_name' => (string) ($stockRequest->supplier_name_snapshot ?? ''),
                    ],
                    'STOCK_REQUEST',
                    (string) $stockRequest->id,
                    (string) $stockRequest->request_number,
                );
            }

            StockRequest::query()->whereKey($stockRequest->id)->update([
                'canonical_fund_request_id' => (string) $locked->id,
                'request_approval_status' => match ((string) $locked->status) {
                    FundRequest::STATUS_APPROVED => 'approved1',
                    FundRequest::STATUS_REJECTED => 'rejected',
                    default => 'awaiting_approval1',
                },
                'purchasing_handoff_status' => match ((string) $locked->status) {
                    FundRequest::STATUS_APPROVED => 'awaiting_order_management',
                    FundRequest::STATUS_REJECTED => 'rejected',
                    default => 'awaiting_request_approval',
                },
                'updated_by_user_id' => (string) $actor->id,
                'updated_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function synchronizeDecision(FundRequest $fundRequest, string $action, User $actor, ?string $notes = null): bool
    {
        if ((string) $fundRequest->source_type !== self::SOURCE_TYPE) {
            return false;
        }

        $action = match ((string) $fundRequest->status) {
            FundRequest::STATUS_APPROVED => 'APPROVE',
            FundRequest::STATUS_REJECTED => 'REJECT',
            default => strtoupper(trim($action)),
        };

        if (! in_array($action, ['APPROVE', 'REJECT'], true)) {
            return false;
        }

        $stockRequestId = $this->stockRequestId($fundRequest);

        DB::transaction(function () use ($stockRequestId, $fundRequest, $action, $actor, $notes): void {
            /** @var StockRequest $stockRequest */
            $stockRequest = StockRequest::query()
                ->where('request_channel', self::CHANNEL)
                ->lockForUpdate()
                ->findOrFail($stockRequestId);

            if ($action === 'REJECT') {
                if ($stockRequest->status === StockRequest::STATUS_REJECTED
                    && $stockRequest->request_approval_status === 'rejected') {
                    return;
                }

                $stockRequest->items()->update([
                    'status' => StockRequest::STATUS_REJECTED,
                    'approved_qty' => 0,
                ]);
                $stockRequest->fill([
                    'status' => StockRequest::STATUS_REJECTED,
                    'request_approval_status' => 'rejected',
                    'purchasing_handoff_status' => 'rejected',
                    'decided_by_user_id' => (string) $actor->id,
                    'decided_at' => now(),
                    'lock_version' => (int) $stockRequest->lock_version + 1,
                    'updated_by_user_id' => (string) $actor->id,
                ])->save();

                $this->timeline(
                    $stockRequest,
                    'non_warehouse_request_rejected_1',
                    StockRequest::STATUS_REJECTED,
                    'Non-Warehouse Stock Request ditolak pada Approval Purchasing.',
                    $actor,
                    ['fund_request_id' => (string) $fundRequest->id, 'notes' => $notes],
                );
                return;
            }

            if ($stockRequest->status === StockRequest::STATUS_APPROVED
                && $stockRequest->request_approval_status === 'approved1') {
                return;
            }

            foreach ($stockRequest->items()->get() as $item) {
                $item->fill([
                    'status' => StockRequest::STATUS_APPROVED,
                    // Legacy approved_qty remains Base UOM for compatibility.
                    'approved_qty' => round((float) ($item->requested_qty_base ?: $item->requested_qty), 4),
                    'approved_by_user_id' => (string) $actor->id,
                    'approved_at' => now(),
                ])->save();
            }

            $stockRequest->fill([
                'status' => StockRequest::STATUS_APPROVED,
                'request_approval_status' => 'approved1',
                'purchasing_handoff_status' => 'awaiting_order_management',
                'request_approved_by_user_id' => (string) $actor->id,
                'request_approved_at' => now(),
                'decided_by_user_id' => (string) $actor->id,
                'decided_at' => now(),
                'lock_version' => (int) $stockRequest->lock_version + 1,
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            $this->timeline(
                $stockRequest,
                'non_warehouse_request_approved_1',
                StockRequest::STATUS_APPROVED,
                'Non-Warehouse Stock Request disetujui. Menunggu Order Management; Actual Stock belum berubah.',
                $actor,
                [
                    'fund_request_id' => (string) $fundRequest->id,
                    'fund_request_number' => (string) $fundRequest->request_number,
                    'next_step' => 'ORDER_MANAGEMENT',
                ],
            );
        }, 3);

        return true;
    }

    public function cancelFromStockRequest(StockRequest $stockRequest, User $actor, ?string $notes = null): void
    {
        if ((string) $stockRequest->request_channel !== self::CHANNEL) {
            return;
        }

        $fundRequest = FundRequest::query()
            ->where(function ($query) use ($stockRequest): void {
                if ($stockRequest->canonical_fund_request_id) {
                    $query->where('id', (string) $stockRequest->canonical_fund_request_id)
                        ->orWhere('source_key', self::SOURCE_KEY_PREFIX . (string) $stockRequest->id);
                    return;
                }
                $query->where('source_key', self::SOURCE_KEY_PREFIX . (string) $stockRequest->id);
            })
            ->first();

        if (! $fundRequest || ! in_array($fundRequest->status, [FundRequest::STATUS_DRAFT, FundRequest::STATUS_AWAITING_APPROVAL], true)) {
            return;
        }

        DB::transaction(function () use ($fundRequest, $stockRequest, $actor, $notes): void {
            /** @var FundRequest $locked */
            $locked = FundRequest::query()->lockForUpdate()->findOrFail($fundRequest->id);
            if (! in_array($locked->status, [FundRequest::STATUS_DRAFT, FundRequest::STATUS_AWAITING_APPROVAL], true)) {
                return;
            }

            $previousStatus = (string) $locked->status;
            $idempotencyKey = 'nw-stock-cancel:' . (string) $stockRequest->id;
            $existing = FundRequestDecision::query()
                ->where('fund_request_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            $locked->fill([
                'status' => FundRequest::STATUS_REJECTED,
                'rejected_by_user_id' => (string) $actor->id,
                'rejected_at' => now(),
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            if (! $existing) {
                FundRequestDecision::query()->create([
                    'fund_request_id' => $locked->id,
                    'step_code' => 'SOURCE_CANCELLATION',
                    'action' => 'REJECT',
                    'previous_status' => $previousStatus,
                    'new_status' => FundRequest::STATUS_REJECTED,
                    'notes' => $notes ?: 'Source Non-Warehouse Stock Request dibatalkan Admin.',
                    'idempotency_key' => $idempotencyKey,
                    'actor_user_id' => (string) $actor->id,
                    'actor_snapshot' => ['name' => $actor->name, 'nisj' => $actor->nisj],
                    'metadata' => ['source_stock_request_id' => (string) $stockRequest->id],
                    'occurred_at' => now(),
                ]);
            }

            $this->fundRequestService->appendDocumentEvent(
                $locked,
                'REQUEST',
                (string) $locked->id,
                'SOURCE_REQUEST_CANCELLED',
                'Source Cancelled',
                FundRequest::STATUS_REJECTED,
                $actor,
                $notes ?: 'Non-Warehouse Stock Request dibatalkan Admin.',
                ['source_stock_request_id' => (string) $stockRequest->id],
                'STOCK_REQUEST',
                (string) $stockRequest->id,
                (string) $stockRequest->request_number,
            );
        }, 3);
    }

    private function stockRequestId(FundRequest $fundRequest): string
    {
        $id = trim((string) $fundRequest->source_id);
        if ($id === '' && str_starts_with((string) $fundRequest->source_key, self::SOURCE_KEY_PREFIX)) {
            $id = substr((string) $fundRequest->source_key, strlen(self::SOURCE_KEY_PREFIX));
        }
        if ($id === '') {
            throw ValidationException::withMessages([
                'source_id' => ['Fund Request Non-Warehouse tidak memiliki source Stock Request.'],
            ]);
        }
        return $id;
    }

    /** @param array<string, mixed> $metadata */
    private function timeline(
        StockRequest $stockRequest,
        string $eventCode,
        string $status,
        string $message,
        User $actor,
        array $metadata = [],
    ): void {
        StockRequestTimeline::query()->create([
            'stock_request_id' => (string) $stockRequest->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'actor_user_id' => (string) $actor->id,
        ]);
    }
}
