<?php

namespace App\Http\Controllers\Api\V1\Warehouse\PurchasingV3;

use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseProcurementBaseController;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehousePurchaseInvoice;
use App\Models\Warehouse\WarehouseSupplierPurchaseOrder;
use App\Services\Warehouse\PurchasingV3\WarehousePurchasingV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehousePurchaseOrderV3Controller extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehousePurchasingV3Service $service) {}

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        return ApiResponse::ok($this->service->listOrders($warehouseId, $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showOrder($id, $warehouseId));
    }

    public function prepareStockIn(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('wh_supplier_purchase_order_items', 'id')],
            'items.*.actual_qty_uom' => ['nullable', 'numeric', 'min:0'],
            'items.*.actual_purchase_price' => ['nullable', 'numeric', 'min:0'],
            // Compatibility untuk client sebelum Adjustment Stage 01.
            'items.*.actual_qty_base' => ['nullable', 'numeric', 'min:0'],
            'items.*.actual_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::ok(
            $this->service->prepareStockIn($id, $warehouseId, $payload, (string) $request->user()->id),
            'Actual Qty dan Actual Price tersimpan. Draft Stock In siap diproses.'
        );
    }

    public function completeStockIn(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.stock_in_item_id' => ['required', 'string', 'distinct', Rule::exists('wh_stock_in_items', 'id')],
            // ERP-V5 Iteration 10: Complete Stock In memakai Purchase UOM.
            // received_qty_base dipertahankan hanya sebagai backward-compatible fallback untuk client lama.
            'items.*.received_qty_uom' => ['nullable', 'numeric', 'min:0'],
            'items.*.received_qty_base' => ['nullable', 'numeric', 'min:0'],
            'items.*.storage_id' => ['nullable', 'string', Rule::exists('wh_storages', 'id')->where('is_active', true)],
            'items.*.supplier_batch_code' => ['nullable', 'string', 'max:100'],
            'items.*.production_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return ApiResponse::ok(
            $this->service->completeStockIn($id, $warehouseId, $payload, (string) $request->user()->id),
            'Stock In selesai. Qty Purchase UOM dikonversi ke Base UOM untuk ledger; stok, batch, Berita Acara, dan draft Incoming Invoice sudah dibuat.'
        );
    }

    public function uploadInvoice(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $request->validate(['invoice' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']]);

        $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        if ((int) ($order->flow_version ?? 2) !== 3) {
            return ApiResponse::error('PO legacy bersifat read-only pada Purchasing Warehouse v3.', 'WAREHOUSE_V3_LEGACY_READ_ONLY', 422);
        }
        if ($order->status === 'completed') {
            return ApiResponse::error('PO sudah complete dan invoice pembelian tidak dapat diubah.', 'WAREHOUSE_V3_PO_COMPLETED', 422);
        }

        $file = $request->file('invoice');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $name = (string) Str::ulid().'.'.$extension;
        $path = 'warehouse/purchase-invoices/'.$order->id.'/'.$name;
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! Storage::disk('local')->put($path, $contents)) {
            return ApiResponse::error('Invoice gagal disimpan.', 'INVOICE_STORAGE_FAILED', 500);
        }

        $invoice = WarehousePurchaseInvoice::query()->create([
            'purchase_order_id' => $order->id,
            'original_name' => $file->getClientOriginalName(),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sha256' => hash('sha256', $contents),
            'uploaded_by_user_id' => (string) $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return ApiResponse::ok([
            'id' => (string) $invoice->id,
            'original_name' => (string) $invoice->original_name,
            'file_size' => (int) $invoice->file_size,
            'uploaded_at' => $invoice->uploaded_at?->toIso8601String(),
        ], 'Invoice private berhasil diupload.', 201);
    }

    public function downloadInvoice(Request $request, string $id, string $invoiceId): StreamedResponse|JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        $invoice = WarehousePurchaseInvoice::query()->where('purchase_order_id', $order->id)->findOrFail($invoiceId);
        if (! Storage::disk($invoice->storage_disk)->exists($invoice->storage_path)) {
            return ApiResponse::error('File invoice tidak ditemukan.', 'INVOICE_NOT_FOUND', 404);
        }
        return Storage::disk($invoice->storage_disk)->download(
            $invoice->storage_path,
            $invoice->original_name,
            ['Content-Type' => $invoice->mime_type ?: 'application/octet-stream']
        );
    }
}
