<?php

namespace App\Services\Operations;

use App\Models\Warehouse\WarehouseLedgerPosting;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransactionResetService
{
    public function __construct(private readonly WarehouseLedgerService $warehouseLedger) {}

    private array $tables = [
        'purchasing_backoffice'=>[
            'pur_goods_receipt_items','pur_goods_receipts','pur_execution_decisions','pur_order_decisions',
            'pur_purchase_order_items','pur_purchase_orders','pur_fund_request_decisions','pur_document_events','pur_fund_request_items','pur_fund_requests',
            'pur_reconciliation_issue_items','pur_reconciliation_issues','pur_reconciliation_links','pur_reconciliation_runs','pur_document_sequences',
        ],
        'warehouse_purchasing'=>[
            'wh_supplier_payment_allocations','wh_supplier_payments','wh_supplier_invoice_items','wh_supplier_invoices','wh_purchase_invoice_items','wh_purchase_invoices',
            'wh_stock_in_documents','wh_stock_in_units','wh_stock_in_items','wh_stock_ins','wh_supplier_purchase_order_items','wh_supplier_purchase_orders',
            'wh_purchase_request_items','wh_purchase_requests','wh_purchasing_events',
            'wh_receiving_units','wh_receiving_items','wh_receivings','wh_delivery_order_items','wh_delivery_orders',
            'wh_sales_goods_receipt_items','wh_sales_goods_receipts',
        ],
        'stock_request'=>[
            'wh_fulfillment_allocations','wh_task_assignments','wh_fulfillment_items','wh_fulfillments',
            'wh_stock_request_fulfillment_barcodes','wh_stock_request_fulfillment_items','wh_stock_request_fulfillments','wh_stock_request_handoffs',
            // Older baseline tables are kept here when they still exist in a deployed DB.
            'wh_stock_request_timelines','wh_stock_request_items','wh_stock_requests',
            'wh_v3_stock_request_review_items','wh_v3_stock_request_reviews','stk_request_approvals','stk_request_timelines','stk_request_items','stk_requests',
        ],
        'goods_receipt_legacy'=>[
            'cogs_purchasing_cost_snapshots','stk_receipt_timelines','stk_receipt_items','stk_receipts','stk_goods_receipt_items','stk_goods_receipts',
        ],
    ];

    public function preview(): array
    {
        $groups=[];$total=0;
        foreach($this->tables as $name=>$tables){
            $items=[];$count=0;
            foreach($tables as $table){
                if(!Schema::hasTable($table)) continue;
                $rows=DB::table($table)->count();$items[]=['table'=>$table,'rows'=>$rows];$count+=$rows;
            }
            $groups[]=['group'=>$name,'rows'=>$count,'tables'=>$items];$total+=$count;
        }
        $v3=$this->v3StockRequestCounts();
        $groups[]=['group'=>'warehouse_logistics_v3_stock_request','rows'=>array_sum($v3),'tables'=>collect($v3)->map(fn($rows,$table)=>['table'=>$table,'rows'=>$rows])->values()->all()];
        $total+=array_sum($v3);
        $financeBlockers=$this->financePostingResetBlockers();
        return [
            'groups'=>$groups,
            'total_rows'=>$total,
            'finance_posting_ready'=>count($financeBlockers)===0,
            'finance_posting_blocker_count'=>count($financeBlockers),
            'finance_posting_blockers'=>$financeBlockers,
            'warning'=>'Reset menghapus PR/PO, Stock Request, GR legacy/Purchasing, serta DO/GR Logistics v3 yang berasal dari Stock Request. Warehouse Ledger purchase_in/request_out yang terkait dokumen reset direversal lebih dulu. '.(count($financeBlockers)>0?'FINANCE BLOCKER: masih ada '.count($financeBlockers).' General Posting/journal Purchasing aktif. Buka Finance → General Posting, Unpost seluruh POSTED lalu Hapus Draft sebelum Reset Transaksi. ':'Finance preflight bersih. ').'Reset juga diblokir bila reversal Warehouse akan membuat batch negatif. Sales Order/Transfer v3 tidak dihapus karena bukan bagian PR/PO/Stock Request dan memiliki ledger terpisah.',
        ];
    }

    public function execute(string $confirmation, bool $includePosted, string $userId, bool $deepReset = false): array
    {
        if ($deepReset) {
            if (trim($confirmation) !== 'RESET WAREHOUSE TEST DATA') {
                throw ValidationException::withMessages(['confirmation'=>['Untuk Deep Reset ketik persis RESET WAREHOUSE TEST DATA.']]);
            }
            if (! $includePosted) {
                throw ValidationException::withMessages(['include_posted'=>['Centang konfirmasi posted/completed document sebelum Deep Reset.']]);
            }
            $this->assertFinancePostingResetReady();
            return $this->executeDeepTestReset($userId);
        }

        if(trim($confirmation)!=='RESET TRANSAKSI') throw ValidationException::withMessages(['confirmation'=>['Ketik persis RESET TRANSAKSI.']]);
        if(!$includePosted) throw ValidationException::withMessages(['include_posted'=>['Centang konfirmasi posted/completed document sebelum reset.']]);
        $this->assertFinancePostingResetReady();
        $before=$this->preview();

        DB::transaction(function() use ($userId): void {
            // Rewind Warehouse physical ledger first. OUT reversals are applied before
            // purchase IN reversals so stock sent by the Stock Request returns to origin
            // before supplier receipts are removed. If another downstream flow already
            // consumed the batch, the reset is blocked instead of forcing negative stock.
            $this->releaseLegacyStockRequestReservations($userId);
            // Repair reserved_qty residue on the exact purchase batches that will be
            // rewound. Older barcode/fulfillment resets could delete allocation rows
            // while leaving wh_batch_balances.reserved_qty behind. We rebuild reservation
            // from live NON-reset flows (Production/Transfer) before ledger reversal.
            $this->reconcileReservationsOnResetPurchaseBatches();
            $this->reverseWarehouseLedgerForReset($userId);
            $this->deleteStockRequestCancellationRequests();
            $this->deleteV3AndLegacyReceiptInventoryMovements();
            $this->deletePurchasingInvoicesDerivedFromResetDocuments();
            $this->deleteV3Logistics();

            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try{
                foreach($this->tables as $tables){
                    foreach($tables as $table){
                        if(Schema::hasTable($table)) DB::table($table)->delete();
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        },1);

        return [
            'deleted_rows'=>$before['total_rows'],
            'executed_by_user_id'=>$userId,
            'executed_at'=>now()->toIso8601String(),
            'message'=>'Reset PR/PO/Stock Request dan GR terkait selesai setelah Finance General Posting preflight bersih. Master data tidak dihapus. Warehouse Ledger terkait direversal aman; Actual Stock outlet hasil GR yang dihapus harus dibangun ulang dari menu Reset Aktual Stock.',
        ];
    }

    private function assertFinancePostingResetReady(): void
    {
        $blockers=$this->financePostingResetBlockers();
        if(empty($blockers)) return;

        $labels=collect($blockers)->take(8)->map(function(array $row): string {
            return (string)($row['posting_no']??$row['journal_no']??$row['reference_no']??$row['id']??'UNKNOWN');
        })->filter()->implode(', ');
        $suffix=count($blockers)>8?' dan '.(count($blockers)-8).' lainnya':'';

        throw ValidationException::withMessages([
            'finance_general_posting'=>[
                'Reset diblokir karena masih ada '.count($blockers).' posting Finance Purchasing aktif/orphan. Buka Finance → General Posting, lakukan Unpost untuk status POSTED lalu Hapus Draft. Untuk journal legacy/orphan jalankan reconcile Unified Posting terlebih dahulu. Blocker: '.$labels.$suffix.'.',
            ],
        ]);
    }

    /**
     * Reset Transaksi hanya boleh menghapus source Purchasing setelah accounting envelope
     * sudah bersih. POSTED harus di-Unpost (membuat reversal), lalu DRAFT harus dihapus.
     * Legacy journal direct-to-GL yang belum mempunyai parent General Posting juga diblokir.
     */
    private function financePostingResetBlockers(): array
    {
        $result=[];

        if(Schema::hasTable('finance_general_postings')){
            $rows=DB::table('finance_general_postings')
                ->whereIn('source_code',['PURCHASING','PURCH_REIMBURSE','PUR_REALIZATION'])
                ->whereIn('status',['POSTED','DRAFT'])
                ->orderByDesc('business_date')
                ->orderBy('posting_no')
                ->limit(200)
                ->get(['id','posting_no','source_code','source_key','reference_no','business_date','status','amount']);

            foreach($rows as $row){
                $result[]=[
                    'type'=>'GENERAL_POSTING',
                    'id'=>(string)$row->id,
                    'posting_no'=>(string)$row->posting_no,
                    'source_code'=>(string)$row->source_code,
                    'source_key'=>(string)$row->source_key,
                    'reference_no'=>$row->reference_no?(string)$row->reference_no:null,
                    'business_date'=>(string)$row->business_date,
                    'status'=>(string)$row->status,
                    'amount'=>(float)$row->amount,
                    'action'=>$row->status==='POSTED'?'UNPOST_THEN_DELETE_DRAFT':'DELETE_DRAFT',
                ];
            }
        }

        if(Schema::hasTable('finance_journal_entries')&&Schema::hasTable('finance_general_posting_journals')){
            $orphans=DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as gpj','gpj.journal_entry_id','=','j.id')
                ->where('j.status','POSTED')
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('gpj.id')
                ->where(function($q): void {
                    $q->whereIn('j.source_type',['PURCHASING','PURCH_REIMBURSE'])
                        ->orWhere('j.source_key','like','FIN-PUR-%')
                        ->orWhere('j.source_key','like','PUR_REIMBURSE:%');
                })
                ->orderByDesc('j.journal_date')
                ->limit(200)
                ->get(['j.id','j.journal_no','j.journal_date','j.source_type','j.source_key','j.reference_no','j.total_debit']);

            foreach($orphans as $row){
                $result[]=[
                    'type'=>'LEGACY_ORPHAN_JOURNAL',
                    'id'=>(string)$row->id,
                    'journal_no'=>(string)$row->journal_no,
                    'source_code'=>(string)$row->source_type,
                    'source_key'=>(string)($row->source_key??''),
                    'reference_no'=>$row->reference_no?(string)$row->reference_no:null,
                    'business_date'=>(string)$row->journal_date,
                    'status'=>'POSTED',
                    'amount'=>(float)$row->total_debit,
                    'action'=>'RUN_F01_OR_F02_RECONCILE_THEN_UNPOST_DELETE',
                ];
            }
        }

        return $result;
    }

    /**
     * Deep Reset khusus local/UAT test data. Reset normal tetap reversal-based.
     * Master SKU/UOM/Warehouse/Supplier/Customer/Storage/Chain Supply dipertahankan.
     */
    private function executeDeepTestReset(string $userId): array
    {
        // Warehouse stock shown by All Item/Dashboard is sourced from
        // stk_inventory_balances, while batch detail is sourced from wh_batch_balances.
        // A deep reset must clear BOTH projections or the UI will continue showing stale
        // quantity/average cost/valuation after ledger/batches are deleted.
        $warehouseIds = Schema::hasTable('outlets')
            ? DB::table('outlets')->where('type', 'warehouse')->pluck('id')->filter()->values()
            : collect();

        $deepTables = [
            'wh_v3_finance_events','wh_v3_invoice_approvals','wh_v3_manual_invoice_items','wh_v3_manual_invoices',
            'wh_v3_outgoing_invoice_items','wh_v3_outgoing_invoices',
            'wh_supplier_payment_allocations','wh_supplier_payments','wh_supplier_invoice_items','wh_supplier_invoices',
            'wh_purchase_invoice_items','wh_purchase_invoices','wh_sales_invoice_items','wh_sales_invoices',
            'wh_v3_logistics_events','wh_v3_goods_receipt_items','wh_v3_goods_receipts',
            'wh_v3_delivery_order_items','wh_v3_delivery_orders','wh_v3_logistics_prepare_items','wh_v3_logistics_prepare_requests',
            'wh_receiving_units','wh_receiving_items','wh_receivings','wh_delivery_order_items','wh_delivery_orders',
            'wh_sales_goods_receipt_items','wh_sales_goods_receipts','wh_fulfillment_allocations','wh_task_assignments',
            'wh_fulfillment_items','wh_fulfillments','wh_stock_request_fulfillment_barcodes','wh_stock_request_fulfillment_items',
            'wh_stock_request_fulfillments','wh_stock_request_handoffs',
            'wh_v3_sales_transfer_events','wh_v3_sales_order_items','wh_v3_sales_orders','wh_v3_transfer_order_items','wh_v3_transfer_orders',
            'wh_sales_order_reservations','wh_sales_order_items','wh_sales_orders','wh_stock_transfer_allocations','wh_stock_transfer_items','wh_stock_transfers',
            'wh_v3_production_result_items','wh_v3_production_results','wh_v3_production_material_request_items','wh_v3_production_material_requests',
            'wh_production_input_allocations','wh_production_output_items','wh_production_outputs','wh_production_inputs','wh_productions',
            'wh_stock_in_documents','wh_stock_in_units','wh_stock_in_items','wh_stock_ins','wh_supplier_purchase_order_items','wh_supplier_purchase_orders',
            'wh_purchase_request_items','wh_purchase_requests','wh_purchasing_events',
            'wh_v3_stock_request_review_items','wh_v3_stock_request_reviews','wh_stock_request_timelines','wh_stock_request_items','wh_stock_requests',
            'stk_request_approvals','stk_request_timelines','stk_request_items','stk_requests','stk_cancellation_requests',
            'pur_invoice_events','pur_invoice_payments','pur_invoice_items','pur_invoices','pur_finance_posting_outbox',
            'pur_goods_receipt_items','pur_goods_receipts','pur_execution_decisions','pur_order_decisions','pur_purchase_order_items','pur_purchase_orders',
            'pur_fund_request_decisions','pur_document_events','pur_fund_request_items','pur_fund_requests',
            'pur_reconciliation_issue_items','pur_reconciliation_issues','pur_reconciliation_links','pur_reconciliation_runs',
            'cogs_purchasing_cost_snapshots','stk_receipt_timelines','stk_receipt_items','stk_receipts','stk_goods_receipt_items','stk_goods_receipts',
            'wh_stock_units','wh_ledger_entries','wh_ledger_postings','wh_batch_balances','wh_batches',
        ];

        $deleted = [];
        $warehouseBalancesReset = 0;
        $warehouseMovementsDeleted = 0;
        DB::transaction(function () use ($deepTables, $warehouseIds, &$deleted, &$warehouseBalancesReset, &$warehouseMovementsDeleted): void {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($deepTables as $table) {
                    if (! Schema::hasTable($table)) continue;
                    $count = DB::table($table)->count();
                    if ($count > 0) DB::table($table)->delete();
                    $deleted[$table] = $count;
                }

                if (Schema::hasTable('stk_inventory_movements')) {
                    $types = ['wh_v3_goods_receipt','stk_goods_receipt','wh_delivery_order','wh_stock_in','wh_v3_production_material_request','wh_v3_production_result'];
                    $query = DB::table('stk_inventory_movements')->whereIn('reference_type', $types);
                    $count = (clone $query)->count();
                    $query->delete();
                    $deleted['stk_inventory_movements(transactional)'] = $count;

                    // Also remove every Stock Inventory movement whose physical outlet is a
                    // Warehouse. Deep Reset is explicitly a test-data wipe, therefore its
                    // Warehouse stock history must not survive after quantity/valuation = 0.
                    if ($warehouseIds->isNotEmpty()) {
                        $warehouseMovementsDeleted = DB::table('stk_inventory_movements')
                            ->whereIn('outlet_id', $warehouseIds)
                            ->count();
                        DB::table('stk_inventory_movements')->whereIn('outlet_id', $warehouseIds)->delete();
                        $deleted['stk_inventory_movements(warehouse)'] = $warehouseMovementsDeleted;
                    }
                }

                if ($warehouseIds->isNotEmpty() && Schema::hasTable('stk_inventory_balances')) {
                    $warehouseBalancesReset = DB::table('stk_inventory_balances')
                        ->whereIn('outlet_id', $warehouseIds)
                        ->count();
                    DB::table('stk_inventory_balances')
                        ->whereIn('outlet_id', $warehouseIds)
                        ->update([
                            'on_hand_qty' => 0,
                            'average_unit_cost' => 0,
                            'inventory_value' => 0,
                            'last_movement_at' => null,
                            'lock_version' => DB::raw('COALESCE(lock_version, 0) + 1'),
                            'updated_at' => now(),
                        ]);
                    $deleted['stk_inventory_balances(warehouse_zeroed)'] = $warehouseBalancesReset;
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }, 1);

        return [
            'deep_reset' => true,
            'deleted_rows' => array_sum($deleted),
            'deleted_by_table' => $deleted,
            'executed_by_user_id' => $userId,
            'executed_at' => now()->toIso8601String(),
            'warehouse_balances_zeroed' => $warehouseBalancesReset,
            'warehouse_movements_deleted' => $warehouseMovementsDeleted,
            'message' => 'Deep Reset Warehouse test data selesai. Dokumen, ledger, batch, Warehouse stock quantity, average cost, valuation, dan Warehouse inventory movement dibersihkan. Master data tetap ada. Reset Aktual Stock -> Zero hanya diperlukan untuk outlet non-Warehouse.',
        ];
    }

    private function releaseLegacyStockRequestReservations(string $userId): void
    {
        if (! Schema::hasTable('wh_fulfillment_allocations')) return;

        $allocations = DB::table('wh_fulfillment_allocations')
            ->get(['id','stock_unit_id','batch_id','storage_id','qty_base','status']);

        foreach ($allocations as $allocation) {
            if ((string) $allocation->status === 'reserved' && Schema::hasTable('wh_batch_balances')) {
                $balance = DB::table('wh_batch_balances')
                    ->where('batch_id', $allocation->batch_id)
                    ->where('storage_id', $allocation->storage_id)
                    ->lockForUpdate()
                    ->first();
                if ($balance) {
                    DB::table('wh_batch_balances')->where('id', $balance->id)->update([
                        'reserved_qty' => max(0, round((float) $balance->reserved_qty - (float) $allocation->qty_base, 4)),
                        'lock_version' => ((int) $balance->lock_version) + 1,
                        'updated_at' => now(),
                    ]);
                }
            }

            // Barcode flow is legacy, but restoring the unit avoids leaving a reset
            // Stock Request with an orphan in_transit/reserved unit.
            if ($allocation->stock_unit_id && Schema::hasTable('wh_stock_units')) {
                // Some legacy databases contain stock units whose batch row was deleted
                // while FK checks were disabled in an older reset/hotfix. Updating any
                // column on such a row makes MySQL revalidate batch_id and raises 1452.
                // Barcode stock units are legacy in Warehouse v3, so only restore status
                // when the referenced batch still exists. Orphan units are left untouched
                // and their fulfillment allocation is removed later by this reset.
                $unit = DB::table('wh_stock_units')->where('id', $allocation->stock_unit_id)->first(['id','batch_id']);
                $batchIsValid = $unit && (! Schema::hasTable('wh_batches') || DB::table('wh_batches')->where('id', $unit->batch_id)->exists());
                if ($batchIsValid) {
                    DB::table('wh_stock_units')->where('id', $allocation->stock_unit_id)->update([
                        'status' => 'available',
                        'updated_by_user_id' => $userId,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }


    /**
     * Rebuild reserved_qty for batch/storage touched by purchase_in postings that are
     * included in this reset. Stock Request fulfillment is itself part of the reset,
     * therefore its reservations must not survive. Reservations belonging to Production
     * or legacy Transfer that are still active are preserved and will correctly block a
     * purchase reversal if they depend on the received stock.
     */
    private function reconcileReservationsOnResetPurchaseBatches(): void
    {
        if (! Schema::hasTable('wh_batch_balances') || ! Schema::hasTable('wh_ledger_entries')) return;

        $postingIds = collect();
        if (Schema::hasTable('wh_stock_ins') && Schema::hasTable('wh_ledger_postings')) {
            $stockInIds = DB::table('wh_stock_ins')->pluck('id')->filter()->values();
            if ($stockInIds->isNotEmpty()) {
                $postingIds = DB::table('wh_ledger_postings')
                    ->where('status', 'posted')
                    ->where('movement_type', 'purchase_in')
                    ->where('reference_type', 'wh_stock_in')
                    ->whereIn('reference_id', $stockInIds)
                    ->pluck('id')
                    ->filter()->values();
            }
        }
        if ($postingIds->isEmpty()) return;

        $balances = DB::table('wh_ledger_entries')
            ->whereIn('posting_id', $postingIds)
            ->where('direction', 'IN')
            ->get(['batch_id','storage_id'])
            ->unique(fn ($row) => (string)$row->batch_id.'|'.(string)$row->storage_id);

        foreach ($balances as $key) {
            $liveReserved = 0.0;

            // Production legacy reservation remains authoritative when the production
            // document is NOT part of Reset Transaksi.
            if (Schema::hasTable('wh_production_input_allocations')) {
                $liveReserved += (float) DB::table('wh_production_input_allocations')
                    ->where('batch_id', $key->batch_id)
                    ->where('storage_id', $key->storage_id)
                    ->where('status', 'reserved')
                    ->sum('qty_base');
            }

            // Legacy Transfer Stock is intentionally outside this reset scope.
            if (Schema::hasTable('wh_stock_transfer_allocations')) {
                $liveReserved += (float) DB::table('wh_stock_transfer_allocations')
                    ->where('origin_batch_id', $key->batch_id)
                    ->where('origin_storage_id', $key->storage_id)
                    ->where('status', 'reserved')
                    ->sum('qty_base');
            }

            $balance = DB::table('wh_batch_balances')
                ->where('batch_id', $key->batch_id)
                ->where('storage_id', $key->storage_id)
                ->lockForUpdate()->first();
            if (! $balance) continue;

            $normalized = round(max(0, $liveReserved), 4);
            if (abs((float)$balance->reserved_qty - $normalized) > 0.0001) {
                DB::table('wh_batch_balances')->where('id', $balance->id)->update([
                    'reserved_qty' => $normalized,
                    'lock_version' => ((int)$balance->lock_version) + 1,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function postingDependencyDiagnostic(WarehouseLedgerPosting $posting): string
    {
        if (! Schema::hasTable('wh_ledger_entries') || ! Schema::hasTable('wh_batch_balances')) return '';
        $posting->loadMissing('entries');
        $parts = [];
        foreach ($posting->entries as $entry) {
            if ((string)$entry->direction !== 'IN') continue;
            $balance = DB::table('wh_batch_balances')
                ->where('batch_id', $entry->batch_id)
                ->where('storage_id', $entry->storage_id)
                ->first();
            if (! $balance) continue;
            $required = round((float)$entry->quantity_base, 4);
            $onHand = round((float)$balance->on_hand_qty, 4);
            $reserved = round((float)$balance->reserved_qty, 4);
            $quarantine = round((float)$balance->quarantine_qty, 4);
            $available = round($onHand - $reserved - $quarantine, 4);
            if ($required <= $available + 0.0001) continue;

            if ($required <= $onHand + 0.0001 && ($reserved > 0.0001 || $quarantine > 0.0001)) {
                $parts[] = sprintf(
                    'Batch %s: on-hand %.4f sebenarnya cukup untuk reversal %.4f, tetapi reserved %.4f / quarantine %.4f menyisakan available %.4f. Reservation yang masih aktif berasal dari flow non-reset (mis. Production/Transfer) dan harus dibatalkan dulu.',
                    $entry->batch_id, $onHand, $required, $reserved, $quarantine, $available
                );
                continue;
            }

            $consumed = max(0, round($required - $onHand, 4));
            $parts[] = sprintf(
                'Batch %s: on-hand %.4f, reversal membutuhkan %.4f; sekitar %.4f sudah benar-benar keluar/terkonsumsi oleh transaksi downstream yang tidak termasuk scope reset.',
                $entry->batch_id, $onHand, $required, $consumed
            );
        }
        return implode(' ', $parts);
    }

    private function reverseWarehouseLedgerForReset(string $userId): void
    {
        if (! Schema::hasTable('wh_ledger_postings')) return;

        $targets = collect();

        // Warehouse v3 Stock Request GR -> request_out.
        if (Schema::hasTable('wh_v3_logistics_prepare_requests') && Schema::hasTable('wh_v3_delivery_orders') && Schema::hasTable('wh_v3_goods_receipts')) {
            $grIds = DB::table('wh_v3_goods_receipts as g')
                ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
                ->join('wh_v3_logistics_prepare_requests as p', 'p.id', '=', 'd.prepare_request_id')
                ->where('p.source_type', 'stock_request')
                ->pluck('g.id');
            if ($grIds->isNotEmpty()) {
                $targets = $targets->merge(
                    WarehouseLedgerPosting::query()
                        ->where('status', 'posted')
                        ->where('movement_type', 'request_out')
                        ->where('reference_type', 'wh_v3_goods_receipt')
                        ->whereIn('reference_id', $grIds)
                        ->get()
                );
            }
        }

        // Legacy Stock Request DO -> request_out.
        if (Schema::hasTable('wh_delivery_orders')) {
            $deliveryIds = DB::table('wh_delivery_orders')->pluck('id');
            if ($deliveryIds->isNotEmpty()) {
                $targets = $targets->merge(
                    WarehouseLedgerPosting::query()
                        ->where('status', 'posted')
                        ->where('movement_type', 'request_out')
                        ->where('reference_type', 'wh_delivery_order')
                        ->whereIn('reference_id', $deliveryIds)
                        ->get()
                );
            }
        }

        // Reverse outbound first, then supplier purchase_in. This ordering returns any
        // reset Stock Request quantities before attempting to remove received stock.
        foreach ($targets->unique('id') as $posting) {
            $this->reversePostingOrBlock($posting, $userId, 'Reset transaksi Stock Request/GR');
        }

        if (Schema::hasTable('wh_stock_ins')) {
            $stockInIds = DB::table('wh_stock_ins')->pluck('id');
            if ($stockInIds->isNotEmpty()) {
                $purchasePostings = WarehouseLedgerPosting::query()
                    ->where('status', 'posted')
                    ->where('movement_type', 'purchase_in')
                    ->where('reference_type', 'wh_stock_in')
                    ->whereIn('reference_id', $stockInIds)
                    ->get();
                foreach ($purchasePostings as $posting) {
                    $this->reversePostingOrBlock($posting, $userId, 'Reset transaksi PR/PO/Stock In Warehouse');
                }
            }
        }
    }

    private function reversePostingOrBlock(WarehouseLedgerPosting $posting, string $userId, string $reason): void
    {
        try {
            $this->warehouseLedger->reverse(
                $posting,
                $userId,
                $reason.' | posting '.$posting->id,
                false,
                'RESET-TRANSACTION-REVERSAL:'.$posting->id,
            );
        } catch (ValidationException $exception) {
            $diagnostic = $this->postingDependencyDiagnostic($posting);
            throw ValidationException::withMessages([
                'reset' => [
                    'Reset diblokir karena Warehouse Ledger posting '.$posting->id.' belum aman untuk direversal. '.
                    ($diagnostic !== '' ? $diagnostic.' ' : '').
                    'Detail ledger: '.$exception->getMessage(),
                ],
            ]);
        }
    }

    private function deleteStockRequestCancellationRequests(): void
    {
        if(Schema::hasTable('stk_cancellation_requests')){
            DB::table('stk_cancellation_requests')->where('document_type','stock_request')->delete();
        }
        if(Schema::hasTable('wh_v3_sales_demand_events')){
            DB::table('wh_v3_sales_demand_events')->where('document_type','stock_request')->delete();
        }
    }

    private function deleteV3AndLegacyReceiptInventoryMovements(): void
    {
        if (! Schema::hasTable('stk_inventory_movements')) return;

        // Only remove outlet movement rows that belong to Stock Request GRs which are
        // actually reset. Sales Order / Transfer GRs must remain intact.
        $stockRequestGrIds = $this->stockRequestV3GoodsReceiptIds();
        if ($stockRequestGrIds->isNotEmpty()) {
            DB::table('stk_inventory_movements')
                ->where('reference_type', 'wh_v3_goods_receipt')
                ->whereIn('reference_id', $stockRequestGrIds)
                ->delete();
        }

        // Legacy Stock Inventory GR is fully part of the reset scope.
        DB::table('stk_inventory_movements')->where('reference_type', 'stk_goods_receipt')->delete();
    }

    private function deletePurchasingInvoicesDerivedFromResetDocuments(): void
    {
        if (! Schema::hasTable('pur_invoices')) return;

        $invoiceIds = collect();

        // Standard Purchasing Invoice generated from Purchasing Goods Receipt.
        if (Schema::hasTable('pur_goods_receipts')) {
            $grIds = DB::table('pur_goods_receipts')->pluck('id');
            if ($grIds->isNotEmpty()) {
                $invoiceIds = $invoiceIds->merge(
                    DB::table('pur_invoices')
                        ->where('source_document_kind', 'GOODS_RECEIPT')
                        ->whereIn('source_document_id', $grIds)
                        ->pluck('id')
                );
            }
        }

        // Incoming Purchasing bridge generated specifically from Warehouse Outgoing
        // Invoice of an Outlet Stock Request. Do not touch Sales Order invoices.
        if (Schema::hasTable('wh_v3_outgoing_invoices')) {
            $stockRequestOutgoingIds = DB::table('wh_v3_outgoing_invoices')
                ->where('source_type', 'stock_request')
                ->where('destination_type', 'outlet')
                ->pluck('id');
            if ($stockRequestOutgoingIds->isNotEmpty()) {
                $invoiceIds = $invoiceIds->merge(
                    DB::table('pur_invoices')
                        ->where('source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
                        ->whereIn('source_document_id', $stockRequestOutgoingIds)
                        ->pluck('id')
                );
            }
        }

        $invoiceIds = $invoiceIds->filter()->unique()->values();
        if ($invoiceIds->isEmpty()) return;

        if (Schema::hasTable('pur_invoice_events')) DB::table('pur_invoice_events')->whereIn('invoice_id', $invoiceIds)->delete();
        if (Schema::hasTable('pur_invoice_payments')) DB::table('pur_invoice_payments')->whereIn('invoice_id', $invoiceIds)->delete();
        if (Schema::hasTable('pur_invoice_items')) DB::table('pur_invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
        if (Schema::hasTable('pur_finance_posting_outbox')) {
            DB::table('pur_finance_posting_outbox')
                ->whereIn('aggregate_id', $invoiceIds)
                ->whereIn('aggregate_type', ['PURCHASING_INVOICE', 'PURCHASING_INVOICE_PAYMENT'])
                ->delete();
        }
        DB::table('pur_invoices')->whereIn('id', $invoiceIds)->delete();
    }

    private function stockRequestV3GoodsReceiptIds(): Collection
    {
        if (! Schema::hasTable('wh_v3_logistics_prepare_requests')
            || ! Schema::hasTable('wh_v3_delivery_orders')
            || ! Schema::hasTable('wh_v3_goods_receipts')) {
            return collect();
        }

        return DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->join('wh_v3_logistics_prepare_requests as p', 'p.id', '=', 'd.prepare_request_id')
            ->where('p.source_type', 'stock_request')
            ->pluck('g.id')
            ->filter()
            ->values();
    }

    private function v3StockRequestCounts(): array
    {
        if (! Schema::hasTable('wh_v3_logistics_prepare_requests')) return [];

        $prepareIds = DB::table('wh_v3_logistics_prepare_requests')
            ->where('source_type', 'stock_request')
            ->pluck('id')
            ->filter()
            ->values();
        $doIds = $this->pluckIf('wh_v3_delivery_orders', 'id', 'prepare_request_id', $prepareIds);
        $grIds = $this->pluckIf('wh_v3_goods_receipts', 'id', 'delivery_order_id', $doIds);
        $invoiceIds = $this->pluckIf('wh_v3_outgoing_invoices', 'id', 'goods_receipt_id', $grIds);

        return [
            'wh_v3_outgoing_invoice_items' => $this->countIfIn('wh_v3_outgoing_invoice_items', 'outgoing_invoice_id', $invoiceIds),
            'wh_v3_outgoing_invoices' => $invoiceIds->count(),
            'wh_v3_goods_receipt_items' => $this->countIfIn('wh_v3_goods_receipt_items', 'goods_receipt_id', $grIds),
            'wh_v3_goods_receipts' => $grIds->count(),
            'wh_v3_delivery_order_items' => $this->countIfIn('wh_v3_delivery_order_items', 'delivery_order_id', $doIds),
            'wh_v3_delivery_orders' => $doIds->count(),
            'wh_v3_logistics_prepare_items' => $this->countIfIn('wh_v3_logistics_prepare_items', 'prepare_request_id', $prepareIds),
            'wh_v3_logistics_prepare_requests' => $prepareIds->count(),
        ];
    }

    private function deleteV3Logistics(): void
    {
        if (! Schema::hasTable('wh_v3_logistics_prepare_requests')) return;

        // Stage 02 resets Logistics v3 only for documents descended from Stock Request.
        // Sales Order and Transfer Stock are intentionally excluded because deleting their
        // completed GR while keeping posted Warehouse Ledger rows would make a re-run capable
        // of double posting physical stock under a new GR/idempotency key.
        $prepareIds = DB::table('wh_v3_logistics_prepare_requests')
            ->where('source_type', 'stock_request')
            ->pluck('id')
            ->filter()
            ->values();
        if ($prepareIds->isEmpty()) return;

        $doIds = $this->pluckIf('wh_v3_delivery_orders', 'id', 'prepare_request_id', $prepareIds);
        $grIds = $this->pluckIf('wh_v3_goods_receipts', 'id', 'delivery_order_id', $doIds);
        $invoiceIds = $this->pluckIf('wh_v3_outgoing_invoices', 'id', 'goods_receipt_id', $grIds);

        if ($invoiceIds->isNotEmpty()) {
            if (Schema::hasTable('wh_v3_invoice_approvals')) {
                DB::table('wh_v3_invoice_approvals')
                    ->where('document_source', 'auto_outgoing')
                    ->whereIn('document_id', $invoiceIds)
                    ->delete();
            }
            if (Schema::hasTable('wh_v3_finance_events')) {
                DB::table('wh_v3_finance_events')
                    ->where('document_source', 'auto_outgoing')
                    ->whereIn('document_id', $invoiceIds)
                    ->delete();
            }
            if (Schema::hasTable('wh_v3_outgoing_invoice_items')) {
                DB::table('wh_v3_outgoing_invoice_items')->whereIn('outgoing_invoice_id', $invoiceIds)->delete();
            }
            if (Schema::hasTable('wh_v3_outgoing_invoices')) {
                DB::table('wh_v3_outgoing_invoices')->whereIn('id', $invoiceIds)->delete();
            }
        }

        if ($grIds->isNotEmpty()) {
            if (Schema::hasTable('wh_v3_logistics_events')) {
                DB::table('wh_v3_logistics_events')
                    ->where('document_type', 'goods_receipt')
                    ->whereIn('document_id', $grIds)
                    ->delete();
            }
            if (Schema::hasTable('wh_v3_goods_receipt_items')) {
                DB::table('wh_v3_goods_receipt_items')->whereIn('goods_receipt_id', $grIds)->delete();
            }
            if (Schema::hasTable('wh_v3_goods_receipts')) {
                DB::table('wh_v3_goods_receipts')->whereIn('id', $grIds)->delete();
            }
        }

        if ($doIds->isNotEmpty()) {
            if (Schema::hasTable('wh_v3_logistics_events')) {
                DB::table('wh_v3_logistics_events')
                    ->where('document_type', 'delivery_order')
                    ->whereIn('document_id', $doIds)
                    ->delete();
            }
            if (Schema::hasTable('wh_v3_delivery_order_items')) {
                DB::table('wh_v3_delivery_order_items')->whereIn('delivery_order_id', $doIds)->delete();
            }
            if (Schema::hasTable('wh_v3_delivery_orders')) {
                DB::table('wh_v3_delivery_orders')->whereIn('id', $doIds)->delete();
            }
        }

        if (Schema::hasTable('wh_v3_logistics_events')) {
            DB::table('wh_v3_logistics_events')
                ->where('document_type', 'prepare_request')
                ->whereIn('document_id', $prepareIds)
                ->delete();
        }
        if (Schema::hasTable('wh_v3_logistics_prepare_items')) {
            DB::table('wh_v3_logistics_prepare_items')->whereIn('prepare_request_id', $prepareIds)->delete();
        }
        DB::table('wh_v3_logistics_prepare_requests')->whereIn('id', $prepareIds)->delete();
    }

    private function pluckIf(string $table,string $select,string $whereColumn,Collection $ids): Collection
    {
        if(!Schema::hasTable($table) || $ids->isEmpty()) return collect();
        return DB::table($table)->whereIn($whereColumn,$ids)->pluck($select)->filter()->values();
    }

    private function countIfIn(string $table,string $whereColumn,Collection $ids): int
    {
        if(!Schema::hasTable($table) || $ids->isEmpty()) return 0;
        return DB::table($table)->whereIn($whereColumn,$ids)->count();
    }
}
