<?php

namespace App\Http\Controllers\Api\V1\Warehouse\PurchasingV3;

use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseProcurementBaseController;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\PurchasingV3\WarehouseStockInV13I18Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockInV13I18Controller extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehouseStockInV13I18Service $lifecycle) {}

    public function progress(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseId($request);
        if ($warehouse instanceof JsonResponse) return $warehouse;
        return ApiResponse::ok($this->lifecycle->progress($id, $warehouse));
    }

    public function partial(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseId($request);
        if ($warehouse instanceof JsonResponse) return $warehouse;
        $payload = $request->validate([
            'request_key' => ['required', 'string', 'min:12', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.stock_in_item_id' => ['required', 'string', 'distinct', Rule::exists('wh_stock_in_items', 'id')],
            'items.*.received_qty_uom' => ['required', 'numeric', 'gt:0'],
            'items.*.storage_id' => ['nullable', 'string'],
            'items.*.supplier_batch_code' => ['nullable', 'string', 'max:100'],
            'items.*.production_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::ok($this->lifecycle->partial($id, $warehouse, $payload, (string) $request->user()->id),
            'Penerimaan partial disimpan sebagai pemeriksaan Logistic. Stok belum bertambah.');
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseId($request);
        if ($warehouse instanceof JsonResponse) return $warehouse;
        $payload = $request->validate([
            'disposition' => ['sometimes', 'in:complete,shortage'],
            'reason' => ['required_if:disposition,shortage', 'nullable', 'string', 'min:15', 'max:1000'],
            'confirm' => ['required_if:disposition,shortage', 'nullable', 'in:ACKNOWLEDGE_SHORTAGE'],
        ]);
        return ApiResponse::ok($this->lifecycle->finish($id, $warehouse, (string) $request->user()->id, $payload),
            'Pengecekan Stock In selesai. Penerimaan lengkap siap untuk Complete PO; selisih memerlukan rekonsiliasi.');
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseId($request);
        if ($warehouse instanceof JsonResponse) return $warehouse;
        $payload = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        return ApiResponse::ok($this->lifecycle->reopen($id, $warehouse, (string) $request->user()->id, $payload['reason']),
            'Pengecekan dibuka kembali. Riwayat selisih tetap tersimpan.');
    }

    public function completePo(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseId($request);
        if ($warehouse instanceof JsonResponse) return $warehouse;
        $request->validate(['confirm' => ['required', 'in:COMPLETE PO AND POST STOCK']]);
        return ApiResponse::ok($this->lifecycle->completePo($id, $warehouse, (string) $request->user()->id),
            'PO Completed. Ledger, batch, Berita Acara dan draft invoice berhasil diposting sekali.');
    }
}
