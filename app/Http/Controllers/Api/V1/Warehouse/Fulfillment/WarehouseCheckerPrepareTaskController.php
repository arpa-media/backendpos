<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Fulfillment;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseCheckerPrepareTaskController extends WarehouseFulfillmentBaseController
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
            'status' => ['nullable', 'in:assigned,in_progress,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);
        $override = $this->canOverrideTask($request) && (bool) ($filters['all'] ?? false);
        return ApiResponse::ok($this->service->listTasks($warehouseId, (string) $request->user()->id, $filters, $override));
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showTask($taskId, $warehouseId, (string) $request->user()->id, $this->canOverrideTask($request)));
    }

    public function scan(Request $request, string $taskId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'barcode' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        return ApiResponse::ok(
            $this->service->scan($taskId, $warehouseId, $payload, (string) $request->user()->id, $this->canOverrideTask($request)),
            'Barcode diterima.'
        );
    }

    public function removeAllocation(Request $request, string $taskId, string $allocationId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok(
            $this->service->removeAllocation($taskId, $allocationId, $warehouseId, (string) $request->user()->id, $this->canOverrideTask($request)),
            'Scan berhasil dibatalkan dan reservasi dilepas.'
        );
    }

    public function confirmShortage(Request $request, string $taskId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return ApiResponse::ok(
            $this->service->confirmShortage($taskId, $warehouseId, $payload['reason'], (string) $request->user()->id, $this->canOverrideTask($request)),
            'Shortage dikonfirmasi dan task selesai.'
        );
    }
}
