<?php

namespace App\Services\Warehouse\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk SKU + transaction-UOM catalog for Warehouse screens.
 *
 * The legacy per-SKU catalog() call is intentionally not used here because an
 * options page can contain hundreds/thousands of SKUs. This service keeps the
 * query count effectively constant and can be reused by Sales/Transfer flows.
 */
class WarehouseSkuTransactionCatalogService
{
    public function forWarehouse(string $warehouseId): array
    {
        $skuQuery = DB::table('stk_skus as sku')
            ->leftJoin('wh_brands as brand', 'brand.id', '=', 'sku.brand_id')
            ->leftJoin('stk_uoms as base_uom', 'base_uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase_uom', 'purchase_uom.id', '=', 'sku.purchase_uom_id')
            ->leftJoin('stk_inventory_balances as balance', function ($join) use ($warehouseId): void {
                $join->on('balance.sku_id', '=', 'sku.id')
                    ->where('balance.outlet_id', '=', $warehouseId);
            })
            ->where('sku.is_active', true);

        if (Schema::hasColumn('stk_skus', 'deleted_at')) {
            $skuQuery->whereNull('sku.deleted_at');
        }

        $skus = $skuQuery
            ->orderBy('sku.name')
            ->orderBy('sku.sku_code')
            ->get([
                'sku.id', 'sku.sku_code', 'sku.name', 'sku.brand_id',
                'sku.base_uom_id', 'sku.purchase_uom_id', 'sku.purchase_conversion_factor',
                'sku.price_min', 'sku.price_max',
                'brand.code as brand_code', 'brand.name as brand_name',
                'base_uom.code as base_uom_code', 'base_uom.name as base_uom_name',
                'purchase_uom.code as purchase_uom_code', 'purchase_uom.name as purchase_uom_name',
                'balance.on_hand_qty', 'balance.average_unit_cost', 'balance.inventory_value',
            ]);

        if ($skus->isEmpty()) {
            return [];
        }

        $mappings = $this->activeMappings();

        return $skus->map(function (object $sku) use ($mappings): array {
            $skuId = (string) $sku->id;
            $baseId = (string) $sku->base_uom_id;
            $purchaseId = $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : null;

            $base = [
                'id' => $baseId,
                'code' => (string) ($sku->base_uom_code ?: 'UNIT'),
                'name' => (string) ($sku->base_uom_name ?: $sku->base_uom_code ?: 'Unit'),
                'conversion_factor' => 1.0,
                'is_base' => true,
                'is_purchase_default' => $purchaseId !== null && $purchaseId === $baseId,
                'is_request_enabled' => true,
            ];

            $byId = [$baseId => $base];

            foreach ($mappings->get($skuId, collect()) as $row) {
                $id = (string) $row->uom_id;
                $factor = round((float) $row->conversion_factor, 8);
                if ($factor <= 0) {
                    continue;
                }

                $byId[$id] = [
                    'id' => $id,
                    'code' => (string) $row->uom_code,
                    'name' => (string) ($row->uom_name ?: $row->uom_code ?: 'Unit'),
                    'conversion_factor' => $id === $baseId ? 1.0 : $factor,
                    'is_base' => $id === $baseId,
                    'is_purchase_default' => property_exists($row, 'is_purchase_default')
                        ? (bool) $row->is_purchase_default
                        : $purchaseId !== null && $id === $purchaseId,
                    'is_request_enabled' => property_exists($row, 'is_request_enabled')
                        ? (bool) $row->is_request_enabled
                        : true,
                ];
            }

            // Preserve the historical fallback: SKU-level Purchase UOM remains
            // selectable even if no wh_sku_uoms row was created for old data.
            if ($purchaseId && $sku->purchase_uom_code && (float) $sku->purchase_conversion_factor > 0) {
                $existing = $byId[$purchaseId] ?? null;
                $byId[$purchaseId] = [
                    'id' => $purchaseId,
                    'code' => (string) $sku->purchase_uom_code,
                    'name' => (string) ($sku->purchase_uom_name ?: $sku->purchase_uom_code),
                    'conversion_factor' => $purchaseId === $baseId
                        ? 1.0
                        : round((float) $sku->purchase_conversion_factor, 8),
                    'is_base' => $purchaseId === $baseId,
                    'is_purchase_default' => true,
                    'is_request_enabled' => $existing ? (bool) $existing['is_request_enabled'] : true,
                ];
            }

            // A malformed mapping must never hide/alter the Base UOM.
            $byId[$baseId] = array_merge($byId[$baseId] ?? [], $base);

            $uoms = array_values($byId);
            usort($uoms, static function (array $a, array $b): int {
                $aRank = $a['is_purchase_default'] ? 0 : ($a['is_base'] ? 1 : 2);
                $bRank = $b['is_purchase_default'] ? 0 : ($b['is_base'] ? 1 : 2);
                if ($aRank !== $bRank) {
                    return $aRank <=> $bRank;
                }

                return strcmp((string) $a['code'], (string) $b['code']);
            });

            $requestUoms = array_values(array_filter(
                $uoms,
                static fn (array $row): bool => (bool) $row['is_request_enabled']
            ));
            $defaultRequest = collect($requestUoms)->firstWhere('is_purchase_default', true)
                ?? ($requestUoms[0] ?? $base);

            return [
                'id' => $skuId,
                'sku_code' => (string) $sku->sku_code,
                'name' => (string) $sku->name,
                'brand' => ($sku->brand_id && ($sku->brand_code !== null || $sku->brand_name !== null)) ? [
                    'id' => (string) $sku->brand_id,
                    'code' => (string) ($sku->brand_code ?? ''),
                    'name' => (string) ($sku->brand_name ?? ''),
                ] : null,
                'base_uom' => $base,
                'purchase_uom' => collect($uoms)->firstWhere('is_purchase_default', true),
                'default_request_uom_id' => (string) $defaultRequest['id'],
                'default_output_uom_id' => $baseId,
                'uoms' => $uoms,
                'price_min' => (float) ($sku->price_min ?? 0),
                'price_max' => (float) ($sku->price_max ?? 0),
                'on_hand_qty' => (float) ($sku->on_hand_qty ?? 0),
                'average_unit_cost' => (float) ($sku->average_unit_cost ?? 0),
                'inventory_value' => (float) ($sku->inventory_value ?? 0),
            ];
        })->values()->all();
    }

    /** @return Collection<string, Collection<int, object>> */
    private function activeMappings(): Collection
    {
        if (
            ! Schema::hasTable('wh_sku_uoms')
            || ! Schema::hasColumn('wh_sku_uoms', 'sku_id')
            || ! Schema::hasColumn('wh_sku_uoms', 'uom_id')
            || ! Schema::hasColumn('wh_sku_uoms', 'conversion_factor')
        ) {
            return collect();
        }

        $hasPurchaseDefault = Schema::hasColumn('wh_sku_uoms', 'is_purchase_default');
        $hasRequestEnabled = Schema::hasColumn('wh_sku_uoms', 'is_request_enabled');
        $hasActive = Schema::hasColumn('wh_sku_uoms', 'is_active');

        $query = DB::table('wh_sku_uoms as map')
            ->join('stk_skus as sku', 'sku.id', '=', 'map.sku_id')
            ->join('stk_uoms as uom', 'uom.id', '=', 'map.uom_id')
            ->where('sku.is_active', true)
            ->where('uom.is_active', true);

        if (Schema::hasColumn('stk_skus', 'deleted_at')) {
            $query->whereNull('sku.deleted_at');
        }
        if ($hasActive) {
            $query->where('map.is_active', true);
        }

        $columns = [
            'map.sku_id', 'map.uom_id', 'map.conversion_factor',
            'uom.code as uom_code', 'uom.name as uom_name',
        ];
        if ($hasPurchaseDefault) {
            $columns[] = 'map.is_purchase_default';
        }
        if ($hasRequestEnabled) {
            $columns[] = 'map.is_request_enabled';
        }

        return $query->get($columns)->groupBy(fn (object $row): string => (string) $row->sku_id);
    }
}
