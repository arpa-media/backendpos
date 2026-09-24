<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseReceivingV3Controller extends StockInventoryBaseController
{
    public function __construct(private readonly WarehouseLogisticsV3Service $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $filters = $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        return ApiResponse::ok($this->service->outletDeliveries($outletId, $filters));
    }

    public function show(Request $request, string $deliveryOrderId): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->service->outletDeliveryDetail($outletId, $deliveryOrderId));
    }

    public function receive(Request $request, string $deliveryOrderId): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $payload = $request->validate([
            'receipt_date' => ['nullable','date'],
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40','distinct'],
            'items.*.received_qty_uom' => ['required','numeric','min:0'],
            'items.*.not_received_qty_uom' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);

        return ApiResponse::ok(
            $this->service->receiveAtOutlet(
                $outletId,
                $deliveryOrderId,
                $payload,
                (string) $request->user()->id,
            ),
            'Penerimaan tersimpan tanpa scan barcode. Goods Receipt menunggu Complete dari Warehouse Logistics.'
        );
    }
}
