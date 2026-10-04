<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Services\Reporting\ReportMonthlySummaryService;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosV11I01ReadinessCheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i01-check {--month= : Month to probe in YYYY-MM, defaults to previous closed month}';

    protected $description = 'Verify V11 I01 real-readiness contract without modifying materialized data or refresh flags.';

    public function handle(
        ReportDailySummaryService $daily,
        ReportMonthlySummaryService $monthly,
        ReportingMaterializationOrchestrator $orchestrator,
    ): int {
        $checks = [];

        $readinessSource = $this->read(base_path('app/Services/Reporting/ReportReadinessService.php'));
        $dailySource = $this->read(base_path('app/Services/ReportDailySummaryService.php'));
        $monthlySource = $this->read(base_path('app/Services/Reporting/ReportMonthlySummaryService.php'));
        $orchestratorSource = $this->read(base_path('app/Services/Reporting/ReportingMaterializationOrchestrator.php'));

        $checks['Readiness service exists and is read-only'] = $readinessSource !== ''
            && str_contains($readinessSource, 'class ReportReadinessService')
            && str_contains($readinessSource, 'effective_pending_refresh_rows')
            && ! str_contains($readinessSource, "->delete()")
            && ! str_contains($readinessSource, 'refreshExactCoverage(');
        $checks['Daily compatibility coverage_percent follows readiness'] = str_contains($dailySource, "'coverage_percent' => (float) (\$matrix['readiness_percent'] ?? 0.0)")
            && str_contains($dailySource, "'physical_coverage_percent'")
            && str_contains($dailySource, "'stale_refresh_flag_rows'");
        $checks['Monthly readiness is gated by daily real readiness'] = str_contains($monthlySource, 'dailyMatrixStatus(')
            && str_contains($monthlySource, "'daily_not_ready'")
            && str_contains($monthlySource, 'dirty_outlet_ids');
        $checks['Console does not average readiness into a false 100%'] = str_contains($orchestratorSource, 'min($dailyPercent,$hourlyPercent')
            && str_contains($orchestratorSource, "'recommended_action'")
            && str_contains($orchestratorSource, "'effective_pending_refresh_rows'");

        foreach ($checks as $label => $ok) {
            $ok ? $this->components->info("PASS — {$label}") : $this->components->error("FAIL — {$label}");
        }

        if (in_array(false, $checks, true)) {
            return self::FAILURE;
        }

        if (! Schema::hasTable('outlets') || ! Schema::hasTable('report_daily_summary_coverage')) {
            $this->components->warn('Runtime probe skipped: reporting tables are not available on this database. Static contract checks passed.');
            return self::SUCCESS;
        }

        $monthOption = trim((string) $this->option('month'));
        if ($monthOption !== '' && ! preg_match('/^\d{4}-\d{2}$/', $monthOption)) {
            $this->components->error('Option --month must use YYYY-MM.');
            return self::FAILURE;
        }

        $timezone = (string) config('app.timezone', 'Asia/Jakarta');
        $month = $monthOption !== ''
            ? CarbonImmutable::parse($monthOption . '-01', $timezone)->startOfMonth()
            : CarbonImmutable::now($timezone)->startOfMonth()->subMonth();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $outletIds = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($outletIds === []) {
            $this->components->warn('Runtime probe skipped: no active outlet scope found.');
            return self::SUCCESS;
        }

        $dailyStatus = $daily->readContractStatus($outletIds, $from, $to, $timezone);
        $monthlyStatus = Schema::hasTable('report_monthly_summary_coverage')
            ? $monthly->readContractStatus($outletIds, $from)
            : null;
        $monthStatus = $orchestrator->monthReadiness($month->format('Y-m'));

        $this->newLine();
        $this->line('Runtime probe: ' . $month->format('Y-m'));
        $this->table(
            ['Metric', 'Value'],
            [
                ['Physical daily coverage', number_format((float) ($dailyStatus['physical_coverage_percent'] ?? 0), 2) . '%'],
                ['Real daily readiness', number_format((float) ($dailyStatus['coverage_percent'] ?? 0), 2) . '%'],
                ['Raw pending flags', (string) ($dailyStatus['raw_pending_refresh_rows'] ?? 0)],
                ['Effective pending', (string) ($dailyStatus['effective_pending_refresh_rows'] ?? 0)],
                ['Stale flags', (string) ($dailyStatus['stale_refresh_flag_rows'] ?? 0)],
                ['Premature rows', (string) ($dailyStatus['premature_rows'] ?? 0)],
                ['Monthly ready', $monthlyStatus ? ((bool) ($monthlyStatus['ready'] ?? false) ? 'YES' : 'NO') : 'N/A'],
                ['Console readiness', number_format((float) ($monthStatus['overall_percent'] ?? 0), 2) . '%'],
                ['Console status', (string) ($monthStatus['status'] ?? '-')],
            ],
        );

        $invariants = [
            'Daily READY implies 100% readiness' => ! (bool) ($dailyStatus['ready'] ?? false)
                || abs((float) ($dailyStatus['coverage_percent'] ?? 0) - 100.0) < 0.001,
            'Daily READY implies zero effective pending' => ! (bool) ($dailyStatus['ready'] ?? false)
                || (int) ($dailyStatus['effective_pending_refresh_rows'] ?? 0) === 0,
            'Monthly READY cannot bypass daily readiness' => $monthlyStatus === null
                || ! (bool) ($monthlyStatus['ready'] ?? false)
                || (bool) ($dailyStatus['ready'] ?? false),
            'Console READY means 100% final readiness' => ! in_array((string) ($monthStatus['status'] ?? ''), ['READY', 'LIVE'], true)
                || abs((float) ($monthStatus['overall_percent'] ?? 0) - 100.0) < 0.001,
        ];

        $failed = false;
        foreach ($invariants as $label => $ok) {
            $ok ? $this->components->info("PASS — {$label}") : $this->components->error("FAIL — {$label}");
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V11 I01 Real Readiness Contract is installed correctly.');
        $this->comment('This command is read-only: it does not clear pending flags or rebuild summaries.');

        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
