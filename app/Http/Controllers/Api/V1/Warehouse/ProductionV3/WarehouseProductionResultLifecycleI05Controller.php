<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ProductionV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ProductionV3\WarehouseProductionV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseProductionResultLifecycleI05Controller extends Controller
{
    public function __construct(private readonly WarehouseProductionV3Service $service) {}

    public function reject(Request $request, string $id, string $resultId): JsonResponse
    {
        $payload = $request->validate(['reason' => ['required','string','min:3','max:2000']]);
        return ApiResponse::ok(
            $this->service->rejectResult($this->warehouseId($request), $id, $resultId, $payload['reason'], (string) $request->user()->id),
            'Production Result ditandai Not Approved.'
        );
    }

    public function destroy(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->deleteResult($this->warehouseId($request), $id, $resultId, (string) $request->user()->id),
            'Draft/Rejected Production Result dihapus.'
        );
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
