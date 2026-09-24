<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Fulfillment;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseFulfillmentController extends WarehouseFulfillmentBaseController
{
    public function __construct(private readonly WarehouseFulfillmentService $service)
    {
    }

    public function options(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->options($warehouseId));
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:review,prepare,ready,on-delivery'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->listFulfillments($warehouseId, $filters));
    }

    public function show(Request $request, string $requestId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showFulfillment($requestId, $warehouseId));
    }

    public function assign(Request $request, string $requestId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'checker_user_id' => ['required', 'string', 'max:40'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'string', 'distinct', 'max:40'],
        ]);
        return ApiResponse::ok(
            $this->service->assign($requestId, $warehouseId, $payload, (string) $request->user()->id),
            'Checker Prepare berhasil diassign.'
        );
    }

    public function dispatch(Request $request, string $requestId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'sender_user_id' => ['required', 'string', 'max:40'],
            'estimated_delivery_date' => ['required', 'date'],
            'estimated_delivery_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        return ApiResponse::ok(
            $this->service->dispatch($requestId, $warehouseId, $payload, (string) $request->user()->id),
            'Delivery Order berhasil digenerate dan stock diposting menjadi in-transit.'
        );
    }
}
