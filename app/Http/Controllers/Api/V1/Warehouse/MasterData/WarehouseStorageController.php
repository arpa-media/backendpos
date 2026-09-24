<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseStorageController extends WarehouseMasterDataBaseController
{
    private const TYPES = ['shelves', 'box', 'freezer', 'basket', 'rack'];

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'storage_type' => ['nullable', Rule::in(self::TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseStorage::query()->with('warehouse:id,code,name')->where('warehouse_id', $warehouseId);
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('position_description', 'like', "%{$term}%"));
        }
        if (! empty($filters['storage_type'])) {
            $query->where('storage_type', $filters['storage_type']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('code')->paginate((int) ($filters['per_page'] ?? 100));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (WarehouseStorage $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $this->validated($request);
        $code = strtoupper(trim($data['code']));
        $userId = $request->user()?->id;

        $row = DB::transaction(function () use ($warehouseId, $data, $code, $userId) {
            $existing = WarehouseStorage::withTrashed()
                ->where('warehouse_id', $warehouseId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($existing && ! $existing->trashed()) {
                throw ValidationException::withMessages(['code' => ['Code Storage sudah digunakan pada warehouse ini.']]);
            }

            if ($existing) {
                $existing->restore();
                $existing->fill([
                    'name' => trim($data['name']),
                    'storage_type' => $this->normalizeType($data['storage_type']),
                    'position_description' => $this->nullableText($data['position_description'] ?? null),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'updated_by_user_id' => $userId,
                ])->save();
                return $existing->fresh(['warehouse']);
            }

            return WarehouseStorage::query()->create([
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => trim($data['name']),
                'storage_type' => $this->normalizeType($data['storage_type']),
                'position_description' => $this->nullableText($data['position_description'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ])->fresh(['warehouse']);
        });

        return ApiResponse::ok($this->serialize($row), 'Storage berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseStorage::query()->whereKey($id)->where('warehouse_id', $warehouseId)->first();
        if (! $row) {
            return ApiResponse::error('Storage tidak ditemukan pada warehouse ini.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $row->id, $warehouseId);
        $row->fill([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'storage_type' => $this->normalizeType($data['storage_type']),
            'position_description' => $this->nullableText($data['position_description'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($row->fresh(['warehouse'])), 'Storage berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseStorage::query()->whereKey($id)->where('warehouse_id', $warehouseId)->first();
        if (! $row) {
            return ApiResponse::error('Storage tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $used = Schema::hasTable('wh_batches') && DB::table('wh_batches')->where('storage_id', $id)->exists();
        if ($used) {
            $row->forceFill(['is_active' => false, 'updated_by_user_id' => $request->user()?->id])->save();
            return ApiResponse::ok($this->serialize($row->fresh(['warehouse'])), 'Storage sudah dipakai batch dan dinonaktifkan.');
        }

        $row->delete();
        return ApiResponse::ok(null, 'Storage berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null, ?string $warehouseId = null): array
    {
        $warehouseId = $warehouseId ?: (string) $request->attributes->get('warehouse_scope_id', '');
        $uniqueCode = Rule::unique('wh_storages', 'code')
            ->where(fn ($query) => $query->where('warehouse_id', $warehouseId));
        if ($ignoreId === null) {
            $uniqueCode = $uniqueCode->whereNull('deleted_at');
        } else {
            $uniqueCode = $uniqueCode->ignore($ignoreId);
        }

        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:60',
                $uniqueCode,
            ],
            'name' => ['required', 'string', 'max:150'],
            'storage_type' => ['required', Rule::in([...self::TYPES, 'bascket'])],
            'position_description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizeType(string $value): string
    {
        $type = strtolower(trim($value));
        return $type === 'bascket' ? 'basket' : $type;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function serialize(WarehouseStorage $row): array
    {
        return [
            'id' => (string) $row->id,
            'warehouse_id' => (string) $row->warehouse_id,
            'warehouse_code' => $row->warehouse?->code,
            'warehouse_name' => $row->warehouse?->name,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'storage_type' => (string) $row->storage_type,
            'position_description' => $row->position_description,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
