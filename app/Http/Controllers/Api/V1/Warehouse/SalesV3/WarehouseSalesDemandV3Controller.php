<?php

namespace App\Http\Controllers\Api\V1\Warehouse\SalesV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseSalesDemandV3Controller extends Controller
{
    public function __construct(private readonly WarehouseSalesDemandV3Service $service)
    {
    }

    public function stockRequests(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','in:pending,approved,rejected,cancelled'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);
        return ApiResponse::ok([
            'summary' => $this->service->stockRequestSummary($warehouseId),
            'list' => $this->service->listStockRequests($warehouseId, $filters),
        ]);
    }

    public function stockRequest(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->stockRequestDetail($this->warehouseId($request), $id));
    }

    public function approveStockRequest(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40'],
            'items.*.approved_qty_uom' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);
        return ApiResponse::ok(
            $this->service->approveStockRequest($this->warehouseId($request), $id, $payload, (string) $request->user()->id),
            'Stock Request disetujui dan langsung masuk Checker Prepare Logistics v3.'
        );
    }

    public function rejectStockRequest(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required','string','min:3','max:1000'],
        ]);
        return ApiResponse::ok(
            $this->service->rejectStockRequest($this->warehouseId($request), $id, (string) $payload['reason'], (string) $request->user()->id),
            'Stock Request ditolak dan tidak diteruskan ke Checker Prepare.'
        );
    }

    public function productionRequests(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','in:pending,approved,cancelled'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);
        return ApiResponse::ok($this->service->listProductionRequests($this->warehouseId($request), $filters));
    }

    public function productionRequest(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->productionRequestDetail($this->warehouseId($request), $id));
    }

    public function approveProductionRequest(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40'],
            'items.*.approved_qty_uom' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);
        return ApiResponse::ok(
            $this->service->approveProductionRequest($this->warehouseId($request), $id, $payload, (string) $request->user()->id),
            'Production Request disetujui. Material issue diposting ke ledger dan Production Order menjadi ongoing.'
        );
    }

    public function logisticsQueue(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'source_type' => ['nullable','string','max:40'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);
        return ApiResponse::ok($this->service->logisticsQueue($this->warehouseId($request), $filters));
    }

    public function logisticsQueueDetail(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->logisticsQueueDetail($this->warehouseId($request), $id));
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
