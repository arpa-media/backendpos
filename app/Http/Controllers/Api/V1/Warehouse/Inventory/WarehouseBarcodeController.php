<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseBarcodeController extends WarehouseInventoryBaseController
{
    private const STATUSES = ['draft', 'available', 'reserved', 'in_transit', 'consumed', 'quarantine', 'damaged', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'batch_id' => ['nullable', 'ulid'],
            'sku_id' => ['nullable', 'ulid'],
            'storage_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseStockUnit::query()
            ->with(['batch:id,batch_code,supplier_batch_code,expiry_date', 'sku.brand:id,code,name', 'sku.baseUom:id,code,name,symbol', 'storage:id,code,name,storage_type'])
            ->where('warehouse_id', $warehouseId);

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('barcode', 'like', "%{$term}%")
                ->orWhereHas('batch', fn ($batch) => $batch->where('batch_code', 'like', "%{$term}%"))
                ->orWhereHas('sku', fn ($sku) => $sku->where('sku_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")));
        }
        foreach (['batch_id', 'sku_id', 'storage_id', 'status'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn ($row) => $this->serialize($row))->values(),
            'pagination' => $this->pagination($paginator),
            'summary' => WarehouseStockUnit::query()->where('warehouse_id', $warehouseId)
                ->selectRaw('COUNT(*) as total, SUM(status = ?) as draft_count, SUM(status = ?) as available_count', ['draft', 'available'])
                ->first(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'batch_id' => ['required', 'ulid'],
            'storage_id' => ['nullable', 'ulid'],
            'label_count' => ['required', 'integer', 'min:1', 'max:500'],
            'qty_per_label_base' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'activate_against_balance' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $created = DB::transaction(function () use ($request, $warehouseId, $data): array {
            $batch = WarehouseBatch::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->find($data['batch_id']);
            if (! $batch) {
                throw ValidationException::withMessages(['batch_id' => ['Batch tidak ditemukan pada warehouse aktif.']]);
            }
            if (in_array($batch->status, ['closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['batch_id' => ['Batch yang sudah closed/cancelled tidak dapat dibuatkan barcode baru.']]);
            }

            $storageId = (string) ($data['storage_id'] ?? $batch->storage_id ?? '');
            $storage = WarehouseStorage::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->find($storageId);
            if (! $storage) {
                throw ValidationException::withMessages(['storage_id' => ['Storage aktif wajib dipilih dan harus berada pada warehouse yang sama.']]);
            }

            $labelCount = (int) $data['label_count'];
            $qtyPerLabel = (float) $data['qty_per_label_base'];
            $activate = (bool) ($data['activate_against_balance'] ?? false);
            if ($activate) {
                if ($batch->status !== 'active') {
                    throw ValidationException::withMessages(['activate_against_balance' => ['Label hanya dapat diaktifkan terhadap saldo pada batch berstatus active.']]);
                }
                $balanceQty = (float) WarehouseBatchBalance::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('batch_id', $batch->id)
                    ->where('storage_id', $storage->id)
                    ->lockForUpdate()
                    ->sum('on_hand_qty');
                $mappedQty = (float) WarehouseStockUnit::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('batch_id', $batch->id)
                    ->where('storage_id', $storage->id)
                    ->whereIn('status', ['available', 'reserved', 'quarantine'])
                    ->sum('qty_base');
                $requestedQty = $labelCount * $qtyPerLabel;
                if ($mappedQty + $requestedQty > $balanceQty + 0.0001) {
                    throw ValidationException::withMessages([
                        'activate_against_balance' => [sprintf('Qty label aktif %.4f melebihi saldo batch yang belum terpetakan %.4f.', $requestedQty, max(0, $balanceQty - $mappedQty))],
                    ]);
                }
            }

            $rows = [];
            for ($index = 0; $index < $labelCount; $index++) {
                $unit = WarehouseStockUnit::query()->create([
                    'barcode' => $this->nextBarcode($warehouseId, (string) $batch->id),
                    'warehouse_id' => $warehouseId,
                    'batch_id' => $batch->id,
                    'sku_id' => $batch->sku_id,
                    'storage_id' => $storage->id,
                    'qty_base' => $qtyPerLabel,
                    'status' => $activate ? 'available' : 'draft',
                    'activated_at' => $activate ? now() : null,
                    'metadata' => ['notes' => $this->nullableText($data['notes'] ?? null), 'generated_from' => 'warehouse_inventory_barcode'],
                    'created_by_user_id' => $request->user()?->id,
                    'updated_by_user_id' => $request->user()?->id,
                ]);
                $rows[] = $unit->load(['batch', 'sku.baseUom', 'storage']);
            }

            return $rows;
        });

        return ApiResponse::ok([
            'items' => collect($created)->map(fn ($row) => $this->serialize($row))->values(),
            'ids' => collect($created)->pluck('id')->map(fn ($id) => (string) $id)->values(),
        ], 'Barcode berhasil dibuat. Pembuatan label tidak mengubah saldo inventory.', 201);
    }

    public function printData(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'ulid'],
        ]);

        $items = WarehouseStockUnit::query()
            ->with(['warehouse:id,code,name', 'batch:id,batch_code,supplier_batch_code,expiry_date', 'sku.baseUom:id,code,name,symbol', 'storage:id,code,name'])
            ->where('warehouse_id', $warehouseId)
            ->whereIn('id', $data['ids'])
            ->get();

        if ($items->count() !== count(array_unique($data['ids']))) {
            return ApiResponse::error('Sebagian barcode tidak ditemukan pada warehouse aktif.', 'BARCODE_NOT_FOUND', 404);
        }

        WarehouseStockUnit::query()->whereIn('id', $items->pluck('id'))->update([
            'print_count' => DB::raw('print_count + 1'),
            'last_printed_at' => now(),
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now(),
        ]);

        return ApiResponse::ok(['items' => $items->map(fn ($row) => $this->serialize($row))->values()]);
    }

    public function validateScan(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'context_type' => ['required', 'string', 'max:60'],
            'context_id' => ['required', 'string', 'max:100'],
            'barcode' => ['required', 'string', 'max:120'],
            'expected_sku_id' => ['nullable', 'ulid', 'exists:stk_skus,id'],
            'allowed_statuses' => ['nullable', 'array', 'max:8'],
            'allowed_statuses.*' => [Rule::in(self::STATUSES)],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? $request->header('Idempotency-Key', '')));
        if ($idempotencyKey !== '') {
            $existing = WarehouseScanEvent::query()->where('idempotency_key', $idempotencyKey)->where('result', 'accepted')->first();
            if ($existing) {
                return ApiResponse::ok(['event_id' => (string) $existing->id, 'result' => $existing->result, 'idempotent_replay' => true], 'Scan idempotent sudah pernah diproses.');
            }
        }

        try {
            $outcome = DB::transaction(function () use ($request, $warehouseId, $data, $idempotencyKey): array {
                $unit = WarehouseStockUnit::query()->with(['batch', 'sku.baseUom', 'storage'])->where('barcode', trim($data['barcode']))->lockForUpdate()->first();
                if (! $unit || (string) $unit->warehouse_id !== $warehouseId) {
                    $this->recordRejected($request, $warehouseId, $data, null, 'Barcode tidak ditemukan pada warehouse aktif.');
                    return ['accepted' => false, 'code' => 'BARCODE_NOT_FOUND', 'status' => 422, 'message' => 'Barcode tidak ditemukan pada warehouse aktif.'];
                }

                $allowed = $data['allowed_statuses'] ?? ['available', 'draft'];
                if (! in_array($unit->status, $allowed, true)) {
                    $message = "Status barcode {$unit->status} tidak diizinkan untuk proses ini.";
                    $this->recordRejected($request, $warehouseId, $data, $unit, $message);
                    return ['accepted' => false, 'code' => 'BARCODE_STATUS_REJECTED', 'status' => 422, 'message' => $message];
                }

                if (! empty($data['expected_sku_id']) && (string) $unit->sku_id !== (string) $data['expected_sku_id']) {
                    $this->recordRejected($request, $warehouseId, $data, $unit, 'SKU barcode tidak sesuai item yang ditugaskan.');
                    return ['accepted' => false, 'code' => 'WRONG_SKU', 'status' => 422, 'message' => 'Barang yang dipindai bukan item yang dimaksud.'];
                }

                $duplicate = WarehouseScanEvent::query()
                    ->where('context_type', $data['context_type'])
                    ->where('context_id', $data['context_id'])
                    ->where('stock_unit_id', $unit->id)
                    ->where('result', 'accepted')
                    ->exists();
                if ($duplicate) {
                    return ['accepted' => false, 'code' => 'DUPLICATE_SCAN', 'status' => 409, 'message' => 'Barcode yang sama sudah dipindai pada tugas/dokumen ini.'];
                }

                $event = WarehouseScanEvent::query()->create([
                    'warehouse_id' => $warehouseId,
                    'context_type' => trim($data['context_type']),
                    'context_id' => trim($data['context_id']),
                    'stock_unit_id' => $unit->id,
                    'barcode' => $unit->barcode,
                    'expected_sku_id' => $data['expected_sku_id'] ?? null,
                    'actual_sku_id' => $unit->sku_id,
                    'result' => 'accepted',
                    'message' => 'Barcode valid.',
                    'idempotency_key' => $idempotencyKey ?: null,
                    'scanned_by_user_id' => $request->user()?->id,
                    'scanned_at' => now(),
                    'metadata' => ['status_snapshot' => $unit->status, 'qty_base_snapshot' => (float) $unit->qty_base],
                ]);

                return ['accepted' => true, 'event_id' => (string) $event->id, 'result' => 'accepted', 'unit' => $this->serialize($unit)];
            });
        } catch (QueryException $error) {
            if (str_contains(strtolower($error->getMessage()), 'wh_scan_events_context_unit_uq')) {
                return ApiResponse::error('Barcode yang sama sudah dipindai pada tugas/dokumen ini.', 'DUPLICATE_SCAN', 409, ['barcode' => ['Double scan ditolak.']]);
            }
            throw $error;
        }

        if (! ($outcome['accepted'] ?? false)) {
            return ApiResponse::error($outcome['message'], $outcome['code'], (int) $outcome['status'], ['barcode' => [$outcome['message']]]);
        }

        unset($outcome['accepted']);
        return ApiResponse::ok($outcome, 'Barcode valid dan scan diterima.');
    }

    private function recordRejected(Request $request, string $warehouseId, array $data, ?WarehouseStockUnit $unit, string $message): void
    {
        WarehouseScanEvent::query()->create([
            'warehouse_id' => $warehouseId,
            'context_type' => trim($data['context_type']),
            'context_id' => trim($data['context_id']),
            'stock_unit_id' => null,
            'barcode' => trim($data['barcode']),
            'expected_sku_id' => $data['expected_sku_id'] ?? null,
            'actual_sku_id' => $unit?->sku_id,
            'result' => 'rejected',
            'message' => $message,
            'idempotency_key' => null,
            'scanned_by_user_id' => $request->user()?->id,
            'scanned_at' => now(),
            'metadata' => $unit ? ['stock_unit_id' => (string) $unit->id, 'status_snapshot' => $unit->status] : null,
        ]);
    }

    private function nextBarcode(string $warehouseId, string $batchId): string
    {
        $warehouse = strtoupper((string) (DB::table('outlets')->where('id', $warehouseId)->value('code') ?: 'WH'));
        $batch = strtoupper(substr((string) (DB::table('wh_batches')->where('id', $batchId)->value('batch_code') ?: 'BATCH'), -12));
        return sprintf('WH-%s-%s-%s', preg_replace('/[^A-Z0-9]/', '', $warehouse), preg_replace('/[^A-Z0-9]/', '', $batch), strtoupper(substr((string) Str::ulid(), -10)));
    }

    private function serialize(WarehouseStockUnit $row): array
    {
        return [
            'id' => (string) $row->id,
            'barcode' => (string) $row->barcode,
            'warehouse_id' => (string) $row->warehouse_id,
            'warehouse_code' => $row->warehouse?->code,
            'warehouse_name' => $row->warehouse?->name,
            'batch_id' => (string) $row->batch_id,
            'batch_code' => $row->batch?->batch_code,
            'supplier_batch_code' => $row->batch?->supplier_batch_code,
            'expiry_date' => $row->batch?->expiry_date?->format('Y-m-d'),
            'sku_id' => (string) $row->sku_id,
            'sku_code' => $row->sku?->sku_code,
            'item_name' => $row->sku?->name,
            'base_uom_code' => $row->sku?->baseUom?->code,
            'storage_id' => (string) $row->storage_id,
            'storage_code' => $row->storage?->code,
            'storage_name' => $row->storage?->name,
            'qty_base' => (float) $row->qty_base,
            'status' => (string) $row->status,
            'print_count' => (int) $row->print_count,
            'last_printed_at' => $row->last_printed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
