<?php

namespace App\Http\Controllers\Api\V1\Warehouse\LogisticsV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\SalesTransferV3\WarehouseLogisticsV7ExtensionService;
use Illuminate\Support\Facades\DB;
use App\Services\Warehouse\Integration\WarehouseV3CompletionIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseLogisticsV3Controller extends Controller
{
    public function __construct(
        private readonly WarehouseLogisticsV3Service $service,
        private readonly WarehouseV3CompletionIntegrationService $integration,
        private readonly WarehouseLogisticsV7ExtensionService $transferFlow,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->warehouseId($request)));
    }

    public function checkerPrepare(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'source_type' => ['nullable','string','max:40'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        return ApiResponse::ok($this->service->listPrepare($this->warehouseId($request), $filters));
    }

    public function checkerPrepareDetail(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->prepareDetail($this->warehouseId($request), $id));
    }

    public function generateDeliveryOrder(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'estimated_delivery_date' => ['required','date'],
            'estimated_delivery_time' => ['required','date_format:H:i'],
            'sender_user_id' => ['required','string','max:40','exists:users,id'],
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40','distinct'],
            'items.*.sent_qty_uom' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);

        $warehouseId = $this->warehouseId($request);
        $sourceType = (string) DB::table('wh_v3_logistics_prepare_requests')->where('id', $id)->value('source_type');
        $result = $sourceType === 'transfer_stock'
            ? $this->transferFlow->generateDeliveryOrder($warehouseId, $id, $payload, (string) $request->user()->id)
            : $this->service->generateDeliveryOrder($warehouseId, $id, $payload, (string) $request->user()->id);

        return ApiResponse::ok(
            $result,
            $sourceType === 'transfer_stock'
                ? 'Delivery Order Transfer berhasil didispatch. Stock origin sudah diposting transfer_out.'
                : 'Delivery Order berhasil digenerate. Tidak ada barcode/scan pada flow Logistics v3.'
        );
    }

    public function deliveryOrders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        return ApiResponse::ok($this->service->listDeliveryOrders($this->warehouseId($request), $filters));
    }

    public function deliveryOrder(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->deliveryOrderDetail($this->warehouseId($request), $id));
    }

    public function goodsReceipts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        return ApiResponse::ok($this->service->listGoodsReceipts($this->warehouseId($request), $filters));
    }

    public function goodsReceipt(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->goodsReceiptDetail($this->warehouseId($request), $id));
    }

    public function completeGoodsReceipt(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'notes' => ['nullable','string','max:1000'],
        ]);

        $userId = (string) $request->user()->id;
        $warehouseId = $this->warehouseId($request);
        $sourceType = (string) DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->where('g.id', $id)
            ->value('d.source_type');

        if ($sourceType === 'transfer_stock') {
            return ApiResponse::ok(
                $this->transferFlow->completeGoodsReceipt($warehouseId, $id, $userId, $payload['notes'] ?? null),
                'Transfer Stock hanya diselesaikan melalui Receiving Stock Warehouse tujuan.'
            );
        }

        $result = $this->service->completeGoodsReceipt($warehouseId, $id, $userId, $payload['notes'] ?? null);
        $result['downstream_integration'] = $this->integration->afterCompletedReceipt($id, $userId);

        return ApiResponse::ok($result, 'Goods Receipt complete. Purchasing GR dan COGS bridge diproses idempotent.');
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
