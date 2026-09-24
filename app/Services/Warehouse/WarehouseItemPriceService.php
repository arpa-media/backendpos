<?php

namespace App\Services\Warehouse;

use App\Models\Warehouse\WarehouseSku;
use Illuminate\Support\Facades\DB;

class WarehouseItemPriceService
{
    public function bands(WarehouseSku|string $sku, string $warehouseId): array
    {
        $skuModel = $sku instanceof WarehouseSku ? $sku : WarehouseSku::query()->findOrFail($sku);

        $aggregate = DB::table('stk_inventory_balances')
            ->where('outlet_id', $warehouseId)
            ->where('sku_id', $skuModel->id)
            ->first(['average_unit_cost', 'inventory_value', 'on_hand_qty']);

        $batch = DB::table('wh_batches')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuModel->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'draft', 'closed'])
            ->selectRaw('MIN(NULLIF(price_min, 0)) as min_price, MAX(NULLIF(price_max, 0)) as max_price')
            ->first();

        $avg = max(0, (float) ($aggregate->average_unit_cost ?? 0));
        $min = $skuModel->price_min !== null
            ? (float) $skuModel->price_min
            : (float) ($batch->min_price ?? $avg);
        $max = $skuModel->price_max !== null
            ? (float) $skuModel->price_max
            : (float) ($batch->max_price ?? $avg);

        if ($avg <= 0 && $min > 0 && $max > 0) {
            $avg = ($min + $max) / 2;
        }
        if ($min <= 0) {
            $min = $avg;
        }
        if ($max <= 0) {
            $max = max($avg, $min);
        }
        $min = min($min, $avg > 0 ? $avg : $min, $max);
        $max = max($max, $avg, $min);

        return [
            'MIN' => round($min, 6),
            'AVG' => round($avg, 6),
            'MAX' => round($max, 6),
            'on_hand_qty' => (float) ($aggregate->on_hand_qty ?? 0),
            'inventory_value' => (float) ($aggregate->inventory_value ?? 0),
        ];
    }

    public function resolve(WarehouseSku|string $sku, string $warehouseId, string $band, ?float $custom = null): float
    {
        $normalized = strtoupper(trim($band));
        if ($normalized === 'CUSTOM') {
            return max(0, (float) $custom);
        }

        $bands = $this->bands($sku, $warehouseId);
        return (float) ($bands[$normalized] ?? $bands['AVG']);
    }
}
