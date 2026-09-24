<?php

namespace App\Services\Warehouse\Pricing;

use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WarehouseSalesPriceResolverI06
{
    public const SOURCE = 'WH_PRICE_POLICIES_V3';
    public const CONTRACT_VERSION = 6;

    public function __construct(private readonly WarehouseBillingUomService $billing) {}

    /**
     * Strict Warehouse sales pricing contract.
     *
     * The resolver intentionally has NO fallback to inventory valuation, SKU price
     * bands, legacy price policies, or browser supplied values. Sales Warehouse
     * prices must come from Warehouse -> Stock -> Harga Outlet & Customer.
     *
     * @return array<string,mixed>
     */
    public function resolve(
        string $warehouseId,
        string $targetType,
        string $targetId,
        string $skuId,
        mixed $businessDate = null,
        bool $requireReviewed = true,
    ): array {
        $warehouseId = trim($warehouseId);
        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        $skuId = trim($skuId);
        $date = Carbon::parse($businessDate ?: now('Asia/Jakarta'))->toDateString();

        if (! in_array($targetType, ['outlet', 'customer'], true)) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Target harga Sales Warehouse harus Outlet atau Customer.'],
            ]);
        }
        if ($warehouseId === '' || $targetId === '' || $skuId === '') {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Warehouse, target, dan SKU wajib tersedia untuk menentukan harga Sales Warehouse.'],
            ]);
        }
        if (! Schema::hasTable('wh_price_policies_v3')) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Master Harga Outlet & Customer belum tersedia. Jalankan migration Warehouse terlebih dahulu.'],
            ]);
        }

        $policy = DB::table('wh_price_policies_v3')
            ->where('warehouse_id', $warehouseId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('sku_id', $skuId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at')
            ->first();

        if (! $policy) {
            throw ValidationException::withMessages([
                'warehouse_price' => [sprintf(
                    'Harga Sales Warehouse untuk SKU %s dan %s terpilih belum tersedia/aktif pada %s. Atur di Warehouse → Stock → Harga Outlet & Customer.',
                    $skuId,
                    $targetType === 'outlet' ? 'Outlet' : 'Customer',
                    $date,
                )],
            ]);
        }

        $uom = $this->billing->resolveForSku($skuId, $policy->price_uom_id ?: null);
        if ($requireReviewed && (bool) ($policy->price_uom_review_required ?? false)) {
            throw ValidationException::withMessages([
                'warehouse_price' => [sprintf(
                    'UOM Harga SKU %s masih membutuhkan review. Perbaiki di Warehouse → Stock → Harga Outlet & Customer.',
                    (string) ($uom['sku_code'] ?? $skuId),
                )],
            ]);
        }

        $price = round((float) $policy->price, 6);
        if ($price <= 0) {
            throw ValidationException::withMessages([
                'warehouse_price' => [sprintf(
                    'Harga Sales Warehouse SKU %s bernilai 0. Isi harga positif di Warehouse → Stock → Harga Outlet & Customer.',
                    (string) ($uom['sku_code'] ?? $skuId),
                )],
            ]);
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => self::SOURCE,
            'policy_id' => (string) $policy->id,
            'warehouse_id' => $warehouseId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'business_date' => $date,
            'unit_price' => $price,
            'unit_price_basis' => (string) ($policy->price_basis ?: 'PER_PRICE_UOM'),
            'price_uom_review_required' => (bool) ($policy->price_uom_review_required ?? false),
            'effective_from' => $policy->effective_from,
            'effective_to' => $policy->effective_to,
            ...$uom,
        ];
    }

    /** @return array<string,mixed> */
    public function resolveForTransaction(
        string $warehouseId,
        string $targetType,
        string $targetId,
        string $skuId,
        string $transactionUomId,
        float $quantityTransactionUom,
        mixed $businessDate = null,
        bool $requireReviewed = true,
    ): array {
        if ($quantityTransactionUom <= 0) {
            throw ValidationException::withMessages(['warehouse_price' => ['Qty transaksi harus lebih besar dari nol.']]);
        }

        $transactionUom = $this->billing->resolveForSku($skuId, $transactionUomId);
        $quantityBase = round($quantityTransactionUom * (float) $transactionUom['conversion_factor'], 4);
        $pricing = $this->resolve($warehouseId, $targetType, $targetId, $skuId, $businessDate, $requireReviewed);
        $bill = $this->billing->billBaseQuantity($quantityBase, $pricing);
        $transactionUnitPrice = round((float) $bill['line_total'] / $quantityTransactionUom, 6);

        return $pricing + [
            'transaction_uom_id' => (string) $transactionUom['price_uom_id'],
            'transaction_uom_code' => (string) $transactionUom['price_uom_code'],
            'transaction_conversion_factor' => round((float) $transactionUom['conversion_factor'], 8),
            'quantity_transaction_uom' => round($quantityTransactionUom, 4),
            'quantity_base' => $quantityBase,
            'billing_qty' => round((float) $bill['billing_qty'], 4),
            'line_total_before_discount' => round((float) $bill['line_total'], 2),
            'transaction_unit_price' => $transactionUnitPrice,
        ];
    }

    /** @param array<string,mixed> $pricing @return array<string,mixed> */
    public function snapshot(array $pricing): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => self::SOURCE,
            'policy_id' => $pricing['policy_id'] ?? null,
            'warehouse_id' => $pricing['warehouse_id'] ?? null,
            'target_type' => $pricing['target_type'] ?? null,
            'target_id' => $pricing['target_id'] ?? null,
            'business_date' => $pricing['business_date'] ?? null,
            'sku_id' => $pricing['sku_id'] ?? null,
            'sku_code' => $pricing['sku_code'] ?? null,
            'price_uom_id' => $pricing['price_uom_id'] ?? null,
            'price_uom_code' => $pricing['price_uom_code'] ?? null,
            'price_conversion_factor' => round((float) ($pricing['conversion_factor'] ?? 1), 8),
            'master_price' => round((float) ($pricing['unit_price'] ?? 0), 6),
            'unit_price_basis' => $pricing['unit_price_basis'] ?? 'PER_PRICE_UOM',
            'transaction_uom_id' => $pricing['transaction_uom_id'] ?? null,
            'transaction_uom_code' => $pricing['transaction_uom_code'] ?? null,
            'transaction_conversion_factor' => round((float) ($pricing['transaction_conversion_factor'] ?? 1), 8),
            'transaction_unit_price' => round((float) ($pricing['transaction_unit_price'] ?? 0), 6),
            'effective_from' => $pricing['effective_from'] ?? null,
            'effective_to' => $pricing['effective_to'] ?? null,
            'resolved_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }
}
