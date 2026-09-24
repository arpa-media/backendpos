<?php

namespace App\Services\Warehouse\Billing;

use App\Services\Warehouse\Pricing\WarehouseSalesPriceResolverI06;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * I06 audit boundary for downstream Sales Warehouse documents.
 *
 * Outgoing Invoice must inherit the commercial price frozen by its source
 * Sales Order / Outlet Stock Request. It must never resolve the latest master
 * price independently after the source document has moved downstream.
 */
final class WarehouseSourcePriceSnapshotI06Service
{
    /** @return array{pricing:array<string,mixed>,discount_percent:float,origin:string,business_date:?string} */
    public function resolve(string $sourceType, string $sourceItemId): array
    {
        return match ($sourceType) {
            'sales_order' => $this->fromSalesOrderItem($sourceItemId),
            'stock_request' => $this->fromStockRequestItem($sourceItemId),
            default => throw ValidationException::withMessages([
                'warehouse_price' => ["Source {$sourceType} belum memiliki kontrak snapshot harga I06."],
            ]),
        };
    }

    /** @return array{pricing:array<string,mixed>,discount_percent:float,origin:string,business_date:?string} */
    private function fromSalesOrderItem(string $itemId): array
    {
        $row = DB::table('wh_v3_sales_order_items')->where('id', $itemId)->first([
            'id','price_source','price_policy_id_snapshot','price_business_date_snapshot',
            'price_uom_id_snapshot','price_uom_code_snapshot','price_conversion_factor_snapshot',
            'price_master_snapshot','price_rule_snapshot','discount_percent',
        ]);
        if (! $row) {
            throw ValidationException::withMessages(['warehouse_price' => ['Sales Order source item tidak ditemukan untuk Outgoing Invoice.']]);
        }

        $rule = $this->json($row->price_rule_snapshot);
        $source = (string) ($row->price_source ?? '');
        $policyId = trim((string) ($row->price_policy_id_snapshot ?? ''));
        $uomId = trim((string) ($row->price_uom_id_snapshot ?? ''));
        $uomCode = trim((string) ($row->price_uom_code_snapshot ?? ''));
        $factor = round((float) ($row->price_conversion_factor_snapshot ?? 0), 8);
        $masterPrice = round((float) ($row->price_master_snapshot ?? 0), 6);

        if ($source !== WarehouseSalesPriceResolverI06::SOURCE || $policyId === '' || $uomId === '' || $factor <= 0 || $masterPrice <= 0) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Sales Order belum memiliki snapshot Harga Outlet & Customer yang lengkap. Edit/Save ulang Draft sebelum dilanjutkan ke Logistics.'],
            ]);
        }

        return [
            'pricing' => [
                'source' => WarehouseSalesPriceResolverI06::SOURCE,
                'policy_id' => $policyId,
                'price_uom_id' => $uomId,
                'price_uom_code' => $uomCode !== '' ? $uomCode : 'UNIT',
                'conversion_factor' => $factor,
                'unit_price' => $masterPrice,
                'unit_price_basis' => (string) ($rule['unit_price_basis'] ?? 'PER_PRICE_UOM'),
                'price_uom_review_required' => false,
            ],
            'discount_percent' => round((float) ($row->discount_percent ?? 0), 4),
            'origin' => 'sales_order_price_snapshot',
            'business_date' => $row->price_business_date_snapshot ? (string) $row->price_business_date_snapshot : ($rule['business_date'] ?? null),
        ];
    }

    /** @return array{pricing:array<string,mixed>,discount_percent:float,origin:string,business_date:?string} */
    private function fromStockRequestItem(string $itemId): array
    {
        $row = DB::table('stk_request_items')->where('id', $itemId)->first(['id','commercial_price_snapshot']);
        if (! $row) {
            throw ValidationException::withMessages(['warehouse_price' => ['Stock Request source item tidak ditemukan untuk Outgoing Invoice.']]);
        }

        $snapshot = $this->json($row->commercial_price_snapshot);
        $source = (string) ($snapshot['price_source'] ?? '');
        $policyId = trim((string) ($snapshot['price_reference_id'] ?? ''));
        $uomId = trim((string) ($snapshot['billing_uom_id'] ?? ''));
        $uomCode = trim((string) ($snapshot['billing_uom_code'] ?? ''));
        $factor = round((float) ($snapshot['billing_conversion_factor'] ?? 0), 8);
        $masterPrice = round((float) ($snapshot['billing_unit_price'] ?? 0), 6);

        if ($source !== WarehouseSalesPriceResolverI06::SOURCE || $policyId === '' || $uomId === '' || $factor <= 0 || $masterPrice <= 0) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Stock Request belum memiliki snapshot Harga Outlet & Customer yang lengkap. Refresh/Save ulang harga request sebelum Logistics dilanjutkan.'],
            ]);
        }

        return [
            'pricing' => [
                'source' => WarehouseSalesPriceResolverI06::SOURCE,
                'policy_id' => $policyId,
                'price_uom_id' => $uomId,
                'price_uom_code' => $uomCode !== '' ? $uomCode : 'UNIT',
                'conversion_factor' => $factor,
                'unit_price' => $masterPrice,
                'unit_price_basis' => (string) ($snapshot['unit_price_basis'] ?? 'PER_PRICE_UOM'),
                'price_uom_review_required' => false,
            ],
            'discount_percent' => 0.0,
            'origin' => 'stock_request_price_snapshot',
            'business_date' => isset($snapshot['business_date']) ? (string) $snapshot['business_date'] : null,
        ];
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
