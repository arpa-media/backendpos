<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseBrand;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseBrandController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseBrand::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%"));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (WarehouseBrand $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $brand = WarehouseBrand::query()->create([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
        return ApiResponse::ok($this->serialize($brand), 'Brand berhasil dibuat.', 201);
    }

    public function resolveOther(Request $request): JsonResponse
    {
        $data = $request->validate([
            'other_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $name = trim($data['other_name']);
        $userId = $request->user()?->id;

        try {
            $brand = DB::transaction(function () use ($name, $data, $userId) {
                $existing = WarehouseBrand::withTrashed()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->forceFill([
                        'is_active' => true,
                        'updated_by_user_id' => $userId,
                    ])->save();
                    return $existing->fresh();
                }

                return WarehouseBrand::query()->create([
                    'code' => $this->uniqueCode($name),
                    'name' => $name,
                    'description' => $this->nullableText($data['description'] ?? null),
                    'is_active' => true,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);
            });
        } catch (QueryException) {
            $brand = WarehouseBrand::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if (! $brand) {
                throw ValidationException::withMessages(['other_name' => ['Brand gagal dibuat karena konflik data. Coba ulangi.']]);
            }
            if ($brand->trashed()) {
                $brand->restore();
            }
            $brand->forceFill(['is_active' => true, 'updated_by_user_id' => $userId])->save();
            $brand = $brand->fresh();
        }

        return ApiResponse::ok($this->serialize($brand), 'Brand lainnya berhasil didaftarkan.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $brand = WarehouseBrand::query()->find($id);
        if (! $brand) {
            return ApiResponse::error('Brand tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ($this->isSystem($brand)) {
            throw ValidationException::withMessages(['brand' => ['Brand No Brand tidak dapat diubah.']]);
        }

        $data = $this->validated($request, $brand->id);
        $brand->fill([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($brand->fresh()), 'Brand berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $brand = WarehouseBrand::query()->find($id);
        if (! $brand) {
            return ApiResponse::error('Brand tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ($this->isSystem($brand)) {
            throw ValidationException::withMessages(['brand' => ['Brand No Brand tidak dapat dihapus.']]);
        }

        $used = Schema::hasTable('stk_skus')
            && Schema::hasColumn('stk_skus', 'brand_id')
            && DB::table('stk_skus')->where('brand_id', $id)->exists();
        if ($used) {
            $brand->forceFill(['is_active' => false, 'updated_by_user_id' => $request->user()?->id])->save();
            return ApiResponse::ok($this->serialize($brand->fresh()), 'Brand sudah dipakai SKU dan dinonaktifkan.');
        }

        $brand->delete();
        return ApiResponse::ok(null, 'Brand berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('wh_brands', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150', Rule::unique('wh_brands', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function uniqueCode(string $name): string
    {
        $base = strtoupper(Str::slug($name, '-')) ?: 'BRAND';
        $base = substr($base, 0, 50);
        $candidate = $base;
        $counter = 2;
        while (WarehouseBrand::withTrashed()->where('code', $candidate)->exists()) {
            $suffix = '-'.$counter++;
            $candidate = substr($base, 0, 60 - strlen($suffix)).$suffix;
        }
        return $candidate;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function isSystem(WarehouseBrand $row): bool
    {
        return strtoupper((string) $row->code) === 'NO-BRAND';
    }

    private function serialize(WarehouseBrand $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'description' => $row->description,
            'is_active' => (bool) $row->is_active,
            'is_system' => $this->isSystem($row),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
