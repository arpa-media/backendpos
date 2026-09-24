<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseSkuUom;
use App\Services\Warehouse\WarehouseItemPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseAllItemController extends WarehouseInventoryBaseController
{
    public function index(Request $request, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->boolQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'category_id' => ['nullable', 'ulid'],
            'brand_id' => ['nullable', 'ulid'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseSku::query()
            ->with(['category:id,code,name', 'brand:id,code,name', 'baseUom:id,code,name,symbol', 'purchaseUom:id,code,name,symbol'])
            ->select('stk_skus.*')
            ->selectSub(
                DB::table('stk_inventory_balances')
                    ->select('on_hand_qty')
                    ->whereColumn('sku_id', 'stk_skus.id')
                    ->where('outlet_id', $warehouseId)
                    ->limit(1),
                'warehouse_qty'
            )
            ->selectSub(
                DB::table('stk_inventory_balances')
                    ->select('average_unit_cost')
                    ->whereColumn('sku_id', 'stk_skus.id')
                    ->where('outlet_id', $warehouseId)
                    ->limit(1),
                'average_unit_cost'
            )
            ->selectSub(
                DB::table('stk_inventory_balances')
                    ->select('inventory_value')
                    ->whereColumn('sku_id', 'stk_skus.id')
                    ->where('outlet_id', $warehouseId)
                    ->limit(1),
                'inventory_value'
            )
            ->selectSub(
                DB::table('wh_batch_balances')
                    ->selectRaw('COALESCE(SUM(on_hand_qty), 0)')
                    ->whereColumn('sku_id', 'stk_skus.id')
                    ->where('warehouse_id', $warehouseId),
                'batch_qty'
            );

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('sku_code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"));
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => $paginator->getCollection()->map(fn (WarehouseSku $row) => $this->serialize($row, $warehouseId, $prices))->values(),
            'pagination' => $this->pagination($paginator),
            'summary' => [
                'total_sku' => (int) $paginator->total(),
                'warehouse_qty_base' => (float) DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->sum('on_hand_qty'),
                'inventory_value' => (float) DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->sum('inventory_value'),
                'unreconciled_sku' => $this->unreconciledCount($warehouseId),
            ],
        ]);
    }

    public function show(Request $request, string $id, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseSku::query()->with([
            'category:id,code,name', 'brand:id,code,name', 'baseUom:id,code,name,symbol',
            'purchaseUom:id,code,name,symbol', 'skuUoms.uom:id,code,name,symbol,decimal_places',
        ])->find($id);
        if (! $row) {
            return ApiResponse::error('Item tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($this->serialize($row, $warehouseId, $prices, true));
    }

    public function store(Request $request, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $this->validated($request);
        $this->validatePriceOrder($data);

        $row = DB::transaction(function () use ($request, $data): WarehouseSku {
            $row = WarehouseSku::query()->create($this->payload($request, $data, true));
            $this->syncDefaultUoms($row, $request->user()?->id);
            return $row;
        });

        return ApiResponse::ok(
            $this->serialize($row->fresh(['category', 'brand', 'baseUom', 'purchaseUom', 'skuUoms.uom']), $warehouseId, $prices, true),
            'Item Warehouse berhasil dibuat.',
            201
        );
    }

    public function update(Request $request, string $id, WarehouseItemPriceService $prices): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $row = WarehouseSku::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Item tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $id);
        $this->validatePriceOrder($data);
        $this->guardBaseUomChange($row, (string) $data['base_uom_id']);

        DB::transaction(function () use ($request, $data, $row): void {
            $oldBaseUomId = (string) $row->base_uom_id;
            $row->fill($this->payload($request, $data, false))->save();
            $this->syncDefaultUoms($row, $request->user()?->id);
            if ($oldBaseUomId !== (string) $row->base_uom_id && $oldBaseUomId !== (string) $row->purchase_uom_id) {
                WarehouseSkuUom::query()
                    ->where('sku_id', $row->id)
                    ->where('uom_id', $oldBaseUomId)
                    ->update(['is_active' => false, 'updated_by_user_id' => $request->user()?->id, 'updated_at' => now()]);
            }
        });

        return ApiResponse::ok(
            $this->serialize($row->fresh(['category', 'brand', 'baseUom', 'purchaseUom', 'skuUoms.uom']), $warehouseId, $prices, true),
            'Item Warehouse berhasil diperbarui.'
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = WarehouseSku::query()->find($id);
        if (! $row) {
            return ApiResponse::error('Item tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $hasStock = DB::table('stk_inventory_balances')->where('sku_id', $id)->whereRaw('ABS(on_hand_qty) > 0.0001')->exists()
            || DB::table('wh_batch_balances')->where('sku_id', $id)->whereRaw('ABS(on_hand_qty) > 0.0001')->exists();
        $hasBatch = WarehouseBatch::query()->where('sku_id', $id)->exists();

        if ($hasStock || $hasBatch) {
            $row->forceFill(['is_active' => false, 'updated_by_user_id' => $request->user()?->id])->save();
            return ApiResponse::ok(null, 'Item sudah memiliki histori/saldo sehingga dinonaktifkan, bukan dihapus.');
        }

        $row->delete();
        return ApiResponse::ok(null, 'Item berhasil dihapus.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        $skuUnique = Rule::unique('stk_skus', 'sku_code');
        $barcodeUnique = Rule::unique('stk_skus', 'barcode');
        if ($ignoreId) {
            $skuUnique = $skuUnique->ignore($ignoreId);
            $barcodeUnique = $barcodeUnique->ignore($ignoreId);
        }

        return $request->validate([
            'sku_code' => ['required', 'string', 'max:60', $skuUnique],
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['required', 'ulid', 'exists:stk_categories,id'],
            'brand_id' => ['required', 'ulid', 'exists:wh_brands,id'],
            'base_uom_id' => ['required', 'ulid', 'exists:stk_uoms,id'],
            'purchase_uom_id' => ['required', 'ulid', 'exists:stk_uoms,id'],
            'purchase_conversion_factor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'barcode' => ['nullable', 'string', 'max:100', $barcodeUnique],
            'price_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function validatePriceOrder(array $data): void
    {
        if (($data['price_min'] ?? null) !== null && ($data['price_max'] ?? null) !== null
            && (float) $data['price_min'] > (float) $data['price_max']) {
            throw ValidationException::withMessages(['price_max' => ['Price Max harus lebih besar atau sama dengan Price Min.']]);
        }
    }

    private function payload(Request $request, array $data, bool $creating): array
    {
        $payload = [
            'sku_code' => strtoupper(trim($data['sku_code'])),
            'name' => trim($data['name']),
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'],
            'base_uom_id' => $data['base_uom_id'],
            'purchase_uom_id' => $data['purchase_uom_id'],
            'purchase_conversion_factor' => (float) $data['purchase_conversion_factor'],
            'barcode' => $this->nullableText($data['barcode'] ?? null),
            'price_min' => $data['price_min'] ?? null,
            'price_max' => $data['price_max'] ?? null,
            'notes' => $this->nullableText($data['notes'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ];
        if ($creating) {
            $payload['created_by_user_id'] = $request->user()?->id;
        }
        return $payload;
    }

    private function syncDefaultUoms(WarehouseSku $sku, ?string $userId): void
    {
        WarehouseSkuUom::query()->where('sku_id', $sku->id)->update([
            'is_purchase_default' => false,
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ]);

        WarehouseSkuUom::query()->updateOrCreate(
            ['sku_id' => $sku->id, 'uom_id' => $sku->base_uom_id],
            [
                'conversion_factor' => 1,
                'is_purchase_default' => $sku->purchase_uom_id === $sku->base_uom_id,
                'is_request_enabled' => true,
                'is_active' => true,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]
        );

        WarehouseSkuUom::query()->updateOrCreate(
            ['sku_id' => $sku->id, 'uom_id' => $sku->purchase_uom_id],
            [
                'conversion_factor' => $sku->purchase_uom_id === $sku->base_uom_id ? 1 : $sku->purchase_conversion_factor,
                'is_purchase_default' => true,
                'is_request_enabled' => true,
                'is_active' => true,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]
        );
    }

    private function guardBaseUomChange(WarehouseSku $row, string $newBaseUomId): void
    {
        if ((string) $row->base_uom_id === $newBaseUomId) {
            return;
        }

        $hasStock = DB::table('stk_inventory_balances')->where('sku_id', $row->id)->whereRaw('ABS(on_hand_qty) > 0.0001')->exists()
            || DB::table('wh_batch_balances')->where('sku_id', $row->id)->whereRaw('ABS(on_hand_qty) > 0.0001')->exists();
        if ($hasStock) {
            throw ValidationException::withMessages([
                'base_uom_id' => ['Base UoM tidak dapat diubah setelah item memiliki saldo. Buat SKU baru atau lakukan migrasi UoM terkontrol.'],
            ]);
        }
    }

    private function serialize(WarehouseSku $row, string $warehouseId, WarehouseItemPriceService $prices, bool $detail = false): array
    {
        $bands = $prices->bands($row, $warehouseId);
        $warehouseQty = (float) ($row->getAttribute('warehouse_qty') ?? $bands['on_hand_qty']);
        $batchQty = $row->getAttribute('batch_qty');
        if ($batchQty === null) {
            $batchQty = (float) DB::table('wh_batch_balances')->where('warehouse_id', $warehouseId)->where('sku_id', $row->id)->sum('on_hand_qty');
        }

        $data = [
            'id' => (string) $row->id,
            'sku_code' => (string) $row->sku_code,
            'name' => (string) $row->name,
            'category_id' => (string) $row->category_id,
            'category_code' => $row->category?->code,
            'category_name' => $row->category?->name,
            'brand_id' => (string) ($row->brand_id ?? ''),
            'brand_code' => $row->brand?->code,
            'brand_name' => $row->brand?->name,
            'qty' => $warehouseQty,
            'batch_qty' => (float) $batchQty,
            'reconciliation_variance' => round($warehouseQty - (float) $batchQty, 4),
            'purchase_uom_id' => (string) ($row->purchase_uom_id ?? ''),
            'purchase_uom_code' => $row->purchaseUom?->code,
            'purchase_uom_symbol' => $row->purchaseUom?->symbol,
            'conversion' => (float) $row->purchase_conversion_factor,
            'base_uom_id' => (string) $row->base_uom_id,
            'base_uom_code' => $row->baseUom?->code,
            'base_uom_symbol' => $row->baseUom?->symbol,
            'barcode' => $row->barcode,
            'price_min' => $bands['MIN'],
            'price_avg' => $bands['AVG'],
            'price_max' => $bands['MAX'],
            'inventory_value' => (float) ($row->getAttribute('inventory_value') ?? $bands['inventory_value']),
            'notes' => $row->notes,
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];

        if ($detail) {
            $data['uoms'] = $row->skuUoms->map(fn ($uom) => [
                'id' => (string) $uom->id,
                'uom_id' => (string) $uom->uom_id,
                'uom_code' => $uom->uom?->code,
                'uom_name' => $uom->uom?->name,
                'uom_symbol' => $uom->uom?->symbol,
                'conversion_factor' => (float) $uom->conversion_factor,
                'is_purchase_default' => (bool) $uom->is_purchase_default,
                'is_request_enabled' => (bool) $uom->is_request_enabled,
                'is_active' => (bool) $uom->is_active,
            ])->values();
        }

        return $data;
    }

    private function unreconciledCount(string $warehouseId): int
    {
        $aggregate = DB::table('stk_inventory_balances')
            ->where('outlet_id', $warehouseId)
            ->select('sku_id', 'on_hand_qty');

        return DB::query()->fromSub($aggregate, 'aggregate')
            ->leftJoinSub(
                DB::table('wh_batch_balances')->where('warehouse_id', $warehouseId)->groupBy('sku_id')->selectRaw('sku_id, SUM(on_hand_qty) as batch_qty'),
                'batch',
                'batch.sku_id',
                '=',
                'aggregate.sku_id'
            )
            ->whereRaw('ABS(aggregate.on_hand_qty - COALESCE(batch.batch_qty, 0)) > 0.0001')
            ->count();
    }
}
