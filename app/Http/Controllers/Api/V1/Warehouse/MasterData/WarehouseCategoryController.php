<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseCategoryController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockCategory::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%"));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('sort_order')->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (StockCategory $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $row = StockCategory::query()->create([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
        return ApiResponse::ok($this->serialize($row), 'Category Item berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = StockCategory::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Category Item tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $data = $this->validated($request, $row->id);
        $row->fill([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();
        return ApiResponse::ok($this->serialize($row->fresh()), 'Category Item berhasil diperbarui.');
    }

    public function destroy(string $id): JsonResponse
    {
        $row = StockCategory::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Category Item tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (StockSku::query()->where('category_id', $id)->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Category masih digunakan SKU. Nonaktifkan category bila tidak dipakai lagi.'],
            ]);
        }
        $row->delete();
        return ApiResponse::ok(null, 'Category Item berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('stk_categories', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150', Rule::unique('stk_categories', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function serialize(StockCategory $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'description' => $row->description,
            'sort_order' => (int) $row->sort_order,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
