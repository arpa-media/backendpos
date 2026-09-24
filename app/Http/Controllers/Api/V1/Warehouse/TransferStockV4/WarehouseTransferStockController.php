<?php

namespace App\Http\Controllers\Api\V1\Warehouse\TransferStockV4;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\TransferStockV4\WarehouseTransferStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseTransferStockController extends Controller
{
    public function __construct(private readonly WarehouseTransferStockService $service) {}

    public function options(Request $request): JsonResponse { return ApiResponse::ok($this->service->options($this->warehouseId($request))); }
    public function index(Request $request): JsonResponse { return ApiResponse::ok($this->service->list($this->warehouseId($request), $this->filters($request))); }
    public function show(Request $request, string $id): JsonResponse { return ApiResponse::ok($this->service->detail($this->warehouseId($request), $id)); }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->save($this->warehouseId($request), $this->payload($request), (string) $request->user()->id), 'Transfer Stock draft berhasil dibuat.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->save($this->warehouseId($request), $this->payload($request), (string) $request->user()->id, $id), 'Transfer Stock draft berhasil diperbarui.');
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->submit($this->warehouseId($request), $id, (string) $request->user()->id), 'Transfer Stock submitted untuk approval.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40','distinct'],
            'items.*.approved_qty_uom' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);
        return ApiResponse::ok($this->service->approve($this->warehouseId($request), $id, $payload, (string) $request->user()->id), 'Transfer Stock approved dan masuk Checker Prepare.');
    }

    public function receivingIndex(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->receivingList($this->warehouseId($request), $this->filters($request)));
    }

    public function receivingShow(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->receivingDetail($this->warehouseId($request), $id));
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'receipt_date' => ['required','date'],
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.item_id' => ['required','string','max:40','distinct'],
            'items.*.received_qty_uom' => ['required','numeric','min:0'],
            'items.*.not_received_qty_uom' => ['required','numeric','min:0'],
            'items.*.destination_storage_id' => ['nullable','string','max:40'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);
        return ApiResponse::ok($this->service->receive($this->warehouseId($request), $id, $payload, (string) $request->user()->id), 'Transfer Stock diterima dan selesai. Stock tujuan sudah transfer_in.');
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'destination_warehouse_id' => ['required','string','max:40','exists:outlets,id'],
            'transfer_date' => ['required','date'],
            'needed_date' => ['nullable','date','after_or_equal:transfer_date'],
            'notes' => ['nullable','string','max:1000'],
            'items' => ['required','array','min:1'],
            'items.*.sku_id' => ['required','string','max:40','distinct','exists:stk_skus,id'],
            'items.*.uom_id' => ['required','string','max:40','exists:stk_uoms,id'],
            'items.*.requested_qty_uom' => ['required','numeric','gt:0'],
            'items.*.notes' => ['nullable','string','max:500'],
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable','string','max:180'],
            'status' => ['nullable','string','max:30'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
