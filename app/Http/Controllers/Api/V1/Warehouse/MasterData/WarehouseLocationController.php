<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Models\Warehouse\WarehouseChainSupply;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseLocationController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $allowedIds = $this->allowedWarehouseIds($request);
        $query = Outlet::query()->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'");
        if (! $this->isAdministrator($request)) {
            $query->whereIn('id', $allowedIds ?: ['__none__']);
        }
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (Outlet $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isAdministrator($request)) {
            return ApiResponse::error('Hanya Administrator yang dapat menambah warehouse.', 'WAREHOUSE_CATALOG_ADMIN_ONLY', 403);
        }

        $data = $this->validated($request);
        $warehouse = Outlet::query()->create([
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'type' => 'warehouse',
            'address' => $this->nullableText($data['address'] ?? null),
            'phone' => $this->nullableText($data['phone'] ?? null),
            'timezone' => trim($data['timezone'] ?? 'Asia/Jakarta') ?: 'Asia/Jakarta',
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_hr_source' => false,
            'is_compatibility_stub' => false,
        ]);

        return ApiResponse::ok($this->serialize($warehouse), 'Data Warehouse berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouse = $this->findAllowed($request, $id);
        if (! $warehouse) {
            return ApiResponse::error('Data Warehouse tidak ditemukan pada scope akun ini.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $warehouse->id);
        $payload = [
            ...$data,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'type' => 'warehouse',
            'address' => $this->nullableText($data['address'] ?? null),
            'phone' => $this->nullableText($data['phone'] ?? null),
            'timezone' => trim($data['timezone'] ?? 'Asia/Jakarta') ?: 'Asia/Jakarta',
        ];

        if (! $this->isAdministrator($request)) {
            unset($payload['is_active']);
        } else {
            $payload['is_active'] = (bool) ($data['is_active'] ?? true);
        }

        $warehouse->fill($payload)->save();

        return ApiResponse::ok($this->serialize($warehouse->fresh()), 'Data Warehouse berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->isAdministrator($request)) {
            return ApiResponse::error('Hanya Administrator yang dapat menonaktifkan warehouse.', 'WAREHOUSE_CATALOG_ADMIN_ONLY', 403);
        }

        $warehouse = $this->findAllowed($request, $id, true);
        if (! $warehouse) {
            return ApiResponse::error('Data Warehouse tidak ditemukan.', 'NOT_FOUND', 404);
        }

        if (WarehouseChainSupply::query()->where('warehouse_id', $id)->where('is_active', true)->exists()
            || WarehouseStorage::query()->where('warehouse_id', $id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => ['Warehouse masih memiliki Chain Supply atau Storage aktif. Nonaktifkan relasinya terlebih dahulu.'],
            ]);
        }

        $warehouse->forceFill(['is_active' => false])->save();

        return ApiResponse::ok($this->serialize($warehouse->fresh()), 'Warehouse berhasil dinonaktifkan.');
    }

    private function findAllowed(Request $request, string $id, bool $adminAll = false): ?Outlet
    {
        $query = Outlet::query()->whereKey($id)->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'");
        if (! ($adminAll && $this->isAdministrator($request)) && ! $this->isAdministrator($request)) {
            $query->whereIn('id', $this->allowedWarehouseIds($request) ?: ['__none__']);
        }
        return $query->first();
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('outlets', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['required', 'timezone'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function serialize(Outlet $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'address' => $row->address,
            'phone' => $row->phone,
            'timezone' => (string) ($row->timezone ?: 'Asia/Jakarta'),
            'latitude' => $row->latitude !== null ? (float) $row->latitude : null,
            'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
            'radius_m' => $row->radius_m !== null ? (int) $row->radius_m : null,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
