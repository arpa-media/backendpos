<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\IssueInvoiceRequest;
use App\Http\Requests\Api\V1\Purchasing\RecordInvoicePaymentRequest;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Purchasing\InvoiceWorkflowService;
use App\Services\Purchasing\WarehouseStockRequestLiabilityOwnershipService;
use Illuminate\Http\JsonResponse;

final class PurchasingInvoiceFinanceAutoPostController extends Controller
{
    public function __construct(
        private readonly InvoiceWorkflowService $workflow,
        private readonly FinancePurchasingPostingService $finance,
        private readonly WarehouseStockRequestLiabilityOwnershipService $liabilityOwnership,
    ) {}

    public function issue(IssueInvoiceRequest $request): JsonResponse
    {
        $direction = $this->direction($request);
        $id = (string) $request->route('id');
        $data = $this->workflow->issue($direction, $id, $request->validated(), $request->user());

        if ($direction === 'incoming') {
            $ownership = $this->liabilityOwnership->coverIfWarehouseStockRequestMirror($id, $request->user()?->id);
            $data['liability_ownership'] = $ownership;
            if ($ownership['is_mirror'] ?? false) {
                $data['finance_posting'] = [
                    'status' => (string) ($ownership['coverage_status'] ?? 'COVERED'),
                    'policy' => WarehouseStockRequestLiabilityOwnershipService::POLICY_KEY,
                    'liability_owner' => 'ORDER_AP_LIFECYCLE',
                    'canonical_ap_invoice_id' => $ownership['canonical_ap_invoice_id'] ?? null,
                    'journal_no' => $ownership['canonical_recognition_journal_no'] ?? null,
                    'general_posting_id' => $ownership['canonical_general_posting_id'] ?? null,
                ];
            } else {
                $data['finance_posting'] = $this->finance->autoPostOutboxByEventKey('invoice-issued:' . $id, $request->user()?->id);
            }
            $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function payment(RecordInvoicePaymentRequest $request): JsonResponse
    {
        $direction = $this->direction($request);
        $id = (string) $request->route('id');
        $payload = $request->validated();

        if ($direction === 'incoming') $this->liabilityOwnership->assertInvoiceCanBePaid($id);
        $data = $this->workflow->recordPayment($direction, $id, $payload, $request->user());

        // ERP POS FINAL I03: payment settlement is no longer posted through
        // the Purchasing outbox. recordPayment() creates a Cash/Bank Treasury
        // DRAFT and Treasury approval is the single General Posting gateway.
        if (! empty($data['treasury_draft'])) {
            $data['finance_posting'] = [
                'status' => (string) ($data['treasury_draft']['status'] ?? 'DRAFT'),
                'source' => 'TREASURY',
                'treasury_transaction_id' => $data['treasury_draft']['id'] ?? null,
                'treasury_number' => $data['treasury_draft']['treasury_number'] ?? null,
                'general_posting_id' => $data['treasury_draft']['general_posting_id'] ?? null,
            ];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function direction($request): string
    {
        $value = strtolower(trim((string) $request->route('direction')));
        if (in_array($value, ['incoming', 'outgoing'], true)) return $value;
        $name = strtolower((string) optional($request->route())->getName());
        if (str_contains($name, '.incoming.')) return 'incoming';
        if (str_contains($name, '.outgoing.')) return 'outgoing';
        $path = strtolower($request->path());
        if (str_contains($path, '/invoices/incoming')) return 'incoming';
        if (str_contains($path, '/invoices/outgoing')) return 'outgoing';
        abort(404, 'Arah invoice tidak dikenali.');
    }
}
