<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Receiving;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseReceivingMonitorController extends Controller
{
    public function __construct(private readonly WarehouseReceivingService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:pending,in_progress,resolved,goods_receipt,cancelled'],
            'discrepancy' => ['nullable', 'in:open,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->listForWarehouse($warehouseId, $filters));
    }

    public function show(Request $request, string $receivingId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showForWarehouse($receivingId, $warehouseId));
    }

    public function confirmReturn(Request $request, string $receivingId, string $unitId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        return ApiResponse::ok(
            $this->service->confirmReturn($receivingId, $unitId, $warehouseId, (string) $request->user()->id, (string) ($payload['notes'] ?? '')),
            'Barang retur berhasil diterima kembali dan stock Warehouse diposting masuk.'
        );
    }

    public function closeMissing(Request $request, string $receivingId, string $unitId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        return ApiResponse::ok(
            $this->service->closeMissing($receivingId, $unitId, $warehouseId, (string) $request->user()->id, $payload['notes']),
            'Investigasi not received berhasil ditutup.'
        );
    }

    public function markPrinted(Request $request, string $receivingId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->markPrinted($receivingId, null, $warehouseId, (string) $request->user()->id));
    }

    private function warehouseId(Request $request): string|JsonResponse
    {
        $warehouseId = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        if ($warehouseId === '') {
            return ApiResponse::error('Pilih warehouse terlebih dahulu.', 'WAREHOUSE_SCOPE_REQUIRED', 422, ['warehouse_id' => ['Warehouse wajib dipilih.']]);
        }
        return $warehouseId;
    }
}
