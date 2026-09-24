<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseUomController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockUom::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('symbol', 'like', "%{$term}%"));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (StockUom $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $row = StockUom::query()->create([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'symbol' => trim($data['symbol']),
            'decimal_places' => (int) $data['decimal_places'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
        return ApiResponse::ok($this->serialize($row), 'Unit of Measurement berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = StockUom::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Unit of Measurement tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $data = $this->validated($request, $row->id);
        $row->fill([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'symbol' => trim($data['symbol']),
            'decimal_places' => (int) $data['decimal_places'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();
        return ApiResponse::ok($this->serialize($row->fresh()), 'Unit of Measurement berhasil diperbarui.');
    }

    public function destroy(string $id): JsonResponse
    {
        $row = StockUom::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Unit of Measurement tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (StockSku::query()->where('base_uom_id', $id)->exists()) {
            throw ValidationException::withMessages([
                'uom' => ['UOM masih digunakan SKU. Nonaktifkan UOM bila tidak dipakai lagi.'],
            ]);
        }
        $row->delete();
        return ApiResponse::ok(null, 'Unit of Measurement berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('stk_uoms', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:30'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function serialize(StockUom $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'symbol' => (string) $row->symbol,
            'decimal_places' => (int) $row->decimal_places,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
