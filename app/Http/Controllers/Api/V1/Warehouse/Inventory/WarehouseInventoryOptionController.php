<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockUom;
use App\Models\Warehouse\WarehouseBrand;
use App\Models\Warehouse\WarehouseChainSupply;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseInventoryOptionController extends WarehouseInventoryBaseController
{
    public function __invoke(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        return ApiResponse::ok([
            'brands' => WarehouseBrand::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => StockCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name']),
            'uoms' => StockUom::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'symbol', 'decimal_places']),
            'storages' => WarehouseStorage::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'storage_type']),
            'outlets' => WarehouseChainSupply::query()
                ->with('outlet:id,code,name,type,is_active')
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->whereHas('outlet', fn ($query) => $query->where('is_active', true))
                ->orderBy('effective_from')
                ->get()
                ->map(fn ($row) => [
                    'id' => (string) $row->outlet_id,
                    'code' => $row->outlet?->code,
                    'name' => $row->outlet?->name,
                ])->values(),
            'skus' => WarehouseSku::query()->where('is_active', true)->orderBy('name')->get(['id', 'sku_code', 'name', 'base_uom_id']),
            'price_bands' => ['MIN', 'AVG', 'MAX', 'CUSTOM'],
            'batch_source_types' => [
                ['value' => 'supplier_purchase', 'label' => 'Pembelian Supplier'],
                ['value' => 'return', 'label' => 'Retur'],
                ['value' => 'production', 'label' => 'Hasil Produksi'],
                ['value' => 'transfer_in', 'label' => 'Transfer Masuk'],
                ['value' => 'manual', 'label' => 'Registrasi Manual'],
                ['value' => 'legacy_opening', 'label' => 'Saldo Legacy'],
            ],
            'batch_statuses' => ['draft', 'active', 'quarantine', 'closed', 'cancelled'],
            'stock_unit_statuses' => ['draft', 'available', 'reserved', 'in_transit', 'consumed', 'quarantine', 'damaged', 'cancelled'],
        ]);
    }
}
