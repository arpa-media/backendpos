<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseBatchController extends WarehouseInventoryBaseController
{
    private const SOURCE_TYPES = ['supplier_purchase', 'return', 'production', 'transfer_in', 'manual', 'legacy_opening'];
    private const STATUSES = ['draft', 'active', 'quarantine', 'closed', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'sku_id' => ['nullable', 'ulid'],
            'storage_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'expiry_before' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseBatch::query()
            ->with(['sku.brand:id,code,name', 'sku.baseUom:id,code,name,symbol', 'storage:id,code,name,storage_type'])
            ->withSum('balances as on_hand_qty', 'on_hand_qty')
            ->withSum('balances as reserved_qty', 'reserved_qty')
            ->withCount('stockUnits as label_count')
            ->where('warehouse_id', $warehouseId);

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('batch_code', 'like', "%{$term}%")
                ->orWhere('supplier_batch_code', 'like', "%{$term}%")
                ->orWhereHas('sku', fn ($sku) => $sku->where('sku_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")));
        }
        foreach (['sku_id', 'storage_id', 'status'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['expiry_before'])) {
            $query->whereDate('expiry_date', '<=', $filters['expiry_before']);
        }

        $paginator = $query->orderByRaw('expiry_date IS NULL, expiry_date')->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn ($row) => $this->serialize($row))->values(),
            'pagination' => $this->pagination($paginator),
            'summary' => [
                'total_batch' => (int) WarehouseBatch::query()->where('warehouse_id', $warehouseId)->count(),
                'active_batch' => (int) WarehouseBatch::query()->where('warehouse_id', $warehouseId)->where('status', 'active')->count(),
                'expiring_30_days' => (int) WarehouseBatch::query()->where('warehouse_id', $warehouseId)->whereBetween('expiry_date', [today(), today()->addDays(30)])->count(),
                'draft_labels' => (int) WarehouseStockUnit::query()->where('warehouse_id', $warehouseId)->where('status', 'draft')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $this->validated($request, $warehouseId);
        $this->validatePriceBands($data);
        $batchCode = $this->nullableText($data['batch_code'] ?? null) ?: $this->nextBatchCode($warehouseId, (string) $data['sku_id']);

        $row = WarehouseBatch::query()->create([
            'warehouse_id' => $warehouseId,
            'sku_id' => $data['sku_id'],
            'storage_id' => $this->resolveStorageId($warehouseId, $data['storage_id'] ?? null, $request->user()?->id),
            'batch_code' => strtoupper($batchCode),
            'supplier_batch_code' => $this->nullableText($data['supplier_batch_code'] ?? null),
            'source_type' => $data['source_type'],
            'received_at' => $data['received_at'] ?? null,
            'production_date' => $data['production_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'quantity_received_base' => 0,
            'actual_unit_cost' => (float) ($data['actual_unit_cost'] ?? 0),
            'price_min' => (float) ($data['price_min'] ?? $data['actual_unit_cost'] ?? 0),
            'price_avg' => (float) ($data['price_avg'] ?? $data['actual_unit_cost'] ?? 0),
            'price_max' => (float) ($data['price_max'] ?? $data['actual_unit_cost'] ?? 0),
            'status' => $data['status'] ?? 'draft',
            'notes' => $this->nullableText($data['notes'] ?? null),
            'metadata' => ['inventory_posted' => false, 'registered_from' => 'warehouse_inventory_catalog'],
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return ApiResponse::ok($this->serialize($row->fresh(['sku.brand', 'sku.baseUom', 'storage'])), 'Batch berhasil diregistrasikan. Qty stok belum berubah sampai workflow stock-in/ledger.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseBatch::query()->where('warehouse_id', $warehouseId)->find($id);
        if (! $row) {
            return ApiResponse::error('Batch tidak ditemukan pada warehouse ini.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $warehouseId, $id, $row);
        $this->validatePriceBands($data);
        $data['storage_id'] = $this->resolveStorageId($warehouseId, $data['storage_id'] ?? null, $request->user()?->id);
        $this->guardStorageChange($row, (string) $data['storage_id']);
        $this->guardPostedBatchChanges($row, $data);

        $row->fill([
            'storage_id' => $this->resolveStorageId($warehouseId, $data['storage_id'] ?? null, $request->user()?->id),
            'batch_code' => strtoupper(trim($data['batch_code'])),
            'supplier_batch_code' => $this->nullableText($data['supplier_batch_code'] ?? null),
            'source_type' => $row->source_type === 'legacy_opening' ? 'legacy_opening' : $data['source_type'],
            'received_at' => $data['received_at'] ?? null,
            'production_date' => $data['production_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'actual_unit_cost' => (float) ($data['actual_unit_cost'] ?? 0),
            'price_min' => (float) ($data['price_min'] ?? 0),
            'price_avg' => (float) ($data['price_avg'] ?? 0),
            'price_max' => (float) ($data['price_max'] ?? 0),
            'status' => $data['status'] ?? 'draft',
            'notes' => $this->nullableText($data['notes'] ?? null),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($row->fresh(['sku.brand', 'sku.baseUom', 'storage'])), 'Batch berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseBatch::query()->where('warehouse_id', $warehouseId)->find($id);
        if (! $row) {
            return ApiResponse::error('Batch tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $hasBalance = WarehouseBatchBalance::query()->where('batch_id', $id)->whereRaw('ABS(on_hand_qty) > 0.0001 OR ABS(reserved_qty) > 0.0001 OR ABS(quarantine_qty) > 0.0001')->exists();
        $hasLabels = WarehouseStockUnit::query()->where('batch_id', $id)->exists();
        if ($hasBalance || $hasLabels || $row->source_type === 'legacy_opening') {
            $row->forceFill(['status' => 'closed', 'updated_by_user_id' => $request->user()?->id])->save();
            return ApiResponse::ok(null, 'Batch memiliki saldo/label/histori dan ditutup, bukan dihapus.');
        }

        $row->delete();
        return ApiResponse::ok(null, 'Batch berhasil dihapus.');
    }

    private function validated(Request $request, string $warehouseId, ?string $ignoreId = null, ?WarehouseBatch $row = null): array
    {
        $batchUnique = Rule::unique('wh_batches', 'batch_code');
        if ($ignoreId) {
            $batchUnique = $batchUnique->ignore($ignoreId);
        }

        $sourceTypes = $row ? self::SOURCE_TYPES : array_values(array_diff(self::SOURCE_TYPES, ['legacy_opening']));

        return $request->validate([
            'sku_id' => [$row ? 'sometimes' : 'required', 'ulid', 'exists:stk_skus,id'],
            'storage_id' => [
                'nullable', 'ulid',
                Rule::exists('wh_storages', 'id')->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->whereNull('deleted_at')),
            ],
            'batch_code' => [$row ? 'required' : 'nullable', 'string', 'max:100', $batchUnique],
            'supplier_batch_code' => ['nullable', 'string', 'max:100'],
            'source_type' => ['required', Rule::in($sourceTypes)],
            'received_at' => ['nullable', 'date'],
            'production_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:production_date'],
            'actual_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_avg' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function guardStorageChange(WarehouseBatch $row, string $newStorageId): void
    {
        if ((string) ($row->storage_id ?? '') === $newStorageId) {
            return;
        }

        $hasBalance = WarehouseBatchBalance::query()
            ->where('batch_id', $row->id)
            ->whereRaw('ABS(on_hand_qty) > 0.0001 OR ABS(reserved_qty) > 0.0001 OR ABS(quarantine_qty) > 0.0001')
            ->exists();
        if ($hasBalance) {
            throw ValidationException::withMessages([
                'storage_id' => ['Default Storage tidak dapat diubah saat batch masih memiliki saldo. Gunakan workflow storage movement.'],
            ]);
        }
    }

    private function guardPostedBatchChanges(WarehouseBatch $row, array $data): void
    {
        $hasBalance = WarehouseBatchBalance::query()
            ->where('batch_id', $row->id)
            ->whereRaw('ABS(on_hand_qty) > 0.0001 OR ABS(reserved_qty) > 0.0001 OR ABS(quarantine_qty) > 0.0001')
            ->exists();
        if (! $hasBalance) {
            return;
        }

        $costChanged = abs((float) $row->actual_unit_cost - (float) ($data['actual_unit_cost'] ?? 0)) > 0.000001;
        $averageChanged = abs((float) $row->price_avg - (float) ($data['price_avg'] ?? 0)) > 0.000001;
        if ($costChanged || $averageChanged) {
            throw ValidationException::withMessages([
                'actual_unit_cost' => ['Actual Unit Cost dan Price Avg batch tidak dapat diedit setelah batch memiliki saldo. Koreksi harus melalui reversal/ledger adjustment.'],
            ]);
        }

        if (in_array((string) ($data['status'] ?? $row->status), ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Batch yang masih memiliki saldo tidak dapat diubah menjadi draft atau cancelled.'],
            ]);
        }
    }

    private function resolveStorageId(string $warehouseId, ?string $storageId, ?string $userId): string
    {
        $storageId = trim((string) $storageId);
        if ($storageId !== '') {
            return $storageId;
        }

        $existing = DB::table('wh_storages')
            ->where('warehouse_id', $warehouseId)
            ->where('code', 'UNCATEGORIZED')
            ->whereNull('deleted_at')
            ->value('id');
        if ($existing) return (string) $existing;

        $id = (string) Str::ulid();
        DB::table('wh_storages')->insert([
            'id' => $id, 'warehouse_id' => $warehouseId, 'code' => 'UNCATEGORIZED',
            'name' => 'Uncategorized', 'storage_type' => 'rack',
            'position_description' => 'Default storage Warehouse v3 ketika storage tidak dipilih.',
            'is_active' => true, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function validatePriceBands(array $data): void
    {
        $actual = (float) ($data['actual_unit_cost'] ?? 0);
        $min = (float) ($data['price_min'] ?? $actual);
        $avg = (float) ($data['price_avg'] ?? $actual);
        $max = (float) ($data['price_max'] ?? $actual);
        if ($min > $avg || $avg > $max) {
            throw ValidationException::withMessages(['price_avg' => ['Urutan harga wajib Price Min ≤ Price Avg ≤ Price Max.']]);
        }
    }

    private function nextBatchCode(string $warehouseId, string $skuId): string
    {
        $warehouseCode = DB::table('outlets')->where('id', $warehouseId)->value('code') ?: 'WH';
        $skuCode = DB::table('stk_skus')->where('id', $skuId)->value('sku_code') ?: 'SKU';
        return sprintf('B-%s-%s-%s-%s', strtoupper(Str::slug($warehouseCode, '')), strtoupper(substr(Str::slug($skuCode, ''), 0, 14)), now()->format('ymd'), strtoupper(substr((string) Str::ulid(), -6)));
    }

    private function serialize(WarehouseBatch $row): array
    {
        return [
            'id' => (string) $row->id,
            'warehouse_id' => (string) $row->warehouse_id,
            'sku_id' => (string) $row->sku_id,
            'sku_code' => $row->sku?->sku_code,
            'item_name' => $row->sku?->name,
            'brand_name' => $row->sku?->brand?->name,
            'base_uom_code' => $row->sku?->baseUom?->code,
            'storage_id' => (string) ($row->storage_id ?? ''),
            'storage_code' => $row->storage?->code,
            'storage_name' => $row->storage?->name,
            'batch_code' => (string) $row->batch_code,
            'supplier_batch_code' => $row->supplier_batch_code,
            'source_type' => (string) $row->source_type,
            'received_at' => $row->received_at?->toIso8601String(),
            'production_date' => $row->production_date?->format('Y-m-d'),
            'expiry_date' => $row->expiry_date?->format('Y-m-d'),
            'quantity_received_base' => (float) $row->quantity_received_base,
            'on_hand_qty' => (float) ($row->getAttribute('on_hand_qty') ?? $row->balances()->sum('on_hand_qty')),
            'reserved_qty' => (float) ($row->getAttribute('reserved_qty') ?? $row->balances()->sum('reserved_qty')),
            'actual_unit_cost' => (float) $row->actual_unit_cost,
            'price_min' => (float) $row->price_min,
            'price_avg' => (float) $row->price_avg,
            'price_max' => (float) $row->price_max,
            'label_count' => (int) ($row->getAttribute('label_count') ?? $row->stockUnits()->count()),
            'status' => (string) $row->status,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
