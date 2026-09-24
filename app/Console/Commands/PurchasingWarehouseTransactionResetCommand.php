<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PurchasingWarehouseTransactionResetCommand extends Command
{
    protected $signature = 'erp:reset-purchasing-warehouse
        {--scope=all : all|purchasing|warehouse}
        {--execute : Jalankan penghapusan; tanpa opsi ini hanya preview}
        {--include-posted : Sertakan GR/Stock In yang sudah posted}
        {--force : Wajib untuk --execute dan melewati environment guard}';

    protected $description = 'Preview atau hapus seluruh transaksi pengajuan, PR, PO, GR Purchasing dan Warehouse tanpa menghapus master data.';

    /** @var array<int,string> */
    private array $purchasingTables = [
        'pur_invoice_events','pur_invoice_payments','pur_invoice_items','pur_invoices','pur_finance_posting_outbox',
        'pur_execution_decisions','pur_service_entry_sheet_items','pur_service_entry_sheets','pur_goods_receipt_items','pur_goods_receipts','pur_service_acceptance_items','pur_service_acceptances','pur_reimburse_payment_items','pur_reimburse_payments',
        'pur_order_decisions','pur_purchase_order_items','pur_purchase_orders','pur_service_order_items','pur_service_orders','pur_reimburse_order_items','pur_reimburse_orders',
        'pur_fund_request_decisions','pur_document_events','pur_fund_request_items','pur_fund_requests',
        'pur_reconciliation_issue_items','pur_reconciliation_issues','pur_reconciliation_links','pur_reconciliation_runs',
    ];

    /** @var array<int,string> */
    private array $warehouseTables = [
        'wh_purchase_invoice_items','wh_purchase_invoices','wh_stock_in_items','wh_stock_ins',
        'wh_supplier_purchase_order_items','wh_supplier_purchase_orders',
        'wh_purchase_request_items','wh_purchase_requests',
        'wh_stock_request_fulfillment_barcodes','wh_stock_request_fulfillment_items','wh_stock_request_fulfillments','wh_stock_request_handoffs',
        'stk_request_timelines','stk_request_items','stk_requests',
    ];

    public function handle(): int
    {
        $scope = strtolower((string) $this->option('scope'));
        if (! in_array($scope, ['all','purchasing','warehouse'], true)) {
            $this->error('Scope harus all, purchasing, atau warehouse.');
            return self::INVALID;
        }

        $tables = match ($scope) {
            'purchasing' => $this->purchasingTables,
            'warehouse' => $this->warehouseTables,
            default => array_values(array_unique(array_merge($this->purchasingTables, $this->warehouseTables))),
        };
        $tables = array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));

        $rows = [];
        $total = 0;
        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $rows[] = [$table, $count];
            $total += $count;
        }
        $this->table(['Table','Rows'], $rows);
        $this->line("Total rows: {$total}");

        if (! $this->option('execute')) {
            $this->warn('PREVIEW ONLY. Tambahkan --execute --force untuk menghapus.');
            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->error('--force wajib untuk menjalankan reset.');
            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->option('force')) {
            throw new RuntimeException('Reset production diblokir.');
        }

        if (! $this->option('include-posted')) {
            foreach (['pur_goods_receipts','wh_stock_ins','stk_goods_receipts'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) continue;
                $posted = DB::table($table)->whereIn(DB::raw('UPPER(status)'), ['POSTED','APPROVED','COMPLETED'])->count();
                if ($posted > 0) {
                    $this->error("{$table} memiliki {$posted} dokumen posted. Gunakan --include-posted hanya untuk reset development setelah backup DB.");
                    return self::FAILURE;
                }
            }
        }

        DB::transaction(function () use ($tables): void {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($tables as $table) DB::table($table)->delete();
                if (Schema::hasTable('pur_document_sequences')) DB::table('pur_document_sequences')->delete();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }, 1);

        $this->info('Reset transaksi selesai. Master SKU, supplier, UOM, category, price list, stock balance, dan Access Matrix tidak dihapus.');
        if ($this->option('include-posted')) {
            $this->warn('Posted document ikut dihapus. Jalankan rekonsiliasi stock/valuation sebelum penggunaan kembali.');
        }
        return self::SUCCESS;
    }
}
