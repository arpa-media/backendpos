<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StockRequestReceiptInvoiceBridgeService
{
    public function __construct(private readonly InvoiceWorkflowService $invoiceWorkflow)
    {
    }

    /**
     * Membuat Purchasing GR DRAFT dari GR outlet hasil Stock Request.
     * Method idempotent berdasarkan external_reference STOCK_GR:{id}.
     *
     * @return array<string,mixed>|null
     */
    public function syncFromOutletGoodsReceipt(string $stockGoodsReceiptId, $actor): ?array
    {
        if (! Schema::hasTable('stk_goods_receipts')
            || ! Schema::hasTable('stk_goods_receipt_items')
            || ! Schema::hasTable('pur_goods_receipts')
            || ! Schema::hasTable('pur_goods_receipt_items')) {
            return null;
        }

        $source = DB::table('stk_goods_receipts')->where('id', $stockGoodsReceiptId)->first();
        if (! $source || (string) ($source->receipt_type ?? '') !== 'stock_request') {
            return null;
        }

        $orderId = trim((string) ($source->purchase_order_id ?? ''));
        if ($orderId === '') {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['GR outlet tidak memiliki Purchase Order Stock canonical.'],
            ]);
        }

        $externalReference = 'STOCK_GR:' . $stockGoodsReceiptId;
        $existing = DB::table('pur_goods_receipts')
            ->where(function ($query) use ($externalReference, $orderId): void {
                $query->where('external_reference', $externalReference)
                    ->orWhere(function ($nested) use ($orderId): void {
                        $nested->where('order_kind', 'PURCHASE_ORDER')->where('order_id', $orderId);
                    });
            })
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return ['id' => (string) $existing->id, 'number' => (string) $existing->gr_number, 'status' => (string) $existing->status];
        }

        return DB::transaction(function () use ($source, $orderId, $stockGoodsReceiptId, $externalReference, $actor): array {
            $order = DB::table('pur_purchase_orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['purchase_order_id' => ['Purchase Order Stock tidak ditemukan.']]);
            }

            $duplicate = DB::table('pur_goods_receipts')
                ->where('order_kind', 'PURCHASE_ORDER')
                ->where('order_id', $orderId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($duplicate) {
                return ['id' => (string) $duplicate->id, 'number' => (string) $duplicate->gr_number, 'status' => (string) $duplicate->status];
            }

            $id = (string) Str::ulid();
            $number = $this->nextNumber();
            $now = now();
            DB::table('pur_goods_receipts')->insert([
                'id' => $id,
                'gr_number' => $number,
                'order_kind' => 'PURCHASE_ORDER',
                'order_id' => $orderId,
                'fund_request_id' => $order->fund_request_id ?? null,
                'outlet_id' => $source->outlet_id ?? ($order->outlet_id ?? null),
                'chamber_code' => $order->chamber_code ?? 'OUTLET',
                'document_date' => $source->receipt_date ?? $now->toDateString(),
                'status' => 'DRAFT',
                'currency' => $source->currency ?? ($order->currency ?? 'IDR'),
                'subtotal' => round((float) ($source->total_amount ?? 0), 2),
                'tax_amount' => 0,
                'total_amount' => round((float) ($source->total_amount ?? 0), 2),
                'external_reference' => $externalReference,
                'notes' => 'Otomatis dari Outlet GR ' . ($source->gr_number ?? $stockGoodsReceiptId) . '. Menunggu approval Finance.',
                'lock_version' => 1,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sourceItems = DB::table('stk_goods_receipt_items as s')
                ->leftJoin('stk_skus as sku', 'sku.id', '=', 's.sku_id')
                ->where('s.goods_receipt_id', $stockGoodsReceiptId)
                ->orderBy('s.created_at')
                ->get([
                    's.*',
                    DB::raw("COALESCE(s.sku_name_snapshot, sku.name, s.sku_code_snapshot, 'Item') as resolved_item_name"),
                ]);

            $lineNo = 0;
            foreach ($sourceItems as $item) {
                $lineNo++;
                $qty = round((float) ($item->received_qty ?: $item->accepted_qty ?: 0), 4);
                $price = round((float) ($item->unit_cost ?? 0), 2);
                $lineTotal = round((float) ($item->line_total ?: ($qty * $price)), 2);
                DB::table('pur_goods_receipt_items')->insert([
                    'id' => (string) Str::ulid(),
                    'document_id' => $id,
                    'order_item_id' => $item->purchase_order_item_id ?? null,
                    'line_no' => $lineNo,
                    'sku_id' => $item->sku_id,
                    'item_name' => trim((string) $item->resolved_item_name) ?: 'Item',
                    'uom_text' => $item->uom_snapshot ?: 'UNIT',
                    'ordered_qty' => round((float) ($item->ordered_qty ?: $qty), 4),
                    'executed_qty' => $qty,
                    'unit_price' => $price,
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                    'notes' => $item->notes,
                    'metadata' => json_encode(['stock_goods_receipt_item_id' => (string) $item->id]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($sourceItems->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Outlet GR tidak memiliki rincian item.']]);
            }

            return ['id' => $id, 'number' => $number, 'status' => 'DRAFT'];
        }, 3);
    }


    /**
     * Membuat Purchasing GR DRAFT dari Manual Stock GR setelah release.
     * Idempotent berdasarkan order_kind MANUAL_STOCK dan order_id source GR.
     */
    public function syncFromManualGoodsReceipt(string $stockGoodsReceiptId, $actor): ?array
    {
        if (! Schema::hasTable('stk_goods_receipts')
            || ! Schema::hasTable('stk_goods_receipt_items')
            || ! Schema::hasTable('pur_goods_receipts')
            || ! Schema::hasTable('pur_goods_receipt_items')) {
            return null;
        }

        $source = DB::table('stk_goods_receipts')->where('id', $stockGoodsReceiptId)->first();
        if (! $source || ! in_array(strtolower((string) ($source->receipt_type ?? '')), ['manual', 'non_warehouse'], true)) {
            return null;
        }

        $existing = DB::table('pur_goods_receipts')
            ->where('order_kind', 'MANUAL_STOCK')
            ->where('order_id', $stockGoodsReceiptId)
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            return ['id' => (string) $existing->id, 'number' => (string) $existing->gr_number, 'status' => (string) $existing->status];
        }

        return DB::transaction(function () use ($source, $stockGoodsReceiptId, $actor): array {
            $duplicate = DB::table('pur_goods_receipts')
                ->where('order_kind', 'MANUAL_STOCK')
                ->where('order_id', $stockGoodsReceiptId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($duplicate) {
                return ['id' => (string) $duplicate->id, 'number' => (string) $duplicate->gr_number, 'status' => (string) $duplicate->status];
            }

            $id = (string) Str::ulid();
            $number = $this->nextNumber();
            $now = now();
            $items = DB::table('stk_goods_receipt_items as s')
                ->leftJoin('stk_skus as sku', 'sku.id', '=', 's.sku_id')
                ->where('s.goods_receipt_id', $stockGoodsReceiptId)
                ->orderBy('s.created_at')
                ->get(['s.*', DB::raw("COALESCE(s.sku_name_snapshot, sku.name, s.sku_code_snapshot, 'Item') as resolved_item_name")]);

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Manual GR tidak memiliki rincian item.']]);
            }

            $subtotal = 0.0;
            foreach ($items as $item) {
                $qty = round((float) ($item->received_qty ?? 0), 4);
                $price = round((float) ($item->unit_cost ?? 0), 2);
                $subtotal += round($qty * $price, 2);
            }

            DB::table('pur_goods_receipts')->insert([
                'id' => $id,
                'gr_number' => $number,
                'order_kind' => 'MANUAL_STOCK',
                'order_id' => $stockGoodsReceiptId,
                'fund_request_id' => null,
                'outlet_id' => $source->outlet_id ?? null,
                'chamber_code' => 'OUTLET',
                'document_date' => $source->receipt_date ?? $now->toDateString(),
                'status' => 'DRAFT',
                'currency' => $source->currency ?? 'IDR',
                'subtotal' => round($subtotal, 2),
                'tax_amount' => 0,
                'total_amount' => round($subtotal, 2),
                'external_reference' => 'MANUAL_STOCK_GR:' . $stockGoodsReceiptId,
                'notes' => 'Otomatis dari Manual Stock GR ' . ($source->gr_number ?? $stockGoodsReceiptId) . '.',
                'lock_version' => 1,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $lineNo = 0;
            foreach ($items as $item) {
                $lineNo++;
                $qty = round((float) ($item->received_qty ?? 0), 4);
                $price = round((float) ($item->unit_cost ?? 0), 2);
                DB::table('pur_goods_receipt_items')->insert([
                    'id' => (string) Str::ulid(),
                    'document_id' => $id,
                    'order_item_id' => (string) $item->id,
                    'line_no' => $lineNo,
                    'sku_id' => $item->sku_id,
                    'item_name' => trim((string) $item->resolved_item_name) ?: 'Item',
                    'uom_text' => $item->uom_snapshot ?? 'UNIT',
                    'ordered_qty' => $qty,
                    'executed_qty' => $qty,
                    'unit_price' => $price,
                    'tax_amount' => 0,
                    'line_total' => round($qty * $price, 2),
                    'notes' => $item->notes,
                    'metadata' => json_encode(['stock_goods_receipt_item_id' => (string) $item->id, 'source' => 'MANUAL_STOCK']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return ['id' => $id, 'number' => $number, 'status' => 'DRAFT'];
        }, 3);
    }

    /**
     * Setelah Purchasing GR diposting/approved Finance, buat Invoice Masuk dari Warehouse.
     * Idempotensi dijamin unique source_document pada pur_invoices.
     *
     * @return array<string,mixed>|null
     */
    public function createWarehouseInvoiceAfterFinanceApproval(string $purchasingGoodsReceiptId, $actor): ?array
    {
        if (! Schema::hasTable('pur_invoices')) {
            return null;
        }

        $gr = DB::table('pur_goods_receipts')->where('id', $purchasingGoodsReceiptId)->first();
        if (! $gr || (string) $gr->status !== 'POSTED') {
            return null;
        }

        $existing = DB::table('pur_invoices')
            ->where('direction', 'INCOMING')
            ->where('source_document_kind', 'GOODS_RECEIPT')
            ->where('source_document_id', $purchasingGoodsReceiptId)
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            return ['id' => (string) $existing->id, 'number' => (string) $existing->invoice_number, 'status' => (string) $existing->status];
        }

        try {
            return $this->invoiceWorkflow->create('incoming', [
                'source_document_kind' => 'GOODS_RECEIPT',
                'source_document_id' => $purchasingGoodsReceiptId,
                'invoice_date' => $gr->document_date ?: now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'external_invoice_number' => null,
                'notes' => 'Invoice Warehouse otomatis setelah Purchasing GR disetujui Finance.',
            ], $actor);
        } catch (Throwable $e) {
            $existing = DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_kind', 'GOODS_RECEIPT')
                ->where('source_document_id', $purchasingGoodsReceiptId)
                ->whereNull('deleted_at')
                ->first();
            if ($existing) {
                return ['id' => (string) $existing->id, 'number' => (string) $existing->invoice_number, 'status' => (string) $existing->status];
            }
            throw $e;
        }
    }

    private function nextNumber(): string
    {
        $date = now()->format('Ymd');
        $count = DB::table('pur_goods_receipts')->whereDate('created_at', today())->count() + 1;
        return 'GR-' . $date . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
