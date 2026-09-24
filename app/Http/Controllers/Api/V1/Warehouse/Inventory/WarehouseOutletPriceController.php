<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseChainSupply;
use App\Models\Warehouse\WarehouseOutletPricePolicy;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Warehouse\WarehouseItemPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseOutletPriceController extends WarehouseInventoryBaseController
{
    private const BANDS = ['MIN', 'AVG', 'MAX', 'CUSTOM'];

    public function index(Request $request, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->boolQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'outlet_id' => ['nullable', 'ulid'],
            'sku_id' => ['nullable', 'ulid'],
            'price_band' => ['nullable', Rule::in(self::BANDS)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseOutletPricePolicy::query()
            ->with(['outlet:id,code,name', 'sku.brand:id,code,name', 'sku.baseUom:id,code,name,symbol'])
            ->where('warehouse_id', $warehouseId);

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->whereHas('outlet', fn ($outlet) => $outlet->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))
                ->orWhereHas('sku', fn ($sku) => $sku->where('sku_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")));
        }
        foreach (['outlet_id', 'sku_id', 'price_band'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('outlet_id')->orderBy('sku_id')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn ($row) => $this->serialize($row, $prices))->values(),
            'pagination' => $this->pagination($paginator),
            'summary' => [
                'total_policy' => (int) WarehouseOutletPricePolicy::query()->where('warehouse_id', $warehouseId)->count(),
                'active_policy' => (int) WarehouseOutletPricePolicy::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->count(),
                'chain_outlet_count' => (int) WarehouseChainSupply::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(Request $request, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $this->validated($request, $warehouseId);
        $this->validateChainSupply($warehouseId, (string) $data['outlet_id']);
        $this->validateCustom($data);

        $existing = WarehouseOutletPricePolicy::query()
            ->where('warehouse_id', $warehouseId)
            ->where('outlet_id', $data['outlet_id'])
            ->where('sku_id', $data['sku_id'])
            ->first();

        $row = WarehouseOutletPricePolicy::query()->updateOrCreate(
            ['warehouse_id' => $warehouseId, 'outlet_id' => $data['outlet_id'], 'sku_id' => $data['sku_id']],
            [
                'price_band' => strtoupper($data['price_band']),
                'custom_price' => strtoupper($data['price_band']) === 'CUSTOM' ? (float) $data['custom_price'] : null,
                'effective_from' => $data['effective_from'] ?? today(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by_user_id' => $existing?->created_by_user_id ?: $request->user()?->id,
                'updated_by_user_id' => $request->user()?->id,
            ]
        );

        return ApiResponse::ok($this->serialize($row->fresh(['outlet', 'sku.brand', 'sku.baseUom']), $prices), $existing ? 'Kebijakan harga outlet berhasil diperbarui.' : 'Kebijakan harga outlet berhasil dibuat.', $existing ? 200 : 201);
    }

    public function update(Request $request, string $id, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseOutletPricePolicy::query()->where('warehouse_id', $warehouseId)->find($id);
        if (! $row) {
            return ApiResponse::error('Kebijakan harga tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $warehouseId, $id);
        $this->validateChainSupply($warehouseId, (string) $data['outlet_id']);
        $this->validateCustom($data);

        $row->fill([
            'outlet_id' => $data['outlet_id'],
            'sku_id' => $data['sku_id'],
            'price_band' => strtoupper($data['price_band']),
            'custom_price' => strtoupper($data['price_band']) === 'CUSTOM' ? (float) $data['custom_price'] : null,
            'effective_from' => $data['effective_from'] ?? today(),
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($row->fresh(['outlet', 'sku.brand', 'sku.baseUom']), $prices), 'Kebijakan harga outlet berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseOutletPricePolicy::query()->where('warehouse_id', $warehouseId)->find($id);
        if (! $row) {
            return ApiResponse::error('Kebijakan harga tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $row->forceFill(['is_active' => false, 'updated_by_user_id' => $request->user()?->id])->save();
        return ApiResponse::ok(null, 'Kebijakan harga outlet dinonaktifkan.');
    }

    private function validated(Request $request, string $warehouseId, ?string $ignoreId = null): array
    {
        $skuRules = ['required', 'ulid', 'exists:stk_skus,id'];
        if ($ignoreId) {
            $outletId = (string) $request->input('outlet_id', '');
            $skuRules[] = Rule::unique('wh_outlet_price_policies', 'sku_id')
                ->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->where('outlet_id', $outletId))
                ->ignore($ignoreId);
        }

        return $request->validate([
            'outlet_id' => ['required', 'ulid', 'exists:outlets,id'],
            'sku_id' => $skuRules,
            'price_band' => ['required', Rule::in(self::BANDS)],
            'custom_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function validateChainSupply(string $warehouseId, string $outletId): void
    {
        $valid = WarehouseChainSupply::query()
            ->where('warehouse_id', $warehouseId)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet belum menjadi customer aktif pada Chain Supply warehouse ini.']]);
        }
    }

    private function validateCustom(array $data): void
    {
        if (strtoupper((string) $data['price_band']) === 'CUSTOM' && ! isset($data['custom_price'])) {
            throw ValidationException::withMessages(['custom_price' => ['Custom price wajib diisi saat band CUSTOM dipilih.']]);
        }
    }

    private function serialize(WarehouseOutletPricePolicy $row, WarehouseItemPriceService $prices): array
    {
        $bands = $prices->bands($row->sku, (string) $row->warehouse_id);
        return [
            'id' => (string) $row->id,
            'warehouse_id' => (string) $row->warehouse_id,
            'outlet_id' => (string) $row->outlet_id,
            'outlet_code' => $row->outlet?->code,
            'outlet_name' => $row->outlet?->name,
            'sku_id' => (string) $row->sku_id,
            'sku_code' => $row->sku?->sku_code,
            'item_name' => $row->sku?->name,
            'brand_name' => $row->sku?->brand?->name,
            'base_uom_code' => $row->sku?->baseUom?->code,
            'price_band' => (string) $row->price_band,
            'custom_price' => $row->custom_price !== null ? (float) $row->custom_price : null,
            'price_min' => $bands['MIN'],
            'price_avg' => $bands['AVG'],
            'price_max' => $bands['MAX'],
            'active_price' => $prices->resolve($row->sku, (string) $row->warehouse_id, (string) $row->price_band, $row->custom_price !== null ? (float) $row->custom_price : null),
            'effective_from' => $row->effective_from?->format('Y-m-d'),
            'effective_to' => $row->effective_to?->format('Y-m-d'),
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
