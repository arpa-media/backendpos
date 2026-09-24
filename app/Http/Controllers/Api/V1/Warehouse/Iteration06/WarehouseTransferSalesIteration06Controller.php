<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Iteration06;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\Iteration06\WarehouseOperationalReadinessV6Service;
use App\Services\Warehouse\Iteration06\WarehouseTransferSpreadsheetV6Service;
use App\Services\Warehouse\SalesCustomerV4\WarehouseSalesCustomerService;
use App\Services\Warehouse\TransferStockV4\WarehouseTransferStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseTransferSalesIteration06Controller extends Controller
{
    public function __construct(
        private readonly WarehouseTransferStockService $transfer,
        private readonly WarehouseSalesCustomerService $sales,
        private readonly WarehouseOperationalReadinessV6Service $readiness,
        private readonly WarehouseTransferSpreadsheetV6Service $spreadsheet,
    ) {}

    public function transferTemplate(Request $request): Response
    {
        return $this->spreadsheet->template($this->warehouseId($request));
    }

    public function transferImportPreview(Request $request): JsonResponse
    {
        $payload = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:15360']]);
        return ApiResponse::ok($this->spreadsheet->preview($this->warehouseId($request), $payload['file']), 'File Transfer Stock valid. Review lalu Simpan Draft.');
    }

    public function transferSubmitReady(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        $this->readiness->assertTransferSubmit($warehouseId, $id);
        return ApiResponse::ok($this->transfer->submit($warehouseId, $id, (string) $request->user()->id), 'Transfer Stock lolos readiness check dan Submitted.');
    }

    public function transferApproveReady(Request $request, string $id): JsonResponse
    {
        $payload = $this->approvalPayload($request);
        $warehouseId = $this->warehouseId($request);
        $this->readiness->assertTransferApprove($warehouseId, $id, $payload);
        return ApiResponse::ok($this->transfer->approve($warehouseId, $id, $payload, (string) $request->user()->id), 'Transfer Stock Approved/Ready dan masuk Checker Prepare.');
    }

    public function salesSubmitReady(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        $this->readiness->assertSalesSubmit($warehouseId, $id);
        return ApiResponse::ok($this->sales->submit($warehouseId, $id, (string) $request->user()->id), 'Sales Order Customer lolos readiness check dan Submitted.');
    }

    public function salesApproveReady(Request $request, string $id): JsonResponse
    {
        $payload = $this->approvalPayload($request);
        $warehouseId = $this->warehouseId($request);
        $this->readiness->assertSalesApprove($warehouseId, $id, $payload);
        return ApiResponse::ok($this->sales->approve($warehouseId, $id, $payload, (string) $request->user()->id), 'Sales Order Customer Approved/Ready dan masuk Checker Prepare Logistics.');
    }

    private function approvalPayload(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', 'max:40', 'distinct'],
            'items.*.approved_qty_uom' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
