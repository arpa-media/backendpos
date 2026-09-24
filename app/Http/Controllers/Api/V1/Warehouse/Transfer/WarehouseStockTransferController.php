<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Transfer;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseStockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockTransferController extends WarehouseTransferBaseController
{
    public function __construct(private readonly WarehouseStockTransferService $service)
    {
    }

    public function options(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['destination_warehouse_id' => ['nullable', 'string', Rule::exists('outlets', 'id')]]);
        return ApiResponse::ok($this->service->options($warehouseId, $payload['destination_warehouse_id'] ?? null));
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['outgoing', 'incoming'])],
            'status' => ['nullable', Rule::in(['draft', 'prepare', 'ready', 'on_delivery', 'receiving', 'received', 'discrepancy', 'completed', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->listTransfers($warehouseId, $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->saveTransfer(null, $warehouseId, $this->planPayload($request), (string) $request->user()->id), 'Draft Transfer Stock berhasil dibuat.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showTransfer($id, $warehouseId));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->saveTransfer($id, $warehouseId, $this->planPayload($request, true), (string) $request->user()->id), 'Draft Transfer Stock berhasil diperbarui.');
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->submitTransfer($id, $warehouseId, (string) $request->user()->id), 'Transfer Stock masuk tahap prepare.');
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:500'],
            'assignments.*.item_id' => ['required', 'string', 'distinct', Rule::exists('wh_stock_transfer_items', 'id')],
            'assignments.*.checker_user_id' => ['required', 'string', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
        return ApiResponse::ok($this->service->assignCheckers($id, $warehouseId, $payload, (string) $request->user()->id), 'Checker Transfer berhasil diassign.');
    }

    public function dispatch(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'sender_user_id' => ['required', 'string', Rule::exists('users', 'id')->where('is_active', true)],
            'estimated_delivery_date' => ['required', 'date_format:Y-m-d'],
            'estimated_delivery_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:3000'], 'idempotency_key' => ['nullable', 'string', 'max:160'],
        ]);
        return ApiResponse::ok($this->service->dispatch($id, $warehouseId, $payload, (string) $request->user()->id), 'Transfer Delivery Order dibuat dan stock diposting keluar.');
    }

    public function startReceiving(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('wh_stock_transfer_items', 'id')],
            'items.*.destination_storage_id' => ['required', 'string', Rule::exists('wh_storages', 'id')->where('is_active', true)],
        ]);
        return ApiResponse::ok($this->service->startReceiving($id, $warehouseId, $payload, (string) $request->user()->id), 'Receiving Transfer Stock dimulai.');
    }

    public function scanReceiving(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['barcode' => ['required', 'string', 'max:120'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return ApiResponse::ok($this->service->scanReceiving($id, $warehouseId, $payload, (string) $request->user()->id), 'Barcode Transfer Stock diterima.');
    }

    public function resolveUnit(Request $request, string $id, string $unitId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['disposition' => ['required', Rule::in(['return', 'not_received'])], 'reason' => ['required', 'string', 'max:2000']]);
        return ApiResponse::ok($this->service->resolveReceivingUnit($id, $unitId, $warehouseId, (string) $payload['disposition'], (string) $payload['reason'], (string) $request->user()->id), 'Disposition barcode berhasil disimpan.');
    }

    public function completeReceiving(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:3000']]);
        return ApiResponse::ok($this->service->completeReceiving($id, $warehouseId, $payload, (string) $request->user()->id), 'Receiving Transfer Stock berhasil diposting.');
    }

    public function confirmReturn(Request $request, string $id, string $unitId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        return ApiResponse::ok($this->service->confirmReturn($id, $unitId, $warehouseId, (string) ($payload['notes'] ?? ''), (string) $request->user()->id), 'Barang retur diterima kembali di Warehouse asal.');
    }

    public function closeMissing(Request $request, string $id, string $unitId): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        return ApiResponse::ok($this->service->closeMissing($id, $unitId, $warehouseId, (string) $payload['notes'], (string) $request->user()->id), 'Investigasi not received ditutup.');
    }

    public function markPrinted(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate(['mode' => ['required', Rule::in(['dispatch', 'receipt'])]]);
        return ApiResponse::ok($this->service->markPrinted($id, $warehouseId, (string) $payload['mode'], (string) $request->user()->id), 'Print Transfer Stock tercatat.');
    }

    private function planPayload(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'destination_warehouse_id' => ['required', 'string', Rule::exists('outlets', 'id')->where('is_active', true)],
            'transfer_date' => ['required', 'date_format:Y-m-d'], 'needed_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:transfer_date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lock_version' => $updating ? ['required', 'integer', 'min:1'] : ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku_id' => ['required', 'string', 'distinct', Rule::exists('stk_skus', 'id')->where('is_active', true)],
            'items.*.request_uom_id' => ['required', 'string', Rule::exists('stk_uoms', 'id')],
            'items.*.requested_qty_uom' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
