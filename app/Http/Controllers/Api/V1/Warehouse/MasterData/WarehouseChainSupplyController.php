<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Models\Warehouse\WarehouseChainSupply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseChainSupplyController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseChainSupply::query()
            ->with(['warehouse:id,code,name,timezone', 'outlet:id,code,name,address,timezone,is_active'])
            ->where('warehouse_id', $warehouseId);

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->whereHas('outlet', fn ($builder) => $builder
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%"));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderByDesc('is_active')->orderByDesc('updated_at')->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (WarehouseChainSupply $row) => $this->serialize($row))->all(),
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
        $this->assertCustomerOutlet($data['outlet_id']);
        $userId = $request->user()?->id;

        $row = DB::transaction(function () use ($request, $data, $warehouseId, $userId) {
            $existing = WarehouseChainSupply::query()->where('outlet_id', $data['outlet_id'])->lockForUpdate()->first();
            if ($existing && $existing->is_active && (string) $existing->warehouse_id !== (string) $warehouseId && $this->scopeLocked($request)) {
                $currentWarehouse = Outlet::query()->find($existing->warehouse_id);
                throw ValidationException::withMessages([
                    'outlet_id' => ['Outlet sudah disupply oleh '.($currentWarehouse?->name ?: 'warehouse lain').'. Reassignment hanya dapat dilakukan user dengan scope lintas warehouse.'],
                ]);
            }

            if ($existing) {
                $existing->fill([
                    'warehouse_id' => $warehouseId,
                    'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                    'notes' => $this->nullableText($data['notes'] ?? null),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'updated_by_user_id' => $userId,
                ])->save();
                return $existing->fresh(['warehouse', 'outlet']);
            }

            return WarehouseChainSupply::query()->create([
                'warehouse_id' => $warehouseId,
                'outlet_id' => $data['outlet_id'],
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'notes' => $this->nullableText($data['notes'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ])->fresh(['warehouse', 'outlet']);
        });

        return ApiResponse::ok($this->serialize($row), 'Chain Supply berhasil disimpan.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseChainSupply::query()->with(['warehouse', 'outlet'])->find($id);
        if (! $row || ($this->scopeLocked($request) && (string) $row->warehouse_id !== (string) $warehouseId)) {
            return ApiResponse::error('Chain Supply tidak ditemukan pada scope warehouse ini.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $row->outlet_id);
        $this->assertCustomerOutlet($data['outlet_id']);

        $conflict = WarehouseChainSupply::query()
            ->where('outlet_id', $data['outlet_id'])
            ->where('id', '<>', $row->id)
            ->first();
        if ($conflict) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet sudah memiliki mapping Chain Supply.']]);
        }

        $row->fill([
            'warehouse_id' => $warehouseId,
            'outlet_id' => $data['outlet_id'],
            'effective_from' => $data['effective_from'] ?? $row->effective_from,
            'notes' => $this->nullableText($data['notes'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($row->fresh(['warehouse', 'outlet'])), 'Chain Supply berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseChainSupply::query()->whereKey($id)->where('warehouse_id', $warehouseId)->first();
        if (! $row) {
            return ApiResponse::error('Chain Supply tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $row->forceFill([
            'is_active' => false,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($row->fresh(['warehouse', 'outlet'])), 'Chain Supply berhasil dinonaktifkan.');
    }

    private function validated(Request $request, ?string $currentOutletId = null): array
    {
        return $request->validate([
            'outlet_id' => [
                'required',
                'string',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query
                    ->where('type', 'outlet')
                    ->where('is_active', true)),
            ],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function assertCustomerOutlet(string $outletId): void
    {
        $valid = Outlet::query()->whereKey($outletId)->where('type', 'outlet')->where('is_active', true)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['outlet_id' => ['Customer Chain Supply harus outlet aktif dengan type outlet.']]);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function serialize(WarehouseChainSupply $row): array
    {
        return [
            'id' => (string) $row->id,
            'warehouse_id' => (string) $row->warehouse_id,
            'warehouse_code' => $row->warehouse?->code,
            'warehouse_name' => $row->warehouse?->name,
            'outlet_id' => (string) $row->outlet_id,
            'outlet_code' => $row->outlet?->code,
            'outlet_name' => $row->outlet?->name,
            'outlet_address' => $row->outlet?->address,
            'effective_from' => $row->effective_from?->format('Y-m-d'),
            'notes' => $row->notes,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
