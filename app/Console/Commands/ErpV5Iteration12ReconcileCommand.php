<?php

namespace App\Console\Commands;

use App\Models\Warehouse\WarehouseStockRequest;
use App\Services\Purchasing\WarehouseCommercialPriceService;
use App\Services\Purchasing\WarehouseOutletInvoiceBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration12ReconcileCommand extends Command
{
    protected $signature = 'erp-v5:iteration-12-reconcile {--dry-run : Hanya tampilkan perubahan} {--stock-request= : Batasi satu Stock Request ULID}';
    protected $description = 'Reconcile canonical Warehouse selling price snapshots and auto-issue Warehouse incoming invoices for ERP V5 Iteration 12.';

    public function handle(WarehouseCommercialPriceService $commercial, WarehouseOutletInvoiceBridgeService $invoiceBridge): int
    {
        foreach (['stk_requests','stk_request_items','pur_fund_requests','pur_fund_request_items','pur_purchase_orders','pur_purchase_order_items','pur_invoices','wh_v3_outgoing_invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table: {$table}");
                return self::FAILURE;
            }
        }
        if (! Schema::hasColumn('stk_request_items', 'commercial_price_snapshot')) {
            $this->error('Migration Iteration 12 belum dijalankan: stk_request_items.commercial_price_snapshot tidak tersedia.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $only = trim((string) $this->option('stock-request'));
        $stats = [
            'stock_request_lines' => 0,
            'fund_request_lines' => 0,
            'purchase_order_lines' => 0,
            'po_locked_skipped' => 0,
            'incoming_invoices_issued' => 0,
            'errors' => 0,
        ];

        $query = WarehouseStockRequest::query()
            ->with(['items.sku'])
            ->whereNotNull('destination_warehouse_id')
            ->orderBy('created_at');
        if (Schema::hasColumn('stk_requests', 'request_channel')) {
            $query->where('request_channel', 'warehouse_operations');
        }
        if ($only !== '') $query->whereKey($only);

        foreach ($query->get() as $request) {
            foreach ($request->items as $item) {
                try {
                    $price = $commercial->resolveForStockRequest($request, $item, null, false);
                    $stats['stock_request_lines']++;
                    if (! $dry) {
                        DB::table('stk_request_items')->where('id', $item->id)->update([
                            'unit_price_snapshot' => round((float) $price['base_equivalent_unit_price'], 2),
                            'line_total_snapshot' => round((float) $price['line_total'], 2),
                            'commercial_price_snapshot' => json_encode($price, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    }

                    $fund = DB::table('pur_fund_requests')
                        ->where('source_key', 'STOCK_REQUEST:'.(string)$request->id)
                        ->whereIn('status', ['DRAFT','AWAITING_REQUEST_APPROVAL'])
                        ->first();
                    if ($fund) {
                        $fundItem = DB::table('pur_fund_request_items')
                            ->where('fund_request_id', $fund->id)
                            ->where('source_line_key', (string) $item->id)
                            ->first();
                        if ($fundItem) {
                            $meta = $this->decode($fundItem->metadata);
                            $meta['stock_qty_base'] = (float) $price['quantity_base'];
                            $meta['stock_base_uom_code'] = (string) ($item->base_uom_code_snapshot ?: 'BASE');
                            $meta['warehouse_commercial_price'] = $price;
                            $meta['price_source'] = $price['price_source'];
                            $meta['price_reference_id'] = $price['price_reference_id'];
                            $stats['fund_request_lines']++;
                            if (! $dry) DB::table('pur_fund_request_items')->where('id', $fundItem->id)->update([
                                'uom_text' => $price['billing_uom_code'],
                                'qty' => $price['billing_qty'],
                                'estimated_unit_price' => $price['billing_unit_price'],
                                'subtotal' => $price['line_total'],
                                'tax_amount' => 0,
                                'line_total' => $price['line_total'],
                                'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    $po = DB::table('pur_purchase_orders')
                        ->where('stock_request_id', $request->id)
                        ->where('source_type', 'STOCK_INVENTORY_REQUEST')
                        ->first();
                    if ($po) {
                        $locked = Schema::hasTable('pur_order_ap_lifecycles')
                            && DB::table('pur_order_ap_lifecycles')->where('order_kind','PURCHASE_ORDER')->where('order_id',$po->id)->exists();
                        if ($locked) {
                            $stats['po_locked_skipped']++;
                        } else {
                            $poItem = DB::table('pur_purchase_order_items')
                                ->where('purchase_order_id',$po->id)
                                ->where('stock_request_item_id',$item->id)
                                ->first();
                            if ($poItem) {
                                $meta = $this->decode($poItem->metadata);
                                $meta['warehouse_commercial_price'] = $price;
                                $meta['price_source'] = $price['price_source'];
                                $meta['price_reference_id'] = $price['price_reference_id'];
                                $meta['storage_basis'] = 'BASE_UOM';
                                $stats['purchase_order_lines']++;
                                if (! $dry) DB::table('pur_purchase_order_items')->where('id',$poItem->id)->update([
                                    'approved_qty' => $price['quantity_base'],
                                    'unit_price' => round((float)$price['base_equivalent_unit_price'],2),
                                    'subtotal' => $price['line_total'],
                                    'tax_amount' => 0,
                                    'line_total' => $price['line_total'],
                                    'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->warn(sprintf('%s / %s: %s', $request->request_number, $item->sku_id, $e->getMessage()));
                }
            }

            if (! $dry) {
                $fund = DB::table('pur_fund_requests')->where('source_key','STOCK_REQUEST:'.(string)$request->id)->whereIn('status',['DRAFT','AWAITING_REQUEST_APPROVAL'])->first();
                if ($fund) {
                    $total = round((float)DB::table('pur_fund_request_items')->where('fund_request_id',$fund->id)->sum('line_total'),2);
                    DB::table('pur_fund_requests')->where('id',$fund->id)->update(['subtotal'=>$total,'tax_amount'=>0,'grand_total'=>$total,'updated_at'=>now()]);
                }
                $po = DB::table('pur_purchase_orders')->where('stock_request_id',$request->id)->where('source_type','STOCK_INVENTORY_REQUEST')->first();
                if ($po) {
                    $locked = Schema::hasTable('pur_order_ap_lifecycles') && DB::table('pur_order_ap_lifecycles')->where('order_kind','PURCHASE_ORDER')->where('order_id',$po->id)->exists();
                    if (! $locked) {
                        $total = round((float)DB::table('pur_purchase_order_items')->where('purchase_order_id',$po->id)->sum('line_total'),2);
                        DB::table('pur_purchase_orders')->where('id',$po->id)->update(['subtotal'=>$total,'tax_amount'=>0,'total_amount'=>$total,'updated_at'=>now()]);
                    }
                }
            }
        }

        $draftInvoices = DB::table('pur_invoices')
            ->where('direction','INCOMING')
            ->where('source_document_kind','WAREHOUSE_OUTGOING_INVOICE')
            ->where('status','DRAFT')
            ->whereNull('deleted_at')
            ->pluck('source_document_id');
        foreach ($draftInvoices as $outgoingId) {
            try {
                $stats['incoming_invoices_issued']++;
                if (! $dry) $invoiceBridge->syncFromWarehouseOutgoingInvoice((string)$outgoingId, null);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn("Invoice {$outgoingId}: {$e->getMessage()}");
            }
        }

        $this->table(['Check','Result'], collect($stats)->map(fn($value,$key)=>[$key,$value])->values()->all());
        $this->info($dry ? 'DRY RUN selesai. Tidak ada data yang diubah.' : 'Reconcile Iteration 12 selesai.');
        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value)==='') return [];
        $decoded = json_decode($value,true);
        return is_array($decoded) ? $decoded : [];
    }
}
