<?php

namespace App\Services\Purchasing;

use App\Models\Warehouse\WarehouseStockRequest;
use App\Models\Warehouse\WarehouseStockRequestItem;
use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use App\Services\Warehouse\Pricing\WarehouseSalesPriceResolverI06;
use Illuminate\Validation\ValidationException;

final class WarehouseCommercialPriceService
{
    public function __construct(
        private readonly WarehouseBillingUomService $billing,
        private readonly WarehouseSalesPriceResolverI06 $strictSalesPricing,
    )
    {
    }

    /**
     * Canonical commercial pricing contract for an Outlet Stock Request.
     *
     * Fulfillment quantities remain in Base UOM, while the commercial snapshot
     * mirrors the exact UOM/price contract later used by Warehouse Outgoing Invoice.
     *
     * @return array<string,mixed>
     */
    public function resolveForStockRequest(
        WarehouseStockRequest $request,
        WarehouseStockRequestItem $item,
        ?float $quantityBase = null,
        bool $requireReviewed = true,
    ): array {
        $warehouseId = trim((string) $request->destination_warehouse_id);
        $outletId = trim((string) $request->outlet_id);
        $qtyBase = round($quantityBase ?? (float) ($item->requested_qty_base ?: $item->requested_qty), 4);
        $businessDate = $request->request_date?->toDateString() ?: now('Asia/Jakarta')->toDateString();

        return $this->resolve(
            $warehouseId,
            $outletId,
            (string) $item->sku_id,
            $qtyBase,
            $businessDate,
            $requireReviewed,
        );
    }

    /** @return array<string,mixed> */
    public function resolve(
        string $warehouseId,
        string $outletId,
        string $skuId,
        float $quantityBase,
        mixed $businessDate = null,
        bool $requireReviewed = true,
    ): array {
        $warehouseId = trim($warehouseId);
        $outletId = trim($outletId);
        if ($warehouseId === '' || $outletId === '') {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Stock Request belum memiliki Warehouse tujuan dan Outlet yang valid untuk menentukan harga jual.'],
            ]);
        }

        $qtyBase = round($quantityBase, 4);
        if ($qtyBase <= 0) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Qty Base Stock Request harus lebih besar dari nol untuk menentukan harga jual Warehouse.'],
            ]);
        }

        $date = $businessDate ?: now('Asia/Jakarta')->toDateString();
        $pricing = $this->strictSalesPricing->resolve(
            $warehouseId,
            'outlet',
            $outletId,
            $skuId,
            $date,
            $requireReviewed,
        );
        $bill = $this->billing->billBaseQuantity($qtyBase, $pricing);

        if ((float) $bill['unit_price'] <= 0 || (float) $bill['line_total'] <= 0) {
            throw ValidationException::withMessages([
                'warehouse_price' => [sprintf(
                    'Harga jual Warehouse untuk SKU %s belum tersedia atau bernilai 0. Atur harga pada Warehouse → Stock → Harga Outlet & Customer.',
                    (string) ($pricing['sku_code'] ?? $skuId),
                )],
            ]);
        }

        $baseUnitPrice = round((float) $bill['line_total'] / $qtyBase, 6);

        return [
            'version' => 1,
            'price_source' => (string) $pricing['source'],
            'price_reference_id' => $pricing['policy_id'] ? (string) $pricing['policy_id'] : null,
            'warehouse_id' => $warehouseId,
            'destination_type' => 'outlet',
            'destination_id' => $outletId,
            'business_date' => \Illuminate\Support\Carbon::parse($date)->toDateString(),
            'sku_id' => $skuId,
            'quantity_base' => $qtyBase,
            'base_uom_id' => $pricing['base_uom_id'] ? (string) $pricing['base_uom_id'] : null,
            'billing_uom_id' => (string) $pricing['price_uom_id'],
            'billing_uom_code' => (string) $pricing['price_uom_code'],
            'billing_uom_name' => (string) ($pricing['price_uom_name'] ?? ''),
            'billing_conversion_factor' => round((float) $pricing['conversion_factor'], 8),
            'billing_qty' => round((float) $bill['billing_qty'], 4),
            'billing_unit_price' => round((float) $bill['unit_price'], 2),
            'unit_price_basis' => (string) $pricing['unit_price_basis'],
            'line_total' => round((float) $bill['line_total'], 2),
            'base_equivalent_unit_price' => $baseUnitPrice,
            'price_uom_review_required' => (bool) ($pricing['price_uom_review_required'] ?? false),
        ];
    }
}
