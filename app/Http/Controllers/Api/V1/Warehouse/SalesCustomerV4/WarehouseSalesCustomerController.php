<?php

namespace App\Http\Controllers\Api\V1\Warehouse\SalesCustomerV4;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\SalesCustomerV4\WarehouseSalesCustomerService;
use App\Services\Warehouse\SalesTransferV3\WarehouseLogisticsV7ExtensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarehouseSalesCustomerController extends Controller
{
    public function __construct(
        private readonly WarehouseSalesCustomerService $service,
        private readonly WarehouseLogisticsV7ExtensionService $logistics,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->warehouseId($request)));
    }

    public function pricePreview(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'customer_id' => ['required','string','max:40','exists:wh_customers,id'],
            'sku_id' => ['required','string','max:40'],
            'uom_id' => ['required','string','max:40'],
            'qty_uom' => ['required','numeric','gt:0'],
            'business_date' => ['required','date'],
        ]);
        return ApiResponse::ok($this->service->pricePreview(
            $this->warehouseId($request),
            (string) $payload['customer_id'],
            (string) $payload['sku_id'],
            (string) $payload['uom_id'],
            (float) $payload['qty_uom'],
            $payload['business_date'],
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->list($this->warehouseId($request), $filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detail($this->warehouseId($request), $id));
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->save($this->warehouseId($request), $this->payload($request), (string) $request->user()->id),
            'Sales Customer draft berhasil dibuat.'
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->save($this->warehouseId($request), $this->payload($request), (string) $request->user()->id, $id),
            'Sales Customer draft berhasil diperbarui.'
        );
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->submit($this->warehouseId($request), $id, (string) $request->user()->id),
            'Sales Customer submitted untuk approval.'
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', 'max:40', 'distinct'],
            'items.*.approved_qty_uom' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        return ApiResponse::ok(
            $this->service->approve($this->warehouseId($request), $id, $payload, (string) $request->user()->id),
            'Sales Customer approved dan masuk Checker Prepare Logistics.'
        );
    }

    public function overrideCustomerReceipt(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        $detail = $this->service->detail($warehouseId, $id);
        $grId = (string) ($detail['logistics']['goods_receipt']['id'] ?? '');
        if ($grId === '') {
            throw ValidationException::withMessages(['goods_receipt' => ['Draft Goods Receipt belum tersedia. Generate Delivery Order dari Checker Prepare terlebih dahulu.']]);
        }

        $payload = $request->validate([
            'receipt_date' => ['required', 'date'],
            'receiver_name' => ['required', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string', 'max:40', 'distinct'],
            'items.*.received_qty_uom' => ['required', 'numeric', 'min:0'],
            'items.*.not_received_qty_uom' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->logistics->receiveCustomerGoodsReceipt($warehouseId, $grId, $payload, (string) $request->user()->id);
        return ApiResponse::ok($this->service->detail($warehouseId, $id), 'Customer GR berhasil dioverride dan siap di-Complete.');
    }

    public function completeGoodsReceipt(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        $detail = $this->service->detail($warehouseId, $id);
        $grId = (string) ($detail['logistics']['goods_receipt']['id'] ?? '');
        if ($grId === '') {
            throw ValidationException::withMessages(['goods_receipt' => ['Goods Receipt belum tersedia.']]);
        }
        $payload = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $this->logistics->completeGoodsReceipt($warehouseId, $grId, (string) $request->user()->id, $payload['notes'] ?? null);
        return ApiResponse::ok($this->service->detail($warehouseId, $id), 'Goods Receipt Complete. Stock Warehouse diposting OUT dan Outgoing Invoice dibuat.');
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'string', 'max:40', 'exists:wh_customers,id'],
            'ship_to_address_id' => ['nullable', 'string', 'max:40'],
            'destination_label' => ['nullable', 'string', 'max:120'],
            'destination_recipient_name' => ['nullable', 'string', 'max:180'],
            'destination_phone' => ['nullable', 'string', 'max:80'],
            'destination_address' => ['nullable', 'string', 'max:3000'],
            'destination_city' => ['nullable', 'string', 'max:120'],
            'destination_province' => ['nullable', 'string', 'max:120'],
            'destination_postal_code' => ['nullable', 'string', 'max:30'],
            'order_date' => ['required', 'date'],
            'needed_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', 'string', 'max:40', 'distinct'],
            'items.*.uom_id' => ['required', 'string', 'max:40'],
            'items.*.requested_qty_uom' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'], // ignored by I06; server resolves strict master price
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
