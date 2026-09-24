<?php

namespace App\Services\Purchasing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WarehouseOutletInvoiceBridgeService
{
    public function __construct(
        private readonly InvoiceWorkflowService $invoiceWorkflow,
        private readonly WarehouseStockRequestLiabilityOwnershipService $liabilityOwnership,
    ) {
    }

    public function syncFromWarehouseOutgoingInvoice(string $outgoingInvoiceId, ?string $userId = null): ?array
    {
        if (! Schema::hasTable('pur_invoices') || ! Schema::hasTable('pur_invoice_items')) return null;
        $source = DB::table('wh_v3_outgoing_invoices')->where('id', $outgoingInvoiceId)->first();
        if (! $source || (string) $source->source_type !== 'stock_request' || (string) $source->destination_type !== 'outlet') return null;

        $prepared = DB::transaction(function () use ($source, $userId): array {
            $existing = DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
                ->where('source_document_id', $source->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((string) $existing->status === 'DRAFT') {
                    $this->syncDraftItems((string) $existing->id, $source, $userId);
                    return ['id'=>(string)$existing->id,'invoice_number'=>(string)$existing->invoice_number,'status'=>'DRAFT','needs_issue'=>true];
                }

                // Audit lock: Warehouse must never reprice an already issued/paid Purchasing invoice.
                return [
                    'id'=>(string)$existing->id,
                    'invoice_number'=>(string)$existing->invoice_number,
                    'status'=>(string)$existing->status,
                    'needs_issue'=>false,
                ];
            }

            $warehouse = DB::table('outlets')->where('id', $source->warehouse_id)->first(['code','name']);
            $invoiceId = (string) Str::ulid();
            $number = 'INV-WH-IN-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));
            $now = now();
            $subtotal = round((float) $source->grand_total, 2);
            DB::table('pur_invoices')->insert([
                'id'=>$invoiceId,'invoice_number'=>$number,'direction'=>'INCOMING','external_invoice_number'=>$source->invoice_number,
                'source_document_kind'=>'WAREHOUSE_OUTGOING_INVOICE','source_document_id'=>$source->id,'source_document_number'=>$source->invoice_number,
                'fund_request_id'=>null,'chamber_code'=>'OUTLET','outlet_id'=>$source->destination_id,'counterparty_name'=>trim(((string)($warehouse?->code??'')).' - '.((string)($warehouse?->name??'Warehouse'))),
                'invoice_date'=>$source->invoice_date ?: now('Asia/Jakarta')->toDateString(),'due_date'=>now('Asia/Jakarta')->addDays(30)->toDateString(),'status'=>'DRAFT','currency'=>'IDR',
                'subtotal'=>$subtotal,'tax_amount'=>0,'total_amount'=>$subtotal,'paid_amount'=>0,'balance_due'=>$subtotal,
                'notes'=>'Auto Incoming Invoice Purchasing dari Warehouse Outgoing Invoice Stock Request '.$source->source_number,
                'lock_version'=>1,'journal_status'=>'NOT_POSTED','created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                'metadata'=>json_encode([
                    'warehouse_outgoing_invoice_id'=>(string)$source->id,
                    'warehouse_id'=>(string)$source->warehouse_id,
                    'stock_request_id'=>(string)$source->source_id,
                    'auto_generated'=>true,
                    'auto_issue'=>true,
                    'flow_version'=>3,
                    'billing_integrity_version'=>2,
                ]),
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            $this->syncDraftItems($invoiceId, $source, $userId);

            if (Schema::hasTable('pur_invoice_events')) DB::table('pur_invoice_events')->insert([
                'id'=>(string)Str::ulid(),'invoice_id'=>$invoiceId,'event_code'=>'INVOICE_CREATED','event_label'=>'Incoming Invoice otomatis dari Warehouse Stock Request.',
                'status'=>'DRAFT','actor_user_id'=>$userId,'notes'=>null,'idempotency_key'=>'WAREHOUSE_OUTGOING_INVOICE:'.(string)$source->id,
                'metadata'=>json_encode(['warehouse_outgoing_invoice_id'=>(string)$source->id,'stock_request_id'=>(string)$source->source_id,'billing_integrity_version'=>2,'auto_issue'=>true]),
                'occurred_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
            ]);
            return ['id'=>$invoiceId,'invoice_number'=>$number,'status'=>'DRAFT','needs_issue'=>true];
        }, 5);

        if ($prepared['needs_issue'] ?? false) {
            $actor = $userId ? User::query()->find($userId) : null;
            $this->invoiceWorkflow->issue('incoming', (string) $prepared['id'], [
                'idempotency_key' => 'WAREHOUSE_AUTO_ISSUE:'.(string) $source->id,
                'notes' => 'Auto issued karena invoice diterbitkan oleh Warehouse untuk Stock Request outlet.',
            ], $actor);
        }

        // ERP-V5 I06: this Warehouse invoice is a commercial mirror. Order AP lifecycle owns
        // the liability and its settlement; cover the invoice outbox before any generic Finance retry.
        $ownership = $this->liabilityOwnership->coverIfWarehouseStockRequestMirror((string) $prepared['id'], $userId);

        $final = DB::table('pur_invoices')->where('id', $prepared['id'])->first(['id','invoice_number','status','issued_at','total_amount','paid_amount','balance_due','journal_status','journal_reference']);
        return $final ? [
            'id'=>(string)$final->id,
            'invoice_number'=>(string)$final->invoice_number,
            'status'=>(string)$final->status,
            'issued_at'=>$final->issued_at,
            'total_amount'=>round((float)$final->total_amount,2),
            'paid_amount'=>round((float)$final->paid_amount,2),
            'balance_due'=>round((float)$final->balance_due,2),
            'journal_status'=>(string)($final->journal_status ?? ''),
            'journal_reference'=>$final->journal_reference,
            'liability_ownership'=>$ownership,
        ] : null;
    }

    private function syncDraftItems(string $invoiceId, object $source, ?string $userId): void
    {
        $invoice = DB::table('pur_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
        if (! $invoice || (string) $invoice->status !== 'DRAFT') return;

        $items = DB::table('wh_v3_outgoing_invoice_items as item')
            ->join('stk_skus as sku', 'sku.id', '=', 'item.sku_id')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as bill', 'bill.id', '=', 'item.billing_uom_id')
            ->where('item.outgoing_invoice_id', $source->id)
            ->orderBy('sku.name')
            ->get(['item.*','sku.name as sku_name','base.code as base_uom_code','bill.code as billing_uom_code']);

        DB::table('pur_invoice_items')->where('invoice_id', $invoiceId)->delete();
        $line = 1;
        foreach ($items as $item) {
            $qty = round((float) ($item->billing_qty ?: $item->billed_qty_base), 4);
            $unit = round((float) $item->unit_price, 2);
            $total = round((float) $item->line_total, 2);
            $billingUom = (string) ($item->billing_uom_code_snapshot ?: $item->billing_uom_code ?: $item->base_uom_code ?: 'UNIT');
            DB::table('pur_invoice_items')->insert([
                'id'=>(string)Str::ulid(),'invoice_id'=>$invoiceId,'line_no'=>$line++,'source_item_kind'=>'WAREHOUSE_OUTGOING_INVOICE_ITEM','source_item_id'=>$item->id,
                'sku_id'=>$item->sku_id,'item_name'=>$item->sku_name,'uom_text'=>$billingUom,'qty'=>$qty,'unit_price'=>$unit,
                'tax_mode'=>'NO_TAX','tax_percent'=>0,'subtotal'=>$total,'tax_amount'=>0,'line_total'=>$total,'notes'=>null,
                'metadata'=>json_encode([
                    'warehouse_outgoing_invoice_item_id'=>(string)$item->id,
                    'billing_uom_id'=>$item->billing_uom_id ? (string)$item->billing_uom_id : null,
                    'billing_uom_code'=>$billingUom,
                    'billing_conversion_factor'=>(float)($item->billing_conversion_factor_snapshot ?: 1),
                    'billing_qty'=>$qty,
                    'billed_qty_base'=>(float)$item->billed_qty_base,
                    'stock_movement_qty_base'=>(float)$item->stock_movement_qty_base,
                    'inventory_cost_total'=>(float)$item->inventory_cost_total,
                    'unit_price_basis'=>(string)($item->unit_price_basis ?: 'PER_PRICE_UOM'),
                    'billing_integrity_version'=>2,
                ]),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }

        $subtotal = round((float) $source->grand_total, 2);
        $meta = $this->decodeJson($invoice->metadata);
        $meta['billing_integrity_version'] = 2;
        $meta['warehouse_outgoing_invoice_id'] = (string) $source->id;
        $meta['auto_issue'] = true;
        $meta['last_source_sync_at'] = now()->toIso8601String();
        DB::table('pur_invoices')->where('id', $invoiceId)->update([
            'external_invoice_number'=>$source->invoice_number,
            'source_document_number'=>$source->invoice_number,
            'subtotal'=>$subtotal,'tax_amount'=>0,'total_amount'=>$subtotal,
            'paid_amount'=>0,'balance_due'=>$subtotal,
            'updated_by_user_id'=>$userId,'metadata'=>json_encode($meta),'updated_at'=>now(),
        ]);
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
