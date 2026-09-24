<?php

namespace App\Http\Controllers\Api\V1\Warehouse\PurchasingV3;

use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseProcurementBaseController;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\PurchasingV3\WarehousePurchasingV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehousePurchaseRequestV3Controller extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehousePurchasingV3Service $service) {}

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
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'partially_approved', 'approved', 'completed', 'rejected'])],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        return ApiResponse::ok($this->service->listRequests($warehouseId, $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showRequest($id, $warehouseId));
    }

    public function store(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $this->validatePayload($request);
        return ApiResponse::ok(
            $this->service->saveRequest(null, $warehouseId, $payload, (string) $request->user()->id),
            'Draft Purchase Request v3 berhasil dibuat.',
            201
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $this->validatePayload($request);
        return ApiResponse::ok(
            $this->service->saveRequest($id, $warehouseId, $payload, (string) $request->user()->id),
            'Draft Purchase Request v3 berhasil diperbarui.'
        );
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok(
            $this->service->submitRequest($id, $warehouseId, (string) $request->user()->id),
            'Purchase Request berhasil disubmit.'
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('wh_purchase_request_items', 'id')],
            'items.*.approved_qty_uom' => ['nullable', 'numeric', 'min:0'],
            'items.*.approved_purchase_price' => ['nullable', 'numeric', 'min:0'],
            // Compatibility untuk client sebelum Adjustment Stage 01.
            'items.*.approved_qty_base' => ['nullable', 'numeric', 'min:0'],
            'items.*.approved_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::ok(
            $this->service->approveRequest($id, $warehouseId, $payload, (string) $request->user()->id),
            'Purchase Request disetujui dan Purchase Order otomatis dibuat.'
        );
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'supplier_source_id' => [
                'required', 'string',
                Rule::exists('pur_supplier_sources', 'id')
                    ->where('is_active', true)
                    ->whereIn('source_type', ['supplier', 'other_supplier'])
                    ->whereNotIn('code', ['OTHER-SUPPLIER', 'WAREHOUSE-MAIN']),
            ],
            'request_date' => ['required', 'date_format:Y-m-d'],
            'needed_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:request_date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['nullable', 'string'],
            'items.*.sku_id' => ['required', 'string', 'distinct', Rule::exists('stk_skus', 'id')->where('is_active', true)],
            'items.*.request_uom_id' => ['required', 'string', Rule::exists('stk_uoms', 'id')->where('is_active', true)],
            'items.*.requested_qty_uom' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
