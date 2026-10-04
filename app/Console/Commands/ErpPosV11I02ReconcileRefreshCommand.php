<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportRefreshReconciliationService;
use Illuminate\Console\Command;

class ErpPosV11I02ReconcileRefreshCommand extends Command
{
    protected $signature = 'erp-pos-v11:i02-reconcile
        {--month= : Bulan YYYY-MM}
        {--resolve : Hapus hanya marker yang terbukti aman}
        {--limit=2000 : Maksimum queue row yang diperiksa, maksimal 5000}';

    protected $description = 'V11 I02 preview/resolve refresh queue secara terverifikasi dan race-safe.';

    public function handle(ReportRefreshReconciliationService $service): int
    {
        $month = trim((string) $this->option('month'));
        if ($month === '') {
            $this->error('Gunakan --month=YYYY-MM');
            return self::INVALID;
        }

        $resolve = (bool) $this->option('resolve');
        $limit = max(1, min(5000, (int) $this->option('limit')));

        $this->info(sprintf('ERP POS V11 I02 - Refresh Reconciliation [%s]', $resolve ? 'RESOLVE' : 'PREVIEW'));
        $this->line('Month : ' . $month);
        $this->line('Limit : ' . number_format($limit));
        if (! $resolve) {
            $this->comment('PREVIEW mode: tidak ada queue flag yang dihapus.');
        }

        try {
            $result = $service->reconcileMonth($month, null, $resolve, $limit, null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Run ID', (string) ($result['run_id'] ?? '-')],
            ['Candidate', (int) ($result['candidate_rows'] ?? 0)],
            ['Stale flag', (int) ($result['stale_flag_rows'] ?? 0)],
            ['Verified OK', (int) ($result['verified_ok_rows'] ?? 0)],
            ['Needs rebuild', (int) ($result['needs_rebuild_rows'] ?? 0)],
            ['Race changed', (int) ($result['race_changed_rows'] ?? 0)],
            ['Resolvable', (int) ($result['resolvable_rows'] ?? 0)],
            ['Resolved', (int) ($result['resolved_rows'] ?? 0)],
            ['Remaining', (int) ($result['remaining_candidate_rows'] ?? 0)],
            ['Next action', (string) ($result['next_action'] ?? '-')],
        ]);

        if (! $resolve && (int) ($result['resolvable_rows'] ?? 0) > 0) {
            $this->comment('Jika hasil preview sesuai, jalankan ulang dengan --resolve untuk acknowledge marker aman.');
        }
        if ((int) ($result['needs_rebuild_rows'] ?? 0) > 0) {
            $this->warn('NEEDS_REBUILD tidak disentuh pada I02. Baris tersebut menjadi input Smart Repair I03.');
        }

        return self::SUCCESS;
    }
}
