<?php

namespace App\Services\Warehouse\Billing;

use App\Services\Purchasing\WarehouseOutletInvoiceBridgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseOutgoingInvoiceRepriceService
{
    public function __construct(
        private readonly WarehouseBillingUomService $billing,
        private readonly WarehouseSourcePriceSnapshotI06Service $sourcePriceSnapshots,
        private readonly WarehouseOutletInvoiceBridgeService $purchasingBridge,
    ) {}

    public function repriceForPolicy(string $warehouseId, string $targetType, string $targetId, string $skuId, ?string $userId = null): int
    {
        if (! in_array($targetType, ['outlet', 'customer'], true)) return 0;

        $invoiceIds = DB::table('wh_v3_outgoing_invoices as invoice')
            ->join('wh_v3_outgoing_invoice_items as item', 'item.outgoing_invoice_id', '=', 'invoice.id')
            ->where('invoice.warehouse_id', $warehouseId)
            ->where('invoice.destination_type', $targetType)
            ->where('invoice.destination_id', $targetId)
            ->where('invoice.status', 'draft')
            ->where('item.sku_id', $skuId)
            ->distinct()
            ->pluck('invoice.id');

        $count = 0;
        foreach ($invoiceIds as $invoiceId) {
            if ($this->repriceDraftInvoice((string) $invoiceId, $userId)) $count++;
        }
        return $count;
    }

    public function repriceDraftInvoice(string $invoiceId, ?string $userId = null): bool
    {
        $changed = DB::transaction(function () use ($invoiceId): bool {
            $invoice = DB::table('wh_v3_outgoing_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (! $invoice || (string) $invoice->status !== 'draft') return false;

            // ERP V5 Iteration 12 audit lock: once the Purchasing mirror has been issued/paid,
            // the Warehouse source may no longer be repriced independently.
            if (Schema::hasTable('pur_invoices') && DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
                ->where('source_document_id', $invoiceId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'DRAFT')
                ->exists()) {
                return false;
            }

            $items = DB::table('wh_v3_outgoing_invoice_items')->where('outgoing_invoice_id', $invoiceId)->lockForUpdate()->get();
            foreach ($items as $item) {
                $quantityBase = round((float) $item->billed_qty_base, 4);
                $snapshot = $this->decodeJson($item->source_snapshot);
                $sourceItemId = trim((string) ($snapshot['source_item_id'] ?? ''));
                if ($sourceItemId === '') {
                    $deliveryOrderItemId = trim((string) ($snapshot['delivery_order_item_id'] ?? ''));
                    if ($deliveryOrderItemId === '' && $item->goods_receipt_item_id) {
                        $deliveryOrderItemId = (string) DB::table('wh_v3_goods_receipt_items')->where('id', $item->goods_receipt_item_id)->value('delivery_order_item_id');
                    }
                    if ($deliveryOrderItemId !== '') {
                        $sourceItemId = trim((string) DB::table('wh_v3_delivery_order_items')->where('id', $deliveryOrderItemId)->value('source_item_id'));
                    }
                }
                if ($sourceItemId === '') {
                    throw ValidationException::withMessages(['warehouse_price' => ['Outgoing Invoice tidak memiliki source item untuk memulihkan snapshot harga dokumen asal.']]);
                }

                $sourcePrice = $this->sourcePriceSnapshots->resolve((string) $invoice->source_type, $sourceItemId);
                $pricing = $sourcePrice['pricing'];
                $rawBill = $this->billing->billBaseQuantity($quantityBase, $pricing);
                $discountPercent = round((float) $sourcePrice['discount_percent'], 4);
                $discountAmount = round((float) $rawBill['line_total'] * ($discountPercent / 100), 2);
                $bill = $rawBill;
                $bill['line_total'] = round((float) $rawBill['line_total'] - $discountAmount, 2);
                $snapshot['source_item_id'] = $sourceItemId;
                $snapshot['source_type'] = (string) $invoice->source_type;
                $snapshot['billing'] = $this->pricingSnapshot($pricing, $bill, $quantityBase, $discountPercent, $discountAmount, (string) $sourcePrice['origin'], $sourcePrice['business_date']);

                DB::table('wh_v3_outgoing_invoice_items')->where('id', $item->id)->update([
                    'billing_uom_id' => $pricing['price_uom_id'],
                    'billing_uom_code_snapshot' => $pricing['price_uom_code'],
                    'billing_conversion_factor_snapshot' => $pricing['conversion_factor'],
                    'billing_qty' => $bill['billing_qty'],
                    'unit_price' => $bill['unit_price'],
                    'unit_price_basis' => $pricing['unit_price_basis'],
                    'line_total' => $bill['line_total'],
                    'source_snapshot' => json_encode($snapshot),
                    'updated_at' => now(),
                ]);
            }

            $subtotal = round((float) DB::table('wh_v3_outgoing_invoice_items')->where('outgoing_invoice_id', $invoiceId)->sum('line_total'), 2);
            $metadata = $this->decodeJson($invoice->metadata);
            $metadata['billing_integrity_version'] = 3;
            $metadata['pricing_contract'] = 'SOURCE_DOCUMENT_SNAPSHOT_I06';
            $metadata['last_source_snapshot_resync_at'] = now()->toIso8601String();
            DB::table('wh_v3_outgoing_invoices')->where('id', $invoiceId)->update([
                'subtotal' => $subtotal,
                'grand_total' => $subtotal,
                'metadata' => json_encode($metadata),
                'updated_at' => now(),
            ]);
            return true;
        }, 5);

        if ($changed) {
            // Only Purchasing DRAFT is synchronized. Issued/paid documents are audit-locked by the bridge.
            $this->purchasingBridge->syncFromWarehouseOutgoingInvoice($invoiceId, $userId);
        }
        return $changed;
    }

    public function repriceAllDrafts(?string $warehouseId = null, ?string $userId = null): int
    {
        $query = DB::table('wh_v3_outgoing_invoices')->where('status', 'draft')->orderBy('id');
        if ($warehouseId) $query->where('warehouse_id', $warehouseId);
        $count = 0;
        foreach ($query->pluck('id') as $id) {
            if ($this->repriceDraftInvoice((string) $id, $userId)) $count++;
        }
        return $count;
    }

    private function pricingSnapshot(array $pricing, array $bill, float $quantityBase, float $discountPercent, float $discountAmount, string $origin, ?string $businessDate): array
    {
        return [
            'version' => 5,
            'pricing_source' => $pricing['source'],
            'snapshot_origin' => $origin,
            'price_business_date' => $businessDate,
            'price_policy_id' => $pricing['policy_id'],
            'price_uom_id' => $pricing['price_uom_id'],
            'price_uom_code' => $pricing['price_uom_code'],
            'conversion_factor_to_base' => $pricing['conversion_factor'],
            'quantity_base' => $quantityBase,
            'billing_qty' => $bill['billing_qty'],
            'unit_price' => $bill['unit_price'],
            'unit_price_basis' => $pricing['unit_price_basis'],
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'line_total' => $bill['line_total'],
            'price_uom_review_required' => $pricing['price_uom_review_required'],
        ];
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
