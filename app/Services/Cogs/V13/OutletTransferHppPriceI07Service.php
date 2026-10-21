<?php

namespace App\Services\Cogs\V13;

use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * I07: Read-only current price for OUTLET recipe estimates.
 * Do not use for warehouse acquisition valuation, historical COGS, or GL posting.
 * Uses the SAME canonical Warehouse -> Outlet price policy as Stock Request I06.
 */
final class OutletTransferHppPriceI07Service
{
    public function __construct(private readonly WarehouseBillingUomService $billing) {}

    /**
     * @param list<string> $skuIds
     * @return array<string,array<string,mixed>>
     */
    public function forOutlet(string $outletId, array $skuIds, string $date): array
    {
        $skuIds = array_values(array_unique(array_filter(array_map('strval', $skuIds))));
        if ($skuIds === []) return [];

        // A chain supply explicitly identifies the Warehouse supplying this outlet.
        // No supply mapping => no inferred Warehouse transfer pricing.
        if (!Schema::hasTable('wh_chain_supplies')) return $this->problems($skuIds, 'missing_chain_supply_schema', null);
        $chains = DB::table('wh_chain_supplies')
            ->where('outlet_id', $outletId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->get(['warehouse_id']);
        if ($chains->isEmpty()) return [];

        $warehouseIds = $chains->pluck('warehouse_id')->unique()->values()->all();
        if (count($warehouseIds) !== 1) {
            return $this->problems($skuIds, 'ambiguous_warehouse', null);
        }
        $warehouseId = (string) $warehouseIds[0];
        $warehouseCode = Schema::hasTable('outlets')
            ? (string) (DB::table('outlets')->where('id', $warehouseId)->value('code') ?? '') : '';
        if (!Schema::hasTable('wh_price_policies_v3')) {
            return $this->problems($skuIds, 'missing_price_master', $warehouseId);
        }

        $policies = DB::table('wh_price_policies_v3')
            ->where('warehouse_id', $warehouseId)
            ->where('target_type', 'outlet')
            ->where('target_id', $outletId)
            ->whereIn('sku_id', $skuIds)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('updated_at')
            ->get(['id','sku_id','price','price_uom_id','price_basis','price_uom_review_required','price_conversion_factor_snapshot','price_uom_code_snapshot']);

        $found = [];
        foreach ($policies as $policy) {
            $skuId = (string) $policy->sku_id;
            if (isset($found[$skuId])) continue;
            $meta = ['warehouse_id'=>$warehouseId, 'warehouse_code'=>$warehouseCode, 'price_policy_id'=>(string)$policy->id,
                'price_uom_code'=>($policy->price_uom_code_snapshot ?: null),
                'master_unit_price'=>(float)$policy->price,
                'price_conversion_factor'=>null,
                'price_source'=>'warehouse_transfer_policy', 'price_issue'=>null,
                'pricing_unit_cost'=>null];
            $price = (float) $policy->price;
            if ((bool)$policy->price_uom_review_required) {
                $meta['price_issue']='price_uom_needs_review';
            } elseif ($price <= 0) {
                $meta['price_issue']='non_positive_warehouse_price';
            } elseif (strtoupper((string) ($policy->price_basis ?: 'PER_PRICE_UOM')) !== 'PER_PRICE_UOM') {
                $meta['price_issue']='unsupported_price_basis';
            } else {
                try {
                    // Same UOM graph used by Warehouse stock-request and billing paths;
                    // no guessing PAX from a base-gram price.
                    $uom = $this->billing->resolveForSku($skuId, $policy->price_uom_id ?: null);
                    $factor = (float) $uom['conversion_factor'];
                    if ($factor <= 0) {
                        $meta['price_issue']='invalid_price_uom';
                    } else {
                        $meta['price_uom_code']=(string)$uom['price_uom_code'];
                        $meta['price_conversion_factor']=$factor;
                        $meta['pricing_unit_cost']=self::perBaseUnit($price, $factor);
                    }
                } catch (ValidationException $exception) {
                    $meta['price_issue']='invalid_price_uom';
                }
            }
            $found[$skuId]=$meta;
        }
        foreach ($skuIds as $skuId) {
            if (!isset($found[$skuId])) $found[$skuId] = array_merge($this->missing('missing_warehouse_price', $warehouseId), ['warehouse_code'=>$warehouseCode]);
        }
        return $found;
    }

    public static function perBaseUnit(float $pricePerPriceUom, float $baseUnitsPerPriceUom): ?float
    {
        if (!is_finite($pricePerPriceUom) || !is_finite($baseUnitsPerPriceUom)
            || $pricePerPriceUom <= 0 || $baseUnitsPerPriceUom <= 0) return null;
        return round($pricePerPriceUom / $baseUnitsPerPriceUom, 8);
    }

    private function problems(array $ids, string $issue, ?string $warehouseId): array
    {
        $result=[];
        foreach ($ids as $id) $result[$id]=$this->missing($issue, $warehouseId);
        return $result;
    }

    private function missing(string $issue, ?string $warehouseId): array
    {
        return ['warehouse_id'=>$warehouseId, 'warehouse_code'=>null, 'price_policy_id'=>null,'price_uom_code'=>null,
            'master_unit_price'=>null,'price_conversion_factor'=>null,
            'price_source'=>'warehouse_transfer_policy','price_issue'=>$issue,'pricing_unit_cost'=>null];
    }
}
