<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\SupplierSource;
use App\Services\StockInventory\GoodsReceiptService;
use App\Services\Purchasing\StockRequestReceiptInvoiceBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualStockController extends StockInventoryBaseController
{
    public function __construct(
        private readonly GoodsReceiptService $service,
        private readonly StockRequestReceiptInvoiceBridgeService $purchasingBridge,
    ) {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        return ApiResponse::ok([
            'outlet_id' => $outletId,
            'suppliers' => SupplierSource::query()
                ->where('source_type', 'other_supplier')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn ($row) => ['id' => (string) $row->id, 'code' => $row->code, 'name' => $row->name])
                ->all(),
            'skus' => StockSku::query()
                ->with('baseUom:id,symbol,decimal_places')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku_code', 'name', 'base_uom_id'])
                ->map(fn (StockSku $sku) => [
                    'id' => (string) $sku->id,
                    'sku_code' => $sku->sku_code,
                    'name' => $sku->name,
                    'uom_symbol' => $sku->baseUom?->symbol,
                    'decimal_places' => (int) ($sku->baseUom?->decimal_places ?? 2),
                ])->all(),
        ]);
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
            ->where('receipt_type', GoodsReceipt::TYPE_MANUAL)
            ->where('outlet_id', $outletId);

        if (! empty($validated['status'])) $query->where('status', $validated['status']);
        if (! empty($validated['date_from'])) $query->whereDate('receipt_date', '>=', $validated['date_from']);
        if (! empty($validated['date_to'])) $query->whereDate('receipt_date', '<=', $validated['date_to']);
        if (! empty($validated['q'])) {
            $needle = '%'.$validated['q'].'%';
            $query->where(fn ($inner) => $inner->where('gr_number', 'like', $needle)->orWhere('supplier_document_number', 'like', $needle));
        }

        $paginator = $query->latest('receipt_date')->latest('created_at')->paginate((int) ($validated['per_page'] ?? 20));
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
            ->where('receipt_type', GoodsReceipt::TYPE_MANUAL)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        return ApiResponse::ok($this->service->serialize($receipt));
    }

    public function store(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'supplier_source_id' => ['required', 'string', Rule::exists('pur_supplier_sources', 'id')->where('source_type', 'other_supplier')->where('is_active', true)],
            'supplier_document_number' => ['nullable', 'string', 'max:120'],
            'receipt_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku_id' => ['required', 'string', 'distinct', Rule::exists('stk_skus', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'items.*.received_qty' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $receipt = $this->service->createManual($validated, $outletId, (string) $request->user()->id);
        return ApiResponse::ok($this->service->serialize($receipt), 'Draft Manual Stock berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $receipt = GoodsReceipt::query()
            ->where('receipt_type', GoodsReceipt::TYPE_MANUAL)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        $validated = $request->validate([
            'supplier_document_number' => ['nullable', 'string', 'max:120'],
            'receipt_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('stk_goods_receipt_items', 'id')],
            'items.*.received_qty' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $receipt = $this->service->updateDraft($receipt, $validated, (string) $request->user()->id);
        return ApiResponse::ok($this->service->serialize($receipt), 'Draft Manual Stock berhasil diperbarui.');
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $receipt = GoodsReceipt::query()
            ->where('receipt_type', GoodsReceipt::TYPE_MANUAL)
            ->where('outlet_id', $outletId)
            ->findOrFail($id);

        $receipt = $this->service->release($receipt, (string) $request->user()->id);
        $purchasingGr = $this->purchasingBridge->syncFromManualGoodsReceipt((string) $receipt->id, $request->user());
        $payload = $this->service->serialize($receipt);
        $payload['purchasing_goods_receipt'] = $purchasingGr;
        return ApiResponse::ok($payload, 'Manual Goods Receipt direlease dan Purchasing GR otomatis dibuat. Actual Stock tidak berubah; gunakan Stock Opname atau penerimaan DO/GR.');
    }
}
