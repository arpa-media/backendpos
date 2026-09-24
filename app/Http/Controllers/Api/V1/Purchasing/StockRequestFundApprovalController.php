<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\FundRequestDecisionRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Purchasing\FundRequest;
use App\Services\Purchasing\FundRequestService;
use App\Services\Purchasing\OrderWorkflowService;
use App\Services\Purchasing\NonWarehouseStockRequestFundBridgeService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use App\Services\Purchasing\StockRequestDraftPoBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StockRequestFundApprovalController extends Controller
{
    public function __construct(
        private readonly FundRequestService $service,
        private readonly StockRequestDraftPoBridgeService $bridge,
        private readonly NonWarehouseStockRequestFundBridgeService $nonWarehouseBridge,
        private readonly OrderWorkflowService $orders,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
    ) {
    }

    public function approve(FundRequestDecisionRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request->user(), $id);

        $fundRequest = DB::transaction(function () use ($request, $id): FundRequest {
            $fundRequest = $this->service->decide(
                $id,
                'APPROVE',
                (string) $request->validated('idempotency_key'),
                $request->validated('notes'),
                $request->user(),
            );
            if ($fundRequest->request_type === FundRequest::TYPE_STOCK) {
                $handled = $this->nonWarehouseBridge->synchronizeDecision($fundRequest, 'APPROVE', $request->user(), $request->validated('notes'));
                if (! $handled) {
                    $this->bridge->synchronizeDecision($fundRequest, 'APPROVE', $request->user(), $request->validated('notes'));
                }
            } else {
                $this->orders->ensureDraftFromApprovedFundRequest($fundRequest, $request->user());
            }

            return $fundRequest->fresh();
        }, 3);

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            $fundRequest->request_type === FundRequest::TYPE_STOCK
                ? ((string) $fundRequest->source_type === NonWarehouseStockRequestFundBridgeService::SOURCE_TYPE
                    ? 'Approval SPV berhasil. Non-Warehouse Stock Request menunggu Order Management; Actual Stock belum berubah.'
                    : 'Approval SPV berhasil. Purchase Order Stock canonical telah dibuat sesuai flow internal.')
                : 'Approval Chamber berhasil. Draft Order canonical otomatis dibuat.'
        );
    }

    public function reject(FundRequestDecisionRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request->user(), $id);

        $fundRequest = DB::transaction(function () use ($request, $id): FundRequest {
            $fundRequest = $this->service->decide(
                $id,
                'REJECT',
                (string) $request->validated('idempotency_key'),
                $request->validated('notes'),
                $request->user(),
            );
            $handled = $this->nonWarehouseBridge->synchronizeDecision($fundRequest, 'REJECT', $request->user(), $request->validated('notes'));
            if (! $handled) {
                $this->bridge->synchronizeDecision($fundRequest, 'REJECT', $request->user(), $request->validated('notes'));
            }

            return $fundRequest->fresh();
        }, 3);

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            'Request berhasil ditolak.'
        );
    }

    private function visibleRequest($user, string $id): FundRequest
    {
        return $this->service->visibleQuery($user)->findOrFail($id);
    }

    /** @return array{view: bool, create: bool, edit: bool, delete: bool} */
    private function menuCapabilities($user): array
    {
        $module = $this->registry->find('fund-requests');
        if (! $module) {
            return ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];
        }

        return $this->access->capabilities($user, $module);
    }
}
