<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportSmartRepairService;
use Illuminate\Console\Command;

class ErpPosV11I03SmartRepairCommand extends Command
{
    protected $signature = 'erp-pos-v11:i03-smart-repair
        {--month= : Bulan YYYY-MM}
        {--execute : Jalankan selective force + verify}
        {--limit=2000 : Maksimum marker yang diproses, maksimal 5000}
        {--outlet=* : Opsional outlet ID tertentu}';

    protected $description = 'V11 I03 selective Smart Repair untuk NEEDS_REBUILD hasil reconciliation.';

    public function handle(ReportSmartRepairService $service): int
    {
        $month = trim((string) $this->option('month'));
        if ($month === '') {
            $this->error('Gunakan --month=YYYY-MM');
            return self::INVALID;
        }

        $outletIds = array_values(array_filter(array_map('strval', (array) $this->option('outlet'))));
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $execute = (bool) $this->option('execute');

        $this->info('ERP POS V11 I03 — Smart Repair ' . ($execute ? '[EXECUTE]' : '[PLAN]'));
        $this->line('Month : ' . $month);
        $this->line('Limit : ' . number_format($limit));
        if ($outletIds !== []) {
            $this->line('Outlet: ' . implode(', ', $outletIds));
        }

        try {
            if (! $execute) {
                $result = $service->planMonth($month, $outletIds ?: null, $limit, null);
                $this->table(['Metric', 'Value'], [
                    ['Reconciliation Run', (string) ($result['reconciliation_run_id'] ?? '-')],
                    ['Candidate', (int) ($result['candidate_rows'] ?? 0)],
                    ['Safe without rebuild', (int) ($result['safe_without_rebuild'] ?? 0)],
                    ['Needs rebuild', (int) ($result['needs_rebuild_rows'] ?? 0)],
                    ['Race changed', (int) ($result['race_changed_rows'] ?? 0)],
                    ['Force full month', ($result['will_force_full_month'] ?? false) ? 'YES' : 'NO'],
                    ['Next action', (string) ($result['next_action'] ?? '-')],
                ]);
                $this->comment('PLAN mode tidak melakukan rebuild. Gunakan --execute untuk selective repair.');
                return self::SUCCESS;
            }

            $result = $service->repairMonth($month, $outletIds ?: null, $limit, null);
            $this->newLine();
            $this->table(['Metric', 'Value'], [
                ['Run ID', (string) ($result['run_id'] ?? '-')],
                ['Reconciliation Run', (string) ($result['reconciliation_run_id'] ?? '-')],
                ['Candidate repair', (int) ($result['candidate_rows'] ?? 0)],
                ['Repaired', (int) ($result['repaired_rows'] ?? 0)],
                ['Verified', (int) ($result['verified_rows'] ?? 0)],
                ['Race changed', (int) ($result['race_changed_rows'] ?? 0)],
                ['Failed', (int) ($result['failed_rows'] ?? 0)],
                ['Already resolved', (int) ($result['already_resolved_rows'] ?? 0)],
                ['Effective pending', (int) ($result['effective_pending_refresh_rows'] ?? 0)],
                ['Raw pending', (int) ($result['raw_pending_refresh_rows'] ?? 0)],
                ['Daily readiness', number_format((float) ($result['final_daily_readiness_percent'] ?? 0), 2) . '%'],
                ['Monthly readiness', number_format((float) ($result['final_monthly_readiness_percent'] ?? 0), 2) . '%'],
                ['Final readiness', number_format((float) ($result['final_readiness_percent'] ?? 0), 2) . '%'],
                ['Final ready', ($result['final_ready'] ?? false) ? 'YES' : 'NO'],
                ['Next action', (string) ($result['next_action'] ?? '-')],
            ]);

            if (! ($result['final_ready'] ?? false)) {
                $this->warn('Belum 100% READY. Jangan hapus queue manual; periksa failed/race/remaining readiness issue.');
            } else {
                $this->info('Smart Repair selesai dan kontrak readiness bulan ini lulus.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
