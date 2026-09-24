<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\LedgerIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\RecordInvoicePaymentRequest;
use App\Http\Requests\Api\V1\Purchasing\LedgerPaymentIndexRequest;
use App\Services\Purchasing\InvoiceWorkflowService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use App\Services\Purchasing\WarehouseStockRequestLiabilityOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountLedgerController extends Controller
{
    public function __construct(
        private readonly InvoiceWorkflowService $service,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
        private readonly WarehouseStockRequestLiabilityOwnershipService $liabilityOwnership,
    ) {
    }

    public function catalogs(Request $request, string $ledger): JsonResponse
    {
        $data = $this->service->ledgerCatalogs($ledger);
        $key = $ledger === 'account-payable' ? 'account-payables' : 'account-receivables';
        $module = $this->registry->find($key);
        $data['capabilities'] = $module
            ? $this->access->capabilities($request->user(), $module)
            : ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];

        return $this->ok($data);
    }

    public function index(LedgerIndexRequest $request, string $ledger): JsonResponse
    {
        $data = $this->service->ledgerPaginate($ledger, $request->validated());
        if ($ledger === 'account-payable') $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        return $this->ok($data);
    }

    public function show(string $ledger, string $id): JsonResponse
    {
        $data = $this->service->ledgerShow($ledger, $id);
        if ($ledger === 'account-payable') $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        return $this->ok($data);
    }

    public function payments(LedgerPaymentIndexRequest $request, string $ledger): JsonResponse
    {
        return $this->ok($this->service->ledgerPayments($ledger, $request->validated()));
    }

    public function payment(RecordInvoicePaymentRequest $request, string $ledger, string $id): JsonResponse
    {
        if ($ledger === 'account-payable') $this->liabilityOwnership->assertInvoiceCanBePaid($id);
        return $this->ok($this->service->ledgerPayment($ledger, $id, $request->validated(), $request->user()));
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
