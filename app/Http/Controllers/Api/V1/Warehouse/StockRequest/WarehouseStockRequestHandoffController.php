<?php

namespace App\Http\Controllers\Api\V1\Warehouse\StockRequest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseStockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseStockRequestHandoffController extends Controller
{
    public function __construct(private readonly WarehouseStockRequestService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:queued,generated,failed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::ok($this->service->listHandoffs($warehouseId, $filters));
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        return ApiResponse::ok(
            $this->service->generateHandoff($id, $warehouseId, (string) $request->user()->id),
            'Purchasing handoff berhasil digenerate.'
        );
    }

    private function warehouseId(Request $request): string|JsonResponse
    {
        $warehouseId = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        if ($warehouseId === '') {
            return ApiResponse::error(
                'Pilih warehouse terlebih dahulu.',
                'WAREHOUSE_SCOPE_REQUIRED',
                422,
                ['warehouse_id' => ['Warehouse wajib dipilih untuk menu ini.']]
            );
        }
        return $warehouseId;
    }
}
