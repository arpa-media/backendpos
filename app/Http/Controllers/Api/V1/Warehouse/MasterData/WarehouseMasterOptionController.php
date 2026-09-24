<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Models\Warehouse\WarehouseChainSupply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseMasterOptionController extends WarehouseMasterDataBaseController
{
    public function __invoke(Request $request): JsonResponse
    {
        $allowedWarehouseIds = $this->allowedWarehouseIds($request);

        $warehouses = Outlet::query()
            ->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")
            ->where('is_active', true)
            ->when(! $this->isAdministrator($request), fn ($query) => $query->whereIn('id', $allowedWarehouseIds ?: ['__none__']))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'timezone'])
            ->map(fn (Outlet $row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'timezone' => (string) ($row->timezone ?: 'Asia/Jakarta'),
            ])
            ->values();

        $mappings = WarehouseChainSupply::query()
            ->with('warehouse:id,code,name')
            ->get()
            ->keyBy(fn (WarehouseChainSupply $row) => (string) $row->outlet_id);

        $outlets = Outlet::query()
            ->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'")
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'address', 'timezone'])
            ->map(function (Outlet $row) use ($mappings) {
                $mapping = $mappings->get((string) $row->id);
                return [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'address' => $row->address,
                    'timezone' => (string) ($row->timezone ?: 'Asia/Jakarta'),
                    'chain_supply_id' => $mapping?->id ? (string) $mapping->id : null,
                    'warehouse_id' => $mapping?->warehouse_id ? (string) $mapping->warehouse_id : null,
                    'warehouse_code' => $mapping?->warehouse?->code,
                    'warehouse_name' => $mapping?->warehouse?->name,
                    'chain_supply_active' => (bool) ($mapping?->is_active ?? false),
                ];
            })
            ->values();

        return ApiResponse::ok([
            'warehouses' => $warehouses,
            'outlets' => $outlets,
            'storage_types' => [
                ['value' => 'shelves', 'label' => 'Shelves'],
                ['value' => 'box', 'label' => 'Box'],
                ['value' => 'freezer', 'label' => 'Freezer'],
                ['value' => 'basket', 'label' => 'Basket'],
                ['value' => 'rack', 'label' => 'Rack'],
            ],
            'supplier_source_types' => [
                ['value' => 'supplier', 'label' => 'Supplier'],
                ['value' => 'other_supplier', 'label' => 'Other Supplier'],
            ],
        ]);
    }
}
