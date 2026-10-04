<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Services\Reporting\ReportingMaterializationSelfHealingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosV11I06ReleaseGateCommand extends Command
{
    protected $signature = 'erp-pos-v11:release-gate
        {--month= : Closed month to verify in YYYY-MM format; defaults to previous month}
        {--runtime : Require scheduler and reporting worker heartbeat to be healthy}
        {--heal : Run non-destructive runtime self-healing before the gate}
        {--json : Print machine-readable JSON result}';

    protected $description = 'V11 I06 production release gate for reporting readiness, queue integrity, worker recovery and Console/Owner contract consistency.';

    private array $rows = [];

    public function handle(
        ReportingMaterializationOrchestrator $orchestrator,
        ReportingMaterializationSelfHealingService $selfHealing,
    ): int {
        $month = trim((string) ($this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Format --month harus YYYY-MM.');
            return self::FAILURE;
        }

        try {
            $monthStart = CarbonImmutable::parse($month.'-01')->startOfMonth();
        } catch (\Throwable) {
            $this->error('Nilai --month tidak valid.');
            return self::FAILURE;
        }

        if ($monthStart->greaterThanOrEqualTo(now()->startOfMonth())) {
            $this->error('Release gate harus memakai bulan yang sudah tutup.');
            return self::FAILURE;
        }

        if ((bool) $this->option('heal')) {
            $heal = $selfHealing->heal(true);
            if (! $this->option('json')) {
                $this->line('Self-healing: '.json_encode($heal, JSON_UNESCAPED_SLASHES));
            }
        }

        $this->checkSchema();
        $this->checkPatchChain();
        $this->checkQueueIntegrity($orchestrator, $selfHealing);

        $readiness = null;
        try {
            $readiness = $orchestrator->monthReadiness($month);
            $this->checkReadiness($readiness);
        } catch (\Throwable $e) {
            $this->add('Real Readiness Contract', false, 'Readiness query failed: '.mb_substr($e->getMessage(), 0, 300));
        }

        if ((bool) $this->option('runtime')) {
            $this->checkRuntime($orchestrator);
        }

        $failed = collect($this->rows)->where('pass', false)->count();
        $payload = [
            'contract' => 'erp_pos_v11_i06_release_gate',
            'month' => $month,
            'checks' => $this->rows,
            'readiness' => $readiness,
            'pass' => $failed === 0,
            'failed_checks' => $failed,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->newLine();
            $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $r) => [
                $r['name'], $r['pass'] ? 'PASS' : 'FAIL', $r['detail'],
            ], $this->rows));
            $this->newLine();
            $this->line($failed === 0
                ? '<info>RELEASE GATE: PASS</info>'
                : '<error>RELEASE GATE: FAIL ('.$failed.' check)</error>');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkSchema(): void
    {
        foreach ([
            'report_daily_summary_coverage',
            'report_daily_summary_refresh_queue',
            'report_hourly_summary_coverage',
            'report_monthly_summary_coverage',
            'report_materialization_settings',
            'report_materialization_runs',
            'report_materialization_run_chunks',
        ] as $table) {
            $this->add('Schema '.$table, Schema::hasTable($table), Schema::hasTable($table) ? 'ready' : 'missing');
        }
    }

    private function checkPatchChain(): void
    {
        $checks = [
            'I01 Real Readiness' => class_exists(\App\Services\Reporting\ReportReadinessService::class),
            'I02 Pending Reconciliation' => class_exists(\App\Services\Reporting\ReportRefreshReconciliationService::class),
            'I03 Smart Repair' => class_exists(\App\Services\Reporting\ReportSmartRepairService::class),
            'I04 Background Runner' => class_exists(\App\Jobs\Reporting\ContinueReportingMaterializationRunJob::class),
            'I06 Self Healing' => class_exists(ReportingMaterializationSelfHealingService::class),
        ];

        foreach ($checks as $name => $ok) {
            $this->add($name, $ok, $ok ? 'loaded' : 'class missing');
        }
    }

    private function checkQueueIntegrity(
        ReportingMaterializationOrchestrator $orchestrator,
        ReportingMaterializationSelfHealingService $selfHealing,
    ): void {
        $diag = $selfHealing->diagnostics();
        $this->add('Refresh Queue Integrity', (int) ($diag['stale_refresh_processing'] ?? 0) === 0,
            'stale processing='.(string) ($diag['stale_refresh_processing'] ?? 'n/a'));
        $this->add('Worker Lease Recovery', (int) ($diag['stale_chunk_leases'] ?? 0) === 0,
            'expired leases='.(string) ($diag['stale_chunk_leases'] ?? 'n/a'));
        $this->add('Single Active Run', (int) ($diag['active_runs'] ?? 0) <= 1,
            'active runs='.(string) ($diag['active_runs'] ?? 'n/a'));

        $duplicateDispatchTokens = 0;
        if (Schema::hasTable('report_materialization_run_chunks') && Schema::hasColumn('report_materialization_run_chunks', 'dispatch_token')) {
            $duplicateDispatchTokens = DB::table('report_materialization_run_chunks')
                ->whereNotNull('dispatch_token')
                ->select('dispatch_token')
                ->groupBy('dispatch_token')
                ->havingRaw('COUNT(*) > 1')
                ->get()->count();
        }
        $this->add('Duplicate Dispatch Guard', $duplicateDispatchTokens === 0, 'duplicate tokens='.$duplicateDispatchTokens);

        // Static contract check: I06 kickRun must reclaim expired leases even when
        // scheduler is temporarily unavailable.
        $source = @file_get_contents(app_path('Services/Reporting/ReportingMaterializationOrchestrator.php')) ?: '';
        $manualRecoveryWired = str_contains($source, 'manual/worker continuation is also a recovery boundary')
            && substr_count($source, 'recoverStaleLeasesLocked();') >= 2;
        $this->add('Manual Run Lease Recovery', $manualRecoveryWired, $manualRecoveryWired ? 'wired' : 'I06 kick recovery missing');
    }

    private function checkReadiness(array $r): void
    {
        $daily = (array) ($r['daily'] ?? []);
        $hourly = (array) ($r['hourly'] ?? []);
        $monthly = (array) ($r['monthly'] ?? []);
        $ready = (bool) ($r['ready'] ?? false);
        $percent = (float) ($r['readiness_percent'] ?? $r['overall_percent'] ?? 0);

        $expectedReady = (bool) ($daily['ready'] ?? false)
            && (bool) ($hourly['ready'] ?? false)
            && (bool) ($monthly['ready'] ?? false);
        $effectivePending = (int) ($daily['effective_pending_refresh_rows'] ?? 0);

        $this->add('Console Contract Consistency', $ready === $expectedReady,
            'console='.($ready ? 'READY' : 'NOT_READY').', layers='.($expectedReady ? 'READY' : 'NOT_READY'));
        $this->add('No False 100%', ! ($percent >= 100 && (! $ready || $effectivePending > 0)),
            'readiness='.$percent.'%, effective_pending='.$effectivePending);
        $this->add('Daily Freshness', (bool) ($daily['ready'] ?? false),
            'daily='.($daily['coverage_percent'] ?? $daily['readiness_percent'] ?? 0).'% pending='.$effectivePending);
        $this->add('Hourly Freshness', (bool) ($hourly['ready'] ?? false),
            'hourly='.($hourly['coverage_percent'] ?? $hourly['readiness_percent'] ?? 0).'%');
        $this->add('Monthly Dependency', (bool) ($monthly['ready'] ?? false),
            'monthly='.($monthly['coverage_percent'] ?? $monthly['readiness_percent'] ?? 0).'%');
        $this->add('Closed Month Readiness 100%', $ready && $percent >= 100,
            'month='.($r['month'] ?? '-').', status='.($r['status'] ?? '-').', readiness='.$percent.'%');

        $ownerSource = @file_get_contents(app_path('Services/OwnerOverviewService.php')) ?: '';
        $hotWindowSource = @file_get_contents(app_path('Services/Reporting/ReportHotWindowReadService.php')) ?: '';
        $ownerUsesHotWindow = str_contains($ownerSource, 'ReportHotWindowReadService') && str_contains($ownerSource, 'readContractStatus');
        $hotWindowUsesDaily = str_contains($hotWindowSource, 'dailySummaryService->readContractStatus');
        $this->add('Owner Overview Read Contract', $ownerUsesHotWindow && $hotWindowUsesDaily,
            ($ownerUsesHotWindow && $hotWindowUsesDaily) ? 'same daily readiness contract' : 'contract wiring mismatch');
    }

    private function checkRuntime(ReportingMaterializationOrchestrator $orchestrator): void
    {
        try {
            $live = $orchestrator->liveStatus();
            $health = (array) ($live['engine_health'] ?? []);
            $scheduler = (string) ($health['scheduler_state'] ?? 'UNKNOWN');
            $worker = (string) ($health['worker_state'] ?? 'WAITING_WORKER');
            $this->add('Scheduler Runtime', $scheduler === 'HEALTHY', 'state='.$scheduler);
            $this->add('Reporting Worker Runtime', in_array($worker, ['HEALTHY', 'BUSY'], true), 'state='.$worker);
        } catch (\Throwable $e) {
            $this->add('Runtime Health', false, mb_substr($e->getMessage(), 0, 300));
        }
    }

    private function add(string $name, bool $pass, string $detail): void
    {
        $this->rows[] = compact('name', 'pass', 'detail');
    }
}
