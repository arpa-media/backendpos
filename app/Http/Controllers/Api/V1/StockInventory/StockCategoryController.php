<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockCategoryController extends StockInventoryBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockCategory::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('sort_order')->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (StockCategory $category) => $this->serialize($category))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $category = StockCategory::query()->create([
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok($this->serialize($category), 'Stock Category berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = StockCategory::query()->find($id);
        if (! $category) {
            return ApiResponse::error('Stock Category tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $category->id);
        $category->fill([
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($category->fresh()), 'Stock Category berhasil diperbarui.');
    }

    public function destroy(string $id): JsonResponse
    {
        $category = StockCategory::query()->find($id);
        if (! $category) {
            return ApiResponse::error('Stock Category tidak ditemukan.', 'NOT_FOUND', 404);
        }

        if (StockSku::query()->where('category_id', $category->id)->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Category masih digunakan oleh Data SKU dan tidak dapat dihapus. Nonaktifkan saja bila tidak dipakai lagi.'],
            ]);
        }

        $category->delete();

        return ApiResponse::ok(null, 'Stock Category berhasil dihapus.');
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

    private function serialize(StockCategory $category): array
    {
        return [
            'id' => (string) $category->id,
            'code' => (string) $category->code,
            'name' => (string) $category->name,
            'description' => $category->description,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}
