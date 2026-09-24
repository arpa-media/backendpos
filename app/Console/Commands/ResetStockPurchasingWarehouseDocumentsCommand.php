<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResetStockPurchasingWarehouseDocumentsCommand extends Command
{
    protected $signature = 'erp:reset-stock-purchasing-documents
        {--execute : Jalankan penghapusan. Tanpa opsi ini hanya preview}
        {--force : Lewati konfirmasi interaktif}
        {--include-posted : Izinkan menghapus GR/Stock In yang sudah posted/released}
        {--scope=all : all|stock-inventory|purchasing|warehouse}';

    protected $description = 'Reset dokumen PR, PO, GR dan Stock Request tanpa menghapus master, stock balance, valuation, atau jurnal.';

    /** @var array<string,array<int,string>> */
    private array $groups = [
        'purchasing' => [
            'pur_invoice_allocations', 'pur_invoice_items', 'pur_invoice_status_history', 'pur_invoices',
            'pur_payment_allocations', 'pur_payments',
            'pur_service_entry_sheet_items', 'pur_service_entry_sheets',
            'pur_goods_receipt_items', 'pur_goods_receipts',
            'pur_service_acceptance_items', 'pur_service_acceptances',
            'pur_reimburse_payment_items', 'pur_reimburse_payments',
            'pur_execution_decisions', 'pur_order_decisions',
            'pur_purchase_order_items', 'pur_purchase_orders',
            'pur_service_order_items', 'pur_service_orders',
            'pur_reimburse_order_items', 'pur_reimburse_orders',
            'pur_fund_request_items', 'pur_fund_request_events', 'pur_fund_requests',
        ],
        'warehouse' => [
            'wh_purchase_invoices',
            'wh_stock_in_items', 'wh_stock_ins',
            'wh_supplier_purchase_order_items', 'wh_supplier_purchase_orders',
            'wh_purchase_request_items', 'wh_purchase_requests',
            'wh_delivery_order_items', 'wh_delivery_orders',
            'wh_stock_request_handoffs',
        ],
        'stock-inventory' => [
            'stk_goods_receipt_items', 'stk_goods_receipts',
            'stk_request_timelines', 'stk_request_items', 'stk_requests',
            'wh_stock_request_timelines', 'wh_stock_request_items', 'wh_stock_requests',
        ],
    ];

    public function handle(): int
    {
        $scope = strtolower((string) $this->option('scope'));
        if (! in_array($scope, ['all', 'stock-inventory', 'purchasing', 'warehouse'], true)) {
            $this->error('Scope harus: all, stock-inventory, purchasing, atau warehouse.');
            return self::FAILURE;
        }

        $groups = $scope === 'all' ? array_keys($this->groups) : [$scope];
        $tables = [];
        foreach ($groups as $group) {
            foreach ($this->groups[$group] as $table) {
                if (Schema::hasTable($table) && ! in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }

        $rows = [];
        $total = 0;
        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $rows[] = [$table, $count];
            $total += $count;
        }
        $this->table(['Table', 'Rows'], $rows);
        $this->line('Total rows: ' . $total);

        if (! $this->option('execute')) {
            $this->warn('PREVIEW ONLY. Tambahkan --execute untuk menghapus.');
            return self::SUCCESS;
        }

        if (! $this->option('include-posted') && $this->hasPostedDocuments()) {
            $this->error('Terdapat GR/Stock In posted atau released. Gunakan --include-posted hanya setelah backup dan bila reset environment memang disengaja.');
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Hapus seluruh dokumen pada scope ' . $scope . '? Master dan saldo stok tidak dihapus.')) {
            $this->warn('Dibatalkan.');
            return self::SUCCESS;
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Reset selesai. Master data, stock balance, valuation, movement, dan jurnal tidak disentuh.');
        if ($this->option('include-posted')) {
            $this->warn('Karena posted document dihapus, jalankan rekonsiliasi stock/valuation sebelum transaksi produksi berikutnya.');
        }
        return self::SUCCESS;
    }

    private function hasPostedDocuments(): bool
    {
        $checks = [
            ['pur_goods_receipts', ['POSTED', 'APPROVED']],
            ['stk_goods_receipts', ['released', 'posted', 'approved']],
            ['wh_stock_ins', ['posted', 'approved', 'completed']],
        ];
        foreach ($checks as [$table, $statuses]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }
            if (DB::table($table)->whereIn('status', $statuses)->exists()) {
                return true;
            }
        }
        return false;
    }
}
