<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\CostingRequest;
use App\Models\Purchasing\DocumentEvent;
use App\Models\Purchasing\FundRequest;
use App\Models\Purchasing\FundRequestDecision;
use App\Models\User;
use App\Services\Purchasing\FundRequestService;
use App\Services\Purchasing\OrderWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CostingPurchasingBridgeService
{
    public function __construct(
        private readonly FundRequestService $fundRequests,
        private readonly OrderWorkflowService $orders,
    ) {
    }

    public function handoff(CostingRequest $costing, User $actor): CostingRequest
    {
        return DB::transaction(function () use ($costing, $actor): CostingRequest {
            /** @var CostingRequest $locked */
            $locked = CostingRequest::query()->lockForUpdate()->findOrFail($costing->id);

            if ($locked->status !== CostingRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Costing GA harus berstatus Approved sebelum handoff Purchasing.']);
            }

            if ($locked->purchasing_order_id && $locked->purchasing_fund_request_id) {
                return $locked->fresh();
            }

            $workflow = strtoupper((string) $locked->workflow_code_snapshot);
            $requestType = $workflow === 'PURCHASE_ASSET_ORDER'
                ? FundRequest::TYPE_ASSET
                : FundRequest::TYPE_REIMBURSE;
            $sourceKey = 'GA_COSTING:'.(string) $locked->id;
            $today = now('Asia/Jakarta')->toDateString();

            $fundRequest = $this->fundRequests->upsertAutomaticDraft([
                'request_type' => $requestType,
                'chamber_code' => 'GA',
                'outlet_id' => $locked->outlet_id,
                'request_date' => $today,
                'needed_date' => $today,
                'marking' => 'UNMARKING',
                'notes' => sprintf(
                    'Auto generated dari Costing GA %s. Rekening tujuan: %s. %s',
                    $locked->request_no,
                    $locked->destination_account,
                    $locked->description
                ),
                'source_type' => 'GA_COSTING',
                'source_id' => (string) $locked->id,
                'source_key' => $sourceKey,
                'source_payload' => [
                    'ga_request_no' => $locked->request_no,
                    'category_code' => $locked->category_code_snapshot,
                    'category_name' => $locked->category_name_snapshot,
                    'workflow_code' => $locked->workflow_code_snapshot,
                    'destination_account' => $locked->destination_account,
                ],
                'items' => [[
                    'item_name' => 'Costing GA - '.$locked->category_name_snapshot,
                    'uom_text' => 'LOT',
                    'qty' => 1,
                    'estimated_unit_price' => (float) $locked->amount,
                    'tax_mode' => 'NO_TAX',
                    'tax_percent' => 0,
                    'notes' => $locked->description,
                    'source_line_key' => 'GA_COSTING_LINE:'.(string) $locked->id,
                    'metadata' => [
                        'ga_costing_request_id' => (string) $locked->id,
                        'ga_request_no' => $locked->request_no,
                    ],
                ]],
            ], $actor);

            $fundRequest = FundRequest::query()->lockForUpdate()->findOrFail($fundRequest->id);
            if ($fundRequest->status === FundRequest::STATUS_DRAFT) {
                $now = now();
                $fundRequest->forceFill([
                    'status' => FundRequest::STATUS_APPROVED,
                    'submitted_by_user_id' => $actor->id,
                    'submitted_at' => $now,
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => $now,
                    'updated_by_user_id' => $actor->id,
                    'lock_version' => (int) $fundRequest->lock_version + 1,
                ])->save();

                FundRequestDecision::query()->firstOrCreate(
                    [
                        'fund_request_id' => $fundRequest->id,
                        'idempotency_key' => 'GA_COSTING_APPROVAL:'.(string) $locked->id,
                    ],
                    [
                        'step_code' => 'GA_COSTING_APPROVAL',
                        'action' => 'APPROVE',
                        'previous_status' => FundRequest::STATUS_DRAFT,
                        'new_status' => FundRequest::STATUS_APPROVED,
                        'notes' => 'Approved dari Costing GA dan diteruskan otomatis ke Purchasing.',
                        'actor_user_id' => $actor->id,
                        'actor_snapshot' => ['name' => $actor->name, 'nisj' => $actor->nisj],
                        'metadata' => ['ga_costing_request_id' => (string) $locked->id],
                        'occurred_at' => $now,
                    ]
                );

                DocumentEvent::query()->firstOrCreate(
                    [
                        'root_request_id' => $fundRequest->id,
                        'document_type' => 'REQUEST',
                        'document_id' => (string) $fundRequest->id,
                        'event_code' => 'GA_COSTING_APPROVED',
                    ],
                    [
                        'event_label' => 'GA Costing Approved',
                        'status' => FundRequest::STATUS_APPROVED,
                        'actor_user_id' => $actor->id,
                        'actor_name_snapshot' => $actor->name,
                        'occurred_at' => $now,
                        'notes' => 'Fund Request otomatis disetujui dari workflow Costing GA.',
                        'reference_type' => 'GA_COSTING',
                        'reference_id' => (string) $locked->id,
                        'reference_number' => $locked->request_no,
                        'metadata' => ['source_key' => $sourceKey],
                    ]
                );
            }

            $order = $this->orders->ensureDraftFromApprovedFundRequest($fundRequest->fresh(), $actor);
            if (! $order) {
                throw ValidationException::withMessages(['purchasing' => 'Gagal membuat Draft Order Purchasing dari Costing GA.']);
            }

            $orderKind = $requestType === FundRequest::TYPE_ASSET ? 'PURCHASE_ORDER' : 'REIMBURSE_ORDER';
            $orderNumber = $requestType === FundRequest::TYPE_ASSET
                ? (string) ($order->po_number ?? '')
                : (string) ($order->reimburse_order_number ?? '');

            if ($requestType === FundRequest::TYPE_REIMBURSE && method_exists($order, 'forceFill')) {
                $order->forceFill([
                    'payment_destination' => $order->payment_destination ?: $locked->destination_account,
                    'counterparty_name' => $order->counterparty_name ?: $locked->requester_name_snapshot,
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }

            $locked->forceFill([
                'purchasing_fund_request_id' => (string) $fundRequest->id,
                'purchasing_fund_request_number' => (string) $fundRequest->request_number,
                'purchasing_order_kind' => $orderKind,
                'purchasing_order_id' => (string) $order->id,
                'purchasing_order_number' => $orderNumber,
                'purchasing_handoff_at' => now(),
                'bridge_error' => null,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $locked->fresh();
        }, 3);
    }
}
