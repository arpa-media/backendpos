<?php

namespace App\Console\Commands;

use App\Services\Purchasing\WarehouseStockRequestLiabilityOwnershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpV5I06FinanceHardeningReconcileCommand extends Command
{
    protected $signature = 'erp-v5:i06-finance-hardening-reconcile
        {--from= : Invoice date mulai YYYY-MM-DD}
        {--to= : Invoice date sampai YYYY-MM-DD}
        {--invoice= : Invoice ID tertentu}
        {--limit=500 : Maksimum invoice yang diproses}
        {--dry-run : Audit tanpa mengubah data}';

    protected $description = 'ERP-V5 I06 audit/cover Warehouse Stock Request incoming invoice sebagai mirror liability Order AP canonical.';

    public function handle(WarehouseStockRequestLiabilityOwnershipService $ownership): int
    {
        foreach (['pur_invoices','wh_v3_outgoing_invoices','pur_order_ap_lifecycles','pur_purchase_orders','pur_invoice_liability_ownerships'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Tabel {$table} belum tersedia. Jalankan migration I06 terlebih dahulu.");
                return self::FAILURE;
            }
        }

        $limit = max(1, min((int) $this->option('limit'), 10000));
        $query = DB::table('pur_invoices as i')
            ->join('wh_v3_outgoing_invoices as w', 'w.id', '=', 'i.source_document_id')
            ->leftJoin('pur_invoice_liability_ownerships as own', 'own.invoice_id', '=', 'i.id')
            ->where('i.direction', 'INCOMING')
            ->where('i.source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
            ->whereRaw("LOWER(COALESCE(w.source_type,'')) = 'stock_request'")
            ->whereRaw("LOWER(COALESCE(w.destination_type,'')) = 'outlet'")
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', ['ISSUED','PARTIALLY_PAID','PAID','MIRROR'])
            ->orderBy('i.invoice_date')
            ->orderBy('i.created_at');

        if ($this->option('invoice')) $query->where('i.id', (string) $this->option('invoice'));
        if ($this->option('from')) $query->whereDate('i.invoice_date', '>=', (string) $this->option('from'));
        if ($this->option('to')) $query->whereDate('i.invoice_date', '<=', (string) $this->option('to'));

        $rows = $query->limit($limit)->get([
            'i.id','i.invoice_number','i.invoice_date','i.status','i.journal_status','i.journal_reference',
            'w.id as warehouse_invoice_id','w.source_id as stock_request_id','w.source_number as stock_request_number',
            'own.coverage_status as current_coverage_status','own.canonical_ap_invoice_id',
        ]);

        if ($rows->isEmpty()) {
            $this->info('Tidak ada Warehouse Stock Request Incoming Invoice pada scope yang dipilih.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $preview = [];
        $covered = 0;
        $conflicts = 0;
        $pendingOwner = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $postedMirror = Schema::hasTable('finance_purchasing_postings')
                ? DB::table('finance_purchasing_postings')->where('invoice_id', $row->id)->where('event_type', 'INVOICE_ISSUED')->where('status', 'POSTED')->exists()
                : false;

            if ($dryRun) {
                $lifecycle = DB::table('pur_order_ap_lifecycles as al')
                    ->join('pur_purchase_orders as po', 'po.id', '=', 'al.order_id')
                    ->where('al.order_kind', 'PURCHASE_ORDER')
                    ->where('po.stock_request_id', $row->stock_request_id)
                    ->orderByDesc('al.created_at')
                    ->first(['al.invoice_id','al.recognition_event_key','al.recognition_journal_no','al.recognition_posting_status']);
                $classification = $postedMirror
                    ? 'LEGACY_POSTED_CONFLICT'
                    : ($lifecycle ? 'COVERED_BY_ORDER_AP' : 'OWNER_PENDING');
                $preview[] = [
                    $row->invoice_date,
                    $row->invoice_number,
                    $row->status,
                    $row->stock_request_number ?: $row->stock_request_id,
                    $lifecycle?->invoice_id ?: '-',
                    $lifecycle?->recognition_journal_no ?: '-',
                    $classification,
                ];
                continue;
            }

            try {
                $result = $ownership->coverIfWarehouseStockRequestMirror((string) $row->id, null);
                $status = (string) ($result['coverage_status'] ?? 'UNKNOWN');
                if ($status === WarehouseStockRequestLiabilityOwnershipService::STATUS_COVERED) $covered++;
                elseif ($status === WarehouseStockRequestLiabilityOwnershipService::STATUS_LEGACY_CONFLICT) $conflicts++;
                elseif ($status === WarehouseStockRequestLiabilityOwnershipService::STATUS_OWNER_PENDING) $pendingOwner++;
            } catch (Throwable $e) {
                $failed++;
                $this->error($row->invoice_number . ': ' . $e->getMessage());
            }
        }

        if ($dryRun) {
            $this->table(['Tanggal','Warehouse Invoice','Status','Stock Request','Canonical AP ID','Jurnal AP','Klasifikasi'], $preview);
            $this->newLine();
            $this->warn('DRY RUN: tidak ada jurnal yang dibalik/dihapus. LEGACY_POSTED_CONFLICT hanya akan dicatat sebagai conflict audit oleh I06.');
            return self::SUCCESS;
        }

        $this->table(['Result','Jumlah'], [
            ['COVERED', $covered],
            ['OWNER_PENDING', $pendingOwner],
            ['LEGACY_POSTED_CONFLICT', $conflicts],
            ['FAILED', $failed],
        ]);
        if ($conflicts > 0) {
            $this->warn('Legacy conflict tidak dibalik otomatis. Karena database akan direset, gunakan laporan ini sebagai audit sebelum reset.');
        }
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
