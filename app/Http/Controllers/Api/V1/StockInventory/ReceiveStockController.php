<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\PurchaseOrder;
use App\Services\StockInventory\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceiveStockController extends StockInventoryBaseController
{
    public function __construct(private readonly GoodsReceiptService $service)
    {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $rows = PurchaseOrder::query()
            ->with('supplierSource:id,code,name')->withCount('items')
            ->where('outlet_id', $outletId)
            ->where('source_type', 'warehouse')
            ->whereIn('status', ['approved', 'partially_received'])
            ->latest('approved_at')
            ->limit(100)
            ->get()
            ->map(function (PurchaseOrder $po) {
                if (! $po->shipment_code) {
                    $po->forceFill(['shipment_code' => 'SHIP-'.strtoupper((string) $po->id)])->save();
                }

                return [
                    'id' => (string) $po->id,
                    'po_number' => (string) $po->po_number,
                    'shipment_code' => (string) $po->shipment_code,
                    'status' => (string) $po->status,
                    'supplier' => $po->supplierSource ? [
                        'id' => (string) $po->supplierSource->id,
                        'code' => (string) $po->supplierSource->code,
                        'name' => (string) $po->supplierSource->name,
                    ] : null,
                    'item_count' => (int) $po->items_count,
                    'total_amount' => (float) $po->total_amount,
                    'approved_at' => $po->approved_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return ApiResponse::ok(['purchase_orders' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'released', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = GoodsReceipt::query()
            ->with(['outlet:id,code,name', 'supplierSource:id,code,name,source_type', 'purchaseOrder:id,po_number,shipment_code,status'])
            ->withCount('items')
            ->where('receipt_type', GoodsReceipt::TYPE_WAREHOUSE)
            ->where('outlet_id', $outletId);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('receipt_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('receipt_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['q'])) {
            $needle = '%'.$validated['q'].'%';
            $query->where(fn ($inner) => $inner
                ->where('gr_number', 'like', $needle)
                ->orWhere('shipment_code', 'like', $needle)
                ->orWhere('supplier_document_number', 'like', $needle));
        }

        $paginator = $query->latest('receipt_date')->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (GoodsReceipt $receipt) => $this->service->summary($receipt))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $receipt = GoodsReceipt::query()
            ->where('receipt_type', GoodsReceipt::TYPE_WAREHOUSE)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        return ApiResponse::ok($this->service->serialize($receipt));
    }

    public function lookup(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'shipment_code' => ['required', 'string', 'max:80'],
        ]);

        $receipt = $this->service->findOrCreateWarehouseReceipt(
            $validated['shipment_code'],
            $outletId,
            (string) $request->user()->id
        );

        return ApiResponse::ok($this->service->serialize($receipt), 'Kode pengiriman ditemukan.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $receipt = GoodsReceipt::query()
            ->where('receipt_type', GoodsReceipt::TYPE_WAREHOUSE)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        $validated = $request->validate([
            'supplier_document_number' => ['nullable', 'string', 'max:120'],
            'receipt_date' => ['nullable', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('stk_goods_receipt_items', 'id')],
            'items.*.received_qty' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $receipt = $this->service->updateDraft($receipt, $validated, (string) $request->user()->id);
        return ApiResponse::ok($this->service->serialize($receipt), 'Penerimaan stock berhasil disimpan.');
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $receipt = GoodsReceipt::query()
            ->where('receipt_type', GoodsReceipt::TYPE_WAREHOUSE)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        $receipt = $this->service->release($receipt, (string) $request->user()->id);
        return ApiResponse::ok($this->service->serialize($receipt), 'Goods Receipt berhasil direlease dan saldo stock diperbarui.');
    }
}
