<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Fulfillment;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseDeliveryOrderController extends WarehouseFulfillmentBaseController
{
    public function __construct(private readonly WarehouseFulfillmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:dispatching,dispatched'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->listDeliveryOrders($warehouseId, $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showDeliveryOrder($id, $warehouseId));
    }

    public function markPrinted(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok(
            $this->service->markPrinted($id, $warehouseId, (string) $request->user()->id),
            'Print event Delivery Order tersimpan.'
        );
    }
}
