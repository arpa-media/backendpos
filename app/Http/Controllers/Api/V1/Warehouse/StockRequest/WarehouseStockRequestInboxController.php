<?php

namespace App\Http\Controllers\Api\V1\Warehouse\StockRequest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseStockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseStockRequestInboxController extends Controller
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
            'status' => ['nullable', 'string', 'max:30'],
            'needed_from' => ['nullable', 'date'],
            'needed_to' => ['nullable', 'date', 'after_or_equal:needed_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::ok($this->service->listInbox($warehouseId, $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }
        return ApiResponse::ok($this->service->show($id, null, $warehouseId));
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        return ApiResponse::ok(
            $this->service->accept($id, $warehouseId, (string) $request->user()->id),
            'Stock Request diterima dan masuk status review.'
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
