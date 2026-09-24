<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Receiving;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseReceivingOutletTypeScopeResolver;
use App\Services\Warehouse\WarehouseReceivingService;
use App\Services\Purchasing\StockRequestReceiptInvoiceBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseOutletReceivingController extends Controller
{
    public function __construct(
        private readonly WarehouseReceivingOutletTypeScopeResolver $scopeResolver,
        private readonly WarehouseReceivingService $service,
        private readonly StockRequestReceiptInvoiceBridgeService $purchasingBridge,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:pending,completed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->listForOutlet((string) $scope['selected']->id, $filters));
    }

    public function show(Request $request, string $deliveryOrderId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        return ApiResponse::ok($this->service->showForOutlet($deliveryOrderId, (string) $scope['selected']->id));
    }

    public function start(Request $request, string $deliveryOrderId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        return ApiResponse::ok(
            $this->service->start($deliveryOrderId, (string) $scope['selected']->id, (string) $request->user()->id),
            'Receiving berhasil dimulai.'
        );
    }

    public function scan(Request $request, string $deliveryOrderId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $request->validate([
            'barcode' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        return ApiResponse::ok(
            $this->service->scan($deliveryOrderId, (string) $scope['selected']->id, $payload['barcode'], $payload['idempotency_key'], (string) $request->user()->id),
            'Scan receiving diproses.'
        );
    }

    public function resolveUnit(Request $request, string $deliveryOrderId, string $unitId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $request->validate([
            'disposition' => ['required', 'in:return,not_received'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        return ApiResponse::ok(
            $this->service->resolveUnit($deliveryOrderId, (string) $scope['selected']->id, $unitId, $payload['disposition'], $payload['reason'], (string) $request->user()->id),
            'Discrepancy barcode berhasil disimpan.'
        );
    }

    public function goodsReceipt(Request $request, string $deliveryOrderId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $request->validate([
            'actual_delivery_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:140'],
        ]);
        $result = $this->service->generateGoodsReceipt(
            $deliveryOrderId,
            (string) $scope['selected']->id,
            $payload,
            (string) $request->user()->id
        );

        $stockGrId = (string) data_get($result, 'goods_receipt.id', '');
        $purchasingGr = $stockGrId !== ''
            ? $this->purchasingBridge->syncFromOutletGoodsReceipt($stockGrId, $request->user())
            : null;
        $result['purchasing_goods_receipt'] = $purchasingGr;

        return ApiResponse::ok(
            $result,
            'Goods Receipt outlet selesai. Purchasing GR otomatis dibuat dan menunggu approval Finance.'
        );
    }

    public function markPrinted(Request $request, string $receivingId): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        return ApiResponse::ok($this->service->markPrinted($receivingId, (string) $scope['selected']->id, null, (string) $request->user()->id));
    }
}
