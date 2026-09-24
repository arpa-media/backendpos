<?php

namespace App\Services\Warehouse\Billing;

use App\Services\Warehouse\WarehouseItemPriceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseBillingUomService
{
    public function __construct(private readonly WarehouseItemPriceService $itemPrices) {}

    /** @return array<int, array<string, mixed>> */
    public function activeSkuCatalog(): array
    {
        $skus = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase', 'purchase.id', '=', 'sku.purchase_uom_id')
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->orderBy('sku.sku_code')
            ->get([
                'sku.id', 'sku.sku_code', 'sku.name', 'sku.base_uom_id', 'sku.purchase_uom_id', 'sku.purchase_conversion_factor',
                'base.code as base_uom_code', 'base.name as base_uom_name', 'base.symbol as base_uom_symbol',
                'purchase.code as purchase_uom_code', 'purchase.name as purchase_uom_name', 'purchase.symbol as purchase_uom_symbol',
            ]);

        $mappingBySku = collect();
        if (Schema::hasTable('wh_sku_uoms')) {
            $mappingBySku = DB::table('wh_sku_uoms as map')
                ->join('stk_uoms as uom', 'uom.id', '=', 'map.uom_id')
                ->where('map.is_active', true)
                ->where('uom.is_active', true)
                ->get(['map.sku_id', 'map.uom_id', 'map.conversion_factor', 'map.is_purchase_default', 'uom.code', 'uom.name', 'uom.symbol'])
                ->groupBy('sku_id');
        }

        return $skus->map(function ($sku) use ($mappingBySku): array {
            $uoms = [];
            $this->appendUom($uoms, (string) $sku->base_uom_id, (string) ($sku->base_uom_code ?: 'BASE'), (string) ($sku->base_uom_name ?: ''), 1.0, true, false);

            if ($sku->purchase_uom_id && (float) $sku->purchase_conversion_factor > 0) {
                $this->appendUom(
                    $uoms,
                    (string) $sku->purchase_uom_id,
                    (string) ($sku->purchase_uom_code ?: 'PURCHASE'),
                    (string) ($sku->purchase_uom_name ?: ''),
                    (float) $sku->purchase_conversion_factor,
                    (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
                    true,
                );
            }

            foreach (($mappingBySku->get($sku->id) ?? collect()) as $map) {
                $this->appendUom(
                    $uoms,
                    (string) $map->uom_id,
                    (string) $map->code,
                    (string) $map->name,
                    (float) $map->conversion_factor,
                    (string) $map->uom_id === (string) $sku->base_uom_id,
                    (bool) $map->is_purchase_default,
                );
            }

            $uoms = array_values($uoms);
            usort($uoms, fn (array $a, array $b): int => ($b['is_purchase_default'] <=> $a['is_purchase_default']) ?: ($a['is_base'] <=> $b['is_base']) ?: strcmp($a['code'], $b['code']));
            $recommended = collect($uoms)->firstWhere('is_purchase_default', true) ?? collect($uoms)->firstWhere('is_base', true) ?? ($uoms[0] ?? null);

            return [
                'id' => (string) $sku->id,
                'sku_code' => (string) $sku->sku_code,
                'name' => (string) $sku->name,
                'base_uom_id' => (string) $sku->base_uom_id,
                'base_uom_code' => (string) ($sku->base_uom_code ?: 'BASE'),
                'recommended_price_uom_id' => $recommended['id'] ?? (string) $sku->base_uom_id,
                'uoms' => $uoms,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function resolveForSku(string $skuId, ?string $uomId): array
    {
        $sku = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase', 'purchase.id', '=', 'sku.purchase_uom_id')
            ->where('sku.id', $skuId)
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->first([
                'sku.id', 'sku.sku_code', 'sku.name', 'sku.base_uom_id', 'sku.purchase_uom_id', 'sku.purchase_conversion_factor',
                'base.code as base_code', 'base.name as base_name', 'purchase.code as purchase_code', 'purchase.name as purchase_name',
            ]);
        if (! $sku) throw ValidationException::withMessages(['sku_id' => ['SKU aktif tidak ditemukan.']]);

        $wanted = trim((string) ($uomId ?: $sku->base_uom_id));
        if ($wanted === (string) $sku->base_uom_id) {
            return $this->resolvedUom($sku, (string) $sku->base_uom_id, (string) ($sku->base_code ?: 'BASE'), (string) ($sku->base_name ?: ''), 1.0, true, false);
        }

        if ($sku->purchase_uom_id && $wanted === (string) $sku->purchase_uom_id && (float) $sku->purchase_conversion_factor > 0) {
            return $this->resolvedUom($sku, $wanted, (string) ($sku->purchase_code ?: 'PURCHASE'), (string) ($sku->purchase_name ?: ''), (float) $sku->purchase_conversion_factor, false, true);
        }

        if (Schema::hasTable('wh_sku_uoms')) {
            $mapping = DB::table('wh_sku_uoms as map')
                ->join('stk_uoms as uom', 'uom.id', '=', 'map.uom_id')
                ->where('map.sku_id', $skuId)
                ->where('map.uom_id', $wanted)
                ->where('map.is_active', true)
                ->where('uom.is_active', true)
                ->first(['map.uom_id', 'map.conversion_factor', 'map.is_purchase_default', 'uom.code', 'uom.name']);
            if ($mapping && (float) $mapping->conversion_factor > 0) {
                return $this->resolvedUom($sku, (string) $mapping->uom_id, (string) $mapping->code, (string) $mapping->name, (float) $mapping->conversion_factor, false, (bool) $mapping->is_purchase_default);
            }
        }

        throw ValidationException::withMessages(['price_uom_id' => ['UOM harga tidak memiliki jalur konversi aktif ke Base UOM SKU.']]);
    }

    /** @return array<string, mixed> */
    public function resolveByCodeForSku(string $skuId, string $uomCode): array
    {
        $code = mb_strtoupper(trim($uomCode));
        foreach ($this->activeSkuCatalog() as $sku) {
            if ((string) $sku['id'] !== $skuId) continue;
            foreach ($sku['uoms'] as $uom) {
                if (mb_strtoupper((string) $uom['code']) === $code) return $this->resolveForSku($skuId, (string) $uom['id']);
            }
            break;
        }
        throw ValidationException::withMessages(['price_uom' => ["UOM harga {$uomCode} tidak valid untuk SKU."]]);
    }

    /** @return array<string, mixed> */
    public function pricingForDestination(string $warehouseId, string $destinationType, string $destinationId, string $skuId, mixed $businessDate = null, bool $requireReviewed = false): array
    {
        $date = $businessDate ? Carbon::parse($businessDate)->toDateString() : now('Asia/Jakarta')->toDateString();
        $targetType = strtolower(trim($destinationType));

        if (Schema::hasTable('wh_price_policies_v3') && in_array($targetType, ['outlet', 'customer'], true)) {
            $policy = DB::table('wh_price_policies_v3')
                ->where('warehouse_id', $warehouseId)
                ->where('target_type', $targetType)
                ->where('target_id', $destinationId)
                ->where('sku_id', $skuId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->orderByDesc('effective_from')
                ->orderByDesc('updated_at')
                ->first();

            if ($policy) {
                $uom = $this->resolveForSku($skuId, $policy->price_uom_id ?: null);
                if ($requireReviewed && (bool) ($policy->price_uom_review_required ?? false)) {
                    throw ValidationException::withMessages([
                        'price_uom' => [sprintf(
                            'UOM Harga SKU %s belum direview. Buka Warehouse → Stock → Harga Outlet & Customer, pilih UOM harga yang benar, lalu ulangi Complete Goods Receipt.',
                            (string) ($uom['sku_code'] ?? $skuId),
                        )],
                    ]);
                }
                return [
                    'source' => 'WH_PRICE_POLICIES_V3',
                    'policy_id' => (string) $policy->id,
                    'unit_price' => round((float) $policy->price, 6),
                    'unit_price_basis' => (string) ($policy->price_basis ?: 'PER_PRICE_UOM'),
                    'price_uom_review_required' => (bool) ($policy->price_uom_review_required ?? false),
                    ...$uom,
                ];
            }
        }

        // Transitional fallback only when canonical v3 policy does not exist.
        $legacyTable = $targetType === 'outlet' ? 'wh_outlet_price_policies' : ($targetType === 'customer' ? 'wh_customer_price_policies' : null);
        $targetColumn = $targetType === 'outlet' ? 'outlet_id' : 'customer_id';
        if ($legacyTable && Schema::hasTable($legacyTable)) {
            $policy = DB::table($legacyTable)
                ->where('warehouse_id', $warehouseId)
                ->where($targetColumn, $destinationId)
                ->where('sku_id', $skuId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->first();
            if ($policy) {
                $uom = $this->resolveForSku($skuId, null);
                return [
                    'source' => 'LEGACY_PRICE_POLICY_FALLBACK',
                    'policy_id' => (string) $policy->id,
                    'unit_price' => round($this->itemPrices->resolve($skuId, $warehouseId, (string) ($policy->price_band ?: 'AVG'), $policy->custom_price !== null ? (float) $policy->custom_price : null), 6),
                    'unit_price_basis' => 'PER_PRICE_UOM',
                    'price_uom_review_required' => false,
                    ...$uom,
                ];
            }
        }

        $uom = $this->resolveForSku($skuId, null);
        return [
            'source' => 'WAREHOUSE_AVG_FALLBACK',
            'policy_id' => null,
            'unit_price' => round($this->itemPrices->resolve($skuId, $warehouseId, 'AVG'), 6),
            'unit_price_basis' => 'PER_PRICE_UOM',
            'price_uom_review_required' => false,
            ...$uom,
        ];
    }

    /** @param array<string, mixed> $pricing @return array<string, float> */
    public function billBaseQuantity(float $quantityBase, array $pricing): array
    {
        $factor = max((float) ($pricing['conversion_factor'] ?? 1), 0.00000001);
        $billingQty = round($quantityBase / $factor, 4);
        $unitPrice = round((float) ($pricing['unit_price'] ?? 0), 6);
        return [
            'billing_qty' => $billingQty,
            'unit_price' => $unitPrice,
            'line_total' => round($billingQty * $unitPrice, 2),
        ];
    }

    private function appendUom(array &$uoms, string $id, string $code, string $name, float $factor, bool $isBase, bool $isPurchaseDefault): void
    {
        if ($id === '' || $factor <= 0) return;
        $current = $uoms[$id] ?? null;
        $uoms[$id] = [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'conversion_factor' => round($isBase ? 1.0 : $factor, 8),
            'is_base' => $isBase,
            'is_purchase_default' => $isPurchaseDefault || (bool) ($current['is_purchase_default'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function resolvedUom(object $sku, string $id, string $code, string $name, float $factor, bool $isBase, bool $isPurchaseDefault): array
    {
        return [
            'sku_id' => (string) $sku->id,
            'sku_code' => (string) $sku->sku_code,
            'base_uom_id' => (string) $sku->base_uom_id,
            'price_uom_id' => $id,
            'price_uom_code' => $code,
            'price_uom_name' => $name,
            'conversion_factor' => round($isBase ? 1.0 : $factor, 8),
            'is_base_uom' => $isBase,
            'is_purchase_default' => $isPurchaseDefault,
        ];
    }
}
