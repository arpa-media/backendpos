<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\Inventory\WarehouseSkuUomBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseSkuUomBulkController extends WarehouseInventoryBaseController
{
    public function __construct(private readonly WarehouseSkuUomBulkService $service) {}

    public function candidates(Request $request, string $skuId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->candidates($skuId));
    }

    public function adopt(Request $request, string $skuId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate([
            'uom_id' => ['required', 'ulid', 'exists:stk_uoms,id'],
            'is_purchase_default' => ['nullable', 'boolean'],
            'is_request_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        return ApiResponse::ok($this->service->adopt($skuId, $data, $request->user()?->id), 'Mapping UOM berhasil diadopsi dari graph HPP/COGS.');
    }

    public function export(Request $request): Response|JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return $this->service->export();
    }

    public function import(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:15360']]);
        return ApiResponse::ok($this->service->import($data['file'], $request->user()?->id), 'Import mapping UOM selesai.');
    }
}
