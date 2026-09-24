<?php

namespace App\Services\Warehouse\TransferStockV4;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone bulk SKU/UOM catalog for Transfer Stock v4.
 *
 * Intentionally avoids WarehouseTransactionUomService::catalog() per SKU to
 * prevent the N+1 options timeout while keeping Iteration 03 self-contained.
 */
class WarehouseTransferSkuCatalogService
{
    public function forWarehouse(string $warehouseId): array
    {
        $query = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase', 'purchase.id', '=', 'sku.purchase_uom_id')
            ->leftJoin('stk_inventory_balances as bal', function ($join) use ($warehouseId): void {
                $join->on('bal.sku_id', '=', 'sku.id')->where('bal.outlet_id', '=', $warehouseId);
            })
            ->where('sku.is_active', true);

        if (Schema::hasColumn('stk_skus', 'deleted_at')) {
            $query->whereNull('sku.deleted_at');
        }

        $skus = $query->orderBy('sku.name')->get([
            'sku.id', 'sku.sku_code', 'sku.name', 'sku.base_uom_id', 'sku.purchase_uom_id',
            'sku.purchase_conversion_factor', 'base.code as base_code', 'base.name as base_name',
            'purchase.code as purchase_code', 'purchase.name as purchase_name',
            'bal.on_hand_qty', 'bal.average_unit_cost', 'bal.inventory_value',
        ]);

        if ($skus->isEmpty()) return [];

        $mappings = $this->activeMappings();

        return $skus->map(function (object $sku) use ($mappings): array {
            $skuId = (string) $sku->id;
            $baseId = (string) $sku->base_uom_id;
            $purchaseId = $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : null;

            $base = [
                'id'=>$baseId,'code'=>(string)($sku->base_code ?: 'UNIT'),'name'=>(string)($sku->base_name ?: 'Unit'),
                'conversion_factor'=>1.0,'is_base'=>true,'is_purchase_default'=>$purchaseId === $baseId,'is_request_enabled'=>true,
            ];
            $byId = [$baseId => $base];

            foreach ($mappings->get($skuId, collect()) as $row) {
                $factor = round((float)$row->conversion_factor, 8);
                if ($factor <= 0) continue;
                $id = (string)$row->uom_id;
                $byId[$id] = [
                    'id'=>$id,'code'=>(string)$row->uom_code,'name'=>(string)($row->uom_name ?: $row->uom_code ?: 'Unit'),
                    'conversion_factor'=>$id === $baseId ? 1.0 : $factor,'is_base'=>$id === $baseId,
                    'is_purchase_default'=>property_exists($row,'is_purchase_default') ? (bool)$row->is_purchase_default : $purchaseId === $id,
                    'is_request_enabled'=>property_exists($row,'is_request_enabled') ? (bool)$row->is_request_enabled : true,
                ];
            }

            if ($purchaseId && $sku->purchase_code && (float)$sku->purchase_conversion_factor > 0) {
                $existing = $byId[$purchaseId] ?? null;
                $byId[$purchaseId] = [
                    'id'=>$purchaseId,'code'=>(string)$sku->purchase_code,'name'=>(string)($sku->purchase_name ?: $sku->purchase_code),
                    'conversion_factor'=>$purchaseId === $baseId ? 1.0 : round((float)$sku->purchase_conversion_factor,8),
                    'is_base'=>$purchaseId === $baseId,'is_purchase_default'=>true,
                    'is_request_enabled'=>$existing ? (bool)$existing['is_request_enabled'] : true,
                ];
            }
            $byId[$baseId] = array_merge($byId[$baseId] ?? [], $base);
            $uoms = array_values($byId);
            usort($uoms, static function (array $a,array $b): int {
                $ra=$a['is_purchase_default']?0:($a['is_base']?1:2);$rb=$b['is_purchase_default']?0:($b['is_base']?1:2);
                return $ra === $rb ? strcmp((string)$a['code'],(string)$b['code']) : $ra <=> $rb;
            });
            $enabled = array_values(array_filter($uoms, static fn(array $u): bool => (bool)$u['is_request_enabled']));
            $default = collect($enabled)->firstWhere('is_purchase_default', true) ?? ($enabled[0] ?? $base);

            return [
                'id'=>$skuId,'sku_code'=>(string)$sku->sku_code,'name'=>(string)$sku->name,
                'base_uom'=>$base,'purchase_uom'=>collect($uoms)->firstWhere('is_purchase_default',true),
                'default_request_uom_id'=>(string)$default['id'],'uoms'=>$uoms,
                'on_hand_qty'=>(float)($sku->on_hand_qty ?? 0),'average_unit_cost'=>(float)($sku->average_unit_cost ?? 0),
                'inventory_value'=>(float)($sku->inventory_value ?? 0),
            ];
        })->values()->all();
    }

    private function activeMappings(): Collection
    {
        if (!Schema::hasTable('wh_sku_uoms') || !Schema::hasColumn('wh_sku_uoms','sku_id') || !Schema::hasColumn('wh_sku_uoms','uom_id') || !Schema::hasColumn('wh_sku_uoms','conversion_factor')) {
            return collect();
        }
        $q = DB::table('wh_sku_uoms as m')->join('stk_uoms as u','u.id','=','m.uom_id')->where('u.is_active',true);
        if (Schema::hasColumn('wh_sku_uoms','is_active')) $q->where('m.is_active',true);
        $cols=['m.sku_id','m.uom_id','m.conversion_factor','u.code as uom_code','u.name as uom_name'];
        if (Schema::hasColumn('wh_sku_uoms','is_purchase_default')) $cols[]='m.is_purchase_default';
        if (Schema::hasColumn('wh_sku_uoms','is_request_enabled')) $cols[]='m.is_request_enabled';
        return $q->get($cols)->groupBy(fn(object $r): string => (string)$r->sku_id);
    }
}
