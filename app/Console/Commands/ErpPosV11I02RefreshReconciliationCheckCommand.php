<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryRefreshService;
use App\Services\Reporting\ReportCorrectnessAuditService;
use App\Services\Reporting\ReportRefreshReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosV11I02RefreshReconciliationCheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i02-check {--month= : Optional YYYY-MM queue snapshot}';

    protected $description = 'Read-only release check untuk V11 I02 pending refresh reconciliation.';

    public function handle(): int
    {
        $checks = [
            'reconciliation runs table' => Schema::hasTable('report_refresh_reconciliation_runs'),
            'reconciliation items table' => Schema::hasTable('report_refresh_reconciliation_items'),
            'daily refresh queue table' => Schema::hasTable('report_daily_summary_refresh_queue'),
            'daily coverage table' => Schema::hasTable('report_daily_summary_coverage'),
            'reconciliation service' => class_exists(ReportRefreshReconciliationService::class),
            'correctness compare method' => method_exists(ReportCorrectnessAuditService::class, 'compareRawToDailyDay'),
            'refresh worker service' => class_exists(ReportDailySummaryRefreshService::class),
        ];

        $this->info('ERP POS V11 I02 - Release Check');
        $this->table(['Check', 'Status'], array_map(
            fn ($label, $ok) => [$label, $ok ? 'PASS' : 'FAIL'],
            array_keys($checks),
            array_values($checks),
        ));

        $month = trim((string) $this->option('month'));
        if ($month !== '') {
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error('Format --month harus YYYY-MM.');
                return self::INVALID;
            }
            if (Schema::hasTable('report_daily_summary_refresh_queue')) {
                $from = $month . '-01';
                $to = date('Y-m-t', strtotime($from));
                $rows = DB::table('report_daily_summary_refresh_queue')
                    ->whereBetween('business_date', [$from, $to])
                    ->whereIn('status', ['pending', 'processing'])
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->orderBy('status')
                    ->get();
                $this->newLine();
                $this->line('Queue snapshot ' . $month . ' (read-only):');
                if ($rows->isEmpty()) {
                    $this->line('  pending/processing = 0');
                } else {
                    foreach ($rows as $row) {
                        $this->line(sprintf('  %s = %d', (string) $row->status, (int) $row->total));
                    }
                }
            }
        }

        $failed = array_filter($checks, fn ($ok) => ! $ok);
        $this->newLine();
        if ($failed !== []) {
            $this->error('V11 I02 CHECK: FAIL');
            return self::FAILURE;
        }

        $this->info('V11 I02 CHECK: PASS');
        $this->comment('Command ini tidak menghapus queue dan tidak menjalankan materialization.');
        return self::SUCCESS;
    }
}
