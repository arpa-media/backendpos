<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseSkuUom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseSkuUomController extends WarehouseInventoryBaseController
{
    public function index(Request $request, string $skuId): JsonResponse
    {
        $sku = $this->findSku($skuId);
        if ($sku instanceof JsonResponse) {
            return $sku;
        }

        $items = WarehouseSkuUom::query()
            ->with('uom:id,code,name,symbol,decimal_places')
            ->where('sku_id', $skuId)
            ->orderByDesc('is_purchase_default')
            ->orderBy('conversion_factor')
            ->get()
            ->map(fn ($row) => $this->serialize($row));

        return ApiResponse::ok(['sku' => ['id' => (string) $sku->id, 'sku_code' => $sku->sku_code, 'name' => $sku->name], 'items' => $items]);
    }

    public function store(Request $request, string $skuId): JsonResponse
    {
        $sku = $this->findSku($skuId);
        if ($sku instanceof JsonResponse) {
            return $sku;
        }

        $data = $this->validated($request, $skuId);
        $this->guardBaseRule($sku, $data);

        $row = DB::transaction(function () use ($request, $sku, $data): WarehouseSkuUom {
            if ((bool) ($data['is_purchase_default'] ?? false)) {
                WarehouseSkuUom::query()->where('sku_id', $sku->id)->update(['is_purchase_default' => false, 'updated_at' => now()]);
                $sku->forceFill([
                    'purchase_uom_id' => $data['uom_id'],
                    'purchase_conversion_factor' => (float) $data['conversion_factor'],
                    'updated_by_user_id' => $request->user()?->id,
                ])->save();
            }

            return WarehouseSkuUom::query()->create([
                'sku_id' => $sku->id,
                'uom_id' => $data['uom_id'],
                'conversion_factor' => (float) $data['conversion_factor'],
                'is_purchase_default' => (bool) ($data['is_purchase_default'] ?? false),
                'is_request_enabled' => (bool) ($data['is_request_enabled'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by_user_id' => $request->user()?->id,
                'updated_by_user_id' => $request->user()?->id,
            ]);
        });

        return ApiResponse::ok($this->serialize($row->fresh('uom')), 'UoM item berhasil ditambahkan.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = WarehouseSkuUom::query()->with('sku')->find($id);
        if (! $row) {
            return ApiResponse::error('UoM item tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, (string) $row->sku_id, $id);
        if ((string) $row->uom_id === (string) $row->sku?->base_uom_id
            && (string) $data['uom_id'] !== (string) $row->uom_id) {
            throw ValidationException::withMessages(['uom_id' => ['Baris Base UoM tidak dapat diubah menjadi UoM lain. Ubah Base UoM dari form Item.']]);
        }
        $this->guardBaseRule($row->sku, $data);

        DB::transaction(function () use ($request, $row, $data): void {
            if ((bool) ($data['is_purchase_default'] ?? false)) {
                WarehouseSkuUom::query()->where('sku_id', $row->sku_id)->whereKeyNot($row->id)->update(['is_purchase_default' => false, 'updated_at' => now()]);
                $row->sku->forceFill([
                    'purchase_uom_id' => $data['uom_id'],
                    'purchase_conversion_factor' => (float) $data['conversion_factor'],
                    'updated_by_user_id' => $request->user()?->id,
                ])->save();
            } elseif ($row->is_purchase_default) {
                throw ValidationException::withMessages(['is_purchase_default' => ['UoM pembelian default tidak dapat dilepas sebelum UoM lain dijadikan default.']]);
            }

            $row->fill([
                'uom_id' => $data['uom_id'],
                'conversion_factor' => (float) $data['conversion_factor'],
                'is_purchase_default' => (bool) ($data['is_purchase_default'] ?? false),
                'is_request_enabled' => (bool) ($data['is_request_enabled'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by_user_id' => $request->user()?->id,
            ])->save();
        });

        return ApiResponse::ok($this->serialize($row->fresh('uom')), 'UoM item berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = WarehouseSkuUom::query()->with('sku')->find($id);
        if (! $row) {
            return ApiResponse::error('UoM item tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ((string) $row->uom_id === (string) $row->sku?->base_uom_id || $row->is_purchase_default) {
            return ApiResponse::error('Base UoM atau UoM pembelian default tidak dapat dihapus.', 'UOM_PROTECTED', 422);
        }

        $row->delete();
        return ApiResponse::ok(null, 'UoM item berhasil dihapus.');
    }

    private function findSku(string $id): WarehouseSku|JsonResponse
    {
        return WarehouseSku::query()->find($id)
            ?: ApiResponse::error('Item tidak ditemukan.', 'NOT_FOUND', 404);
    }

    private function validated(Request $request, string $skuId, ?string $ignoreId = null): array
    {
        $unique = Rule::unique('wh_sku_uoms', 'uom_id')->where(fn ($query) => $query->where('sku_id', $skuId));
        if ($ignoreId) {
            $unique = $unique->ignore($ignoreId);
        }

        return $request->validate([
            'uom_id' => ['required', 'ulid', 'exists:stk_uoms,id', $unique],
            'conversion_factor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'is_purchase_default' => ['nullable', 'boolean'],
            'is_request_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function guardBaseRule(WarehouseSku $sku, array &$data): void
    {
        if ((string) $data['uom_id'] === (string) $sku->base_uom_id) {
            $data['conversion_factor'] = 1;
            $data['is_request_enabled'] = true;
        }
    }

    private function serialize(WarehouseSkuUom $row): array
    {
        return [
            'id' => (string) $row->id,
            'sku_id' => (string) $row->sku_id,
            'uom_id' => (string) $row->uom_id,
            'uom_code' => $row->uom?->code,
            'uom_name' => $row->uom?->name,
            'uom_symbol' => $row->uom?->symbol,
            'conversion_factor' => (float) $row->conversion_factor,
            'is_purchase_default' => (bool) $row->is_purchase_default,
            'is_request_enabled' => (bool) $row->is_request_enabled,
            'is_active' => (bool) $row->is_active,
        ];
    }
}
