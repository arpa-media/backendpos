<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockUomController extends StockInventoryBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockUom::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('symbol', 'like', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (StockUom $uom) => $this->serialize($uom))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;

        $uom = StockUom::query()->create([
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'symbol' => trim($data['symbol']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok($this->serialize($uom), 'Unit of Measure berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $uom = StockUom::query()->find($id);
        if (! $uom) {
            return ApiResponse::error('Unit of Measure tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $uom->id);
        $uom->fill([
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'symbol' => trim($data['symbol']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($uom->fresh()), 'Unit of Measure berhasil diperbarui.');
    }

    public function destroy(string $id): JsonResponse
    {
        $uom = StockUom::query()->find($id);
        if (! $uom) {
            return ApiResponse::error('Unit of Measure tidak ditemukan.', 'NOT_FOUND', 404);
        }

        if (StockSku::query()->where('base_uom_id', $uom->id)->exists()) {
            throw ValidationException::withMessages([
                'uom' => ['UOM masih digunakan oleh Data SKU dan tidak dapat dihapus. Nonaktifkan saja bila tidak dipakai lagi.'],
            ]);
        }

        $uom->delete();

        return ApiResponse::ok(null, 'Unit of Measure berhasil dihapus.');
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

    private function serialize(StockUom $uom): array
    {
        return [
            'id' => (string) $uom->id,
            'code' => (string) $uom->code,
            'name' => (string) $uom->name,
            'symbol' => (string) $uom->symbol,
            'decimal_places' => (int) $uom->decimal_places,
            'is_active' => (bool) $uom->is_active,
            'created_at' => $uom->created_at?->toIso8601String(),
            'updated_at' => $uom->updated_at?->toIso8601String(),
        ];
    }
}
