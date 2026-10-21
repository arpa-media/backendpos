<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\LogisticsV3\WarehousePartialOutletReceivingI05Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseReceivingV3Controller extends StockInventoryBaseController
{
    public function __construct(private readonly WarehouseLogisticsV3Service $service, private readonly WarehousePartialOutletReceivingI05Service $partial)
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

        $result = $this->service->outletDeliveries($outletId, $filters);
        $ids = collect($result['items'] ?? [])->pluck('id')->all();
        $counts = count($ids) ? DB::table('wh_v3_partial_receipt_batches')->whereIn('delivery_order_id',$ids)
            ->groupBy('delivery_order_id')->selectRaw('delivery_order_id, COUNT(*) total')->pluck('total','delivery_order_id') : collect();
        $result['items'] = array_map(function ($row) use ($counts) {
            $row['partial_batch_count'] = (int)$counts->get($row['id'],0);
            return $row;
        }, $result['items'] ?? []);
        return ApiResponse::ok($result);
    }

    public function show(Request $request, string $deliveryOrderId): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->partial->detail($outletId, $deliveryOrderId));
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

        // Lock the DO before deciding legacy vs I05; otherwise a concurrent partial
        // POST could slip between this check and the legacy stock posting.
        $detail = DB::transaction(function () use ($outletId, $deliveryOrderId, $payload, $request) {
            $do = DB::table('wh_v3_delivery_orders')->where('id', $deliveryOrderId)
                ->where('destination_type','outlet')->where('destination_id',$outletId)->lockForUpdate()->first();
            if (! $do) abort(404);
            if ($this->partial->hasPartialBatches($deliveryOrderId)) {
                throw ValidationException::withMessages(['items'=>['Sudah ada penerimaan bertahap untuk DO ini. Gunakan endpoint receive-partial agar stok tidak tercatat ganda.']]);
            }
            return $this->service->receiveAtOutlet($outletId, $deliveryOrderId, $payload, (string)$request->user()->id);
        }, 5);
        return ApiResponse::ok($detail, 'Penerimaan final tersimpan; Goods Receipt menunggu Complete Warehouse.');
    }

    public function receivePartial(Request $request, string $deliveryOrderId): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $payload = $request->validate([
            'idempotency_key' => ['required','string','min:8','max:120'],
            'receipt_date' => ['required','date_format:Y-m-d'],
            'close_shortage' => ['sometimes','boolean'],
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1','max:500'],
            'items.*.item_id' => ['required','string','max:40','distinct'],
            'items.*.received_qty_uom' => ['required','numeric','min:0'],
        ]);
        return ApiResponse::ok($this->partial->receive($outletId,$deliveryOrderId,$payload,(string)$request->user()->id),
            'Penerimaan tahap ini tersimpan sesuai Qty aktual, sisa tetap outstanding.');
    }

}
