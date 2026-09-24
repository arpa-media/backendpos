<?php

namespace App\Services\Warehouse\Pricing;

use App\Services\Warehouse\WarehouseItemPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseProductionPriceBandResolverI06
{
    public const SOURCE = 'PRODUCTION_PRICE_BAND';
    public const CONTRACT_VERSION = 6;

    public function __construct(private readonly WarehouseItemPriceService $prices) {}

    /** @return array<string,mixed> */
    public function bands(string $warehouseId, string $skuId): array
    {
        $sku = DB::table('stk_skus')->where('id', $skuId)->where('is_active', true)->whereNull('deleted_at')->first(['id','sku_code','name','base_uom_id']);
        if (! $sku) throw ValidationException::withMessages(['sku_id' => ['SKU aktif tidak ditemukan.']]);
        $bands = $this->prices->bands($skuId, $warehouseId);
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => self::SOURCE,
            'warehouse_id' => $warehouseId,
            'sku_id' => (string) $sku->id,
            'sku_code' => (string) $sku->sku_code,
            'item_name' => (string) $sku->name,
            'base_uom_id' => (string) $sku->base_uom_id,
            'basis' => 'PER_BASE_UOM',
            'bands' => [
                'MIN' => round((float) $bands['MIN'], 6),
                'AVG' => round((float) $bands['AVG'], 6),
                'MAX' => round((float) $bands['MAX'], 6),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function resolve(string $warehouseId, string $skuId, string $band): array
    {
        $snapshot = $this->bands($warehouseId, $skuId);
        $selected = strtoupper(trim($band));
        if (! in_array($selected, ['MIN','AVG','MAX'], true)) {
            throw ValidationException::withMessages(['selected_price_band' => ['Price Band Production harus MIN, AVG, atau MAX.']]);
        }
        $price = round((float) $snapshot['bands'][$selected], 6);
        if ($price <= 0) {
            throw ValidationException::withMessages([
                'selected_price_band' => [sprintf('Price Band %s untuk SKU %s belum memiliki nilai positif.', $selected, $snapshot['sku_code'])],
            ]);
        }
        return $snapshot + [
            'selected_band' => $selected,
            'selected_price' => $price,
            'resolved_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }
}
