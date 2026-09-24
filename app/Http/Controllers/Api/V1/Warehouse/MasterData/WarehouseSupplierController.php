<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseSupplierController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'source_type' => ['nullable', Rule::in(['supplier', 'other_supplier', 'warehouse'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseSupplier::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        $paginator = $query->orderByRaw("CASE WHEN source_type = 'warehouse' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (WarehouseSupplier $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $supplier = WarehouseSupplier::query()->create([
            ...$this->normalize($data),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok($this->serialize($supplier), 'Supplier berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $supplier = WarehouseSupplier::query()->find($id);
        if (! $supplier) {
            return ApiResponse::error('Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ($this->isSystem($supplier)) {
            throw ValidationException::withMessages([
                'supplier' => ['Supplier sistem Warehouse tidak dapat diubah dari menu Supplier.'],
            ]);
        }

        $data = $this->validated($request, $supplier->id);
        $supplier->fill([
            ...$this->normalize($data),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($supplier->fresh()), 'Supplier berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $supplier = WarehouseSupplier::query()->find($id);
        if (! $supplier) {
            return ApiResponse::error('Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ($this->isSystem($supplier)) {
            throw ValidationException::withMessages([
                'supplier' => ['Supplier sistem Warehouse tidak dapat dihapus.'],
            ]);
        }

        $used = (Schema::hasTable('pur_price_lists') && DB::table('pur_price_lists')->where('supplier_source_id', $id)->exists())
            || (Schema::hasTable('stk_request_items') && DB::table('stk_request_items')->where('supplier_source_id', $id)->exists())
            || (Schema::hasTable('pur_purchase_orders') && DB::table('pur_purchase_orders')->where('supplier_source_id', $id)->exists());

        if ($used) {
            $supplier->forceFill([
                'is_active' => false,
                'updated_by_user_id' => $request->user()?->id,
            ])->save();

            return ApiResponse::ok($this->serialize($supplier->fresh()), 'Supplier sudah dipakai transaksi dan dinonaktifkan.');
        }

        $supplier->delete();
        return ApiResponse::ok(null, 'Supplier berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('pur_supplier_sources', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:180'],
            'source_type' => ['required', Rule::in(['supplier', 'other_supplier'])],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'address' => ['nullable', 'string', 'max:2000'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalize(array $data): array
    {
        foreach (['contact_name', 'phone', 'email', 'address', 'tax_number', 'notes'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $data[$field] = $value === '' ? null : $value;
        }

        $data['code'] = strtoupper(trim($data['code']));
        $data['name'] = trim($data['name']);
        $data['source_type'] = strtolower(trim($data['source_type']));
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        return $data;
    }

    private function isSystem(WarehouseSupplier $row): bool
    {
        return strtolower((string) $row->source_type) === 'warehouse'
            || in_array(strtoupper((string) $row->code), ['WAREHOUSE-MAIN', 'OTHER-SUPPLIER'], true);
    }

    private function serialize(WarehouseSupplier $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'source_type' => (string) $row->source_type,
            'contact_name' => $row->contact_name,
            'phone' => $row->phone,
            'email' => $row->email,
            'address' => $row->address,
            'tax_number' => $row->tax_number,
            'notes' => $row->notes,
            'is_active' => (bool) $row->is_active,
            'is_system' => $this->isSystem($row),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
