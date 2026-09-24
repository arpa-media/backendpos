<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Ledger;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseLedgerReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseLedgerReconciliationController extends WarehouseLedgerBaseController
{
    public function index(Request $request, WarehouseLedgerReconciliationService $service): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->normalizeBooleanQuery($request, 'only_variance');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'only_variance' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return ApiResponse::ok($service->inspect($warehouseId, $filters));
    }

    public function repair(Request $request, WarehouseLedgerReconciliationService $service): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'sku_id' => ['nullable', 'ulid', 'exists:stk_skus,id'],
        ]);

        $run = $service->run($warehouseId, true, $request->user()?->id, $data['sku_id'] ?? null);

        return ApiResponse::ok([
            'run_id' => (string) $run->id,
            'status' => (string) $run->status,
            'checked_sku_count' => (int) $run->checked_sku_count,
            'variance_sku_count' => (int) $run->variance_sku_count,
            'repaired_sku_count' => (int) $run->repaired_sku_count,
            'negative_balance_count' => (int) $run->negative_balance_count,
            'summary' => $run->summary,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ], 'Reconciliation repair selesai.');
    }
}
