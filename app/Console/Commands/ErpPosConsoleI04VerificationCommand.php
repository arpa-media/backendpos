<?php

namespace App\Console\Commands;

use App\Jobs\Reporting\ProcessReportingMaterializationChunkJob;
use App\Services\Reporting\ReportHotWindowReadService;
use App\Services\Reporting\ReportMonthlySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\ReportingScheduleRegistry;
use App\Support\TransactionDate;
use App\Support\UserManagementCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpPosConsoleI04VerificationCommand extends Command
{
    protected $signature = 'erp-pos-console:i04-verify
        {--month= : Closed month YYYY-MM used for Monthly aggregate/per-outlet parity}
        {--outlet=* : Optional outlet ULID(s) used for Monthly parity}';

    protected $description = 'I04 deployment gate for Console routing, 3-day live read boundary, cache freshness, monthly parity, scheduler uniqueness, and reporting queue.';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(
        ReportHotWindowReadService $hotWindow,
        ReportMonthlySummaryService $monthly,
    ): int {
        $timezone = TransactionDate::appTimezone();
        $today = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);

        $this->info('ERP POS BACKOFFICE — CONSOLE / REPORTING I04 VERIFICATION');
        $this->line('Timezone: '.$timezone.' | Business date: '.$today->toDateString());

        $this->section('1. Console Maintenance route & Access Matrix');
        $this->verifyMaintenanceContract();

        $this->section('2. Live Hot-Window max 3 business dates');
        $this->verifyHotWindow($hotWindow, $today, $timezone);

        $this->section('3. Reporting-aware cache boundary');
        $this->verifyCacheBoundary();

        $this->section('4. Consumer no-recovery wiring');
        $this->verifyConsumers();

        $this->section('5. Monthly aggregate / per-outlet readiness parity');
        $this->verifyMonthlyParity($monthly, $today);

        $this->section('6. Scheduler registration uniqueness');
        $this->verifySchedulerRegistration();

        $this->section('7. Reporting queue / runtime prerequisites');
        $this->verifyQueueConfiguration();

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("I04 RESULT: FAIL — {$this->failures} failure(s), {$this->warnings} warning(s).");
            $this->line('Resolve FAIL items before considering Console/Reporting deployment verified.');
            return self::FAILURE;
        }

        $this->info("I04 RESULT: PASS — all required invariants passed ({$this->warnings} warning(s)).");
        return self::SUCCESS;
    }

    private function verifyMaintenanceContract(): void
    {
        $menus = collect(UserManagementCatalog::menus());
        $maintenance = $menus->firstWhere('code', 'console-maintenance');
        $this->check(
            'Access Matrix catalog contains Console > Maintenance',
            is_array($maintenance)
                && ($maintenance['path'] ?? null) === '/console/maintenance'
                && ($maintenance['permission_view'] ?? null) === 'console.maintenance.view'
                && ($maintenance['permission_update'] ?? null) === 'console.maintenance.manage',
            is_array($maintenance) ? (string) ($maintenance['path'] ?? '') : 'missing'
        );

        foreach ([
            'console.maintenance.status.hotfix-i01',
            'console.maintenance.show.hotfix-i01',
            'console.maintenance.update.hotfix-i01',
        ] as $routeName) {
            $this->check('Backend route '.$routeName, Route::has($routeName), Route::has($routeName) ? 'registered' : 'missing');
        }

        $frontendRoute = $this->frontendFile('src/modules/console/routes.js');
        if ($frontendRoute === null) {
            $this->recordWarning('Frontend /console/maintenance route source not locally inspectable', 'split deployment is allowed; verify the deployed frontend bundle separately');
        } else {
            $source = (string) @file_get_contents($frontendRoute);
            $this->check(
                'Frontend /console/maintenance route source',
                str_contains($source, "path: '/console/maintenance'")
                    && str_contains($source, "name: 'console-maintenance'")
                    && str_contains($source, 'MaintenancePage'),
                $frontendRoute
            );
        }

        try {
            $this->check(
                'Persisted Access Matrix row Console > Maintenance',
                Schema::hasTable('access_menus')
                    && DB::table('access_menus')->where('code', 'console-maintenance')->where('path', '/console/maintenance')->exists(),
                'access_menus.console-maintenance'
            );
        } catch (Throwable $e) {
            $this->check('Persisted Access Matrix row Console > Maintenance', false, $this->shortError($e));
        }
    }

    private function verifyHotWindow(ReportHotWindowReadService $hotWindow, CarbonImmutable $today, string $timezone): void
    {
        $todayString = $today->toDateString();
        $h1 = $today->subDay()->toDateString();
        $h2 = $today->subDays(2)->toDateString();
        $h3 = $today->subDays(3)->toDateString();
        $monthFrom = $today->subDays(29)->toDateString();

        $this->check('HOT_WINDOW_DAYS = 3', ReportHotWindowReadService::HOT_WINDOW_DAYS === 3, 'configured='.ReportHotWindowReadService::HOT_WINDOW_DAYS);

        $plans = [
            'Today selects LIVE' => [$hotWindow->readPlan($todayString, $todayString, $timezone), 'live'],
            'H-1 selects LIVE' => [$hotWindow->readPlan($h1, $h1, $timezone), 'live'],
            'H-2 selects LIVE' => [$hotWindow->readPlan($h2, $h2, $timezone), 'live'],
            'H-3 selects MATERIALIZED' => [$hotWindow->readPlan($h3, $h3, $timezone), 'materialized'],
            '30-day range ending today selects HYBRID' => [$hotWindow->readPlan($monthFrom, $todayString, $timezone), 'hybrid'],
        ];
        foreach ($plans as $label => [$plan, $expectedMode]) {
            $ok = ($plan['mode'] ?? null) === $expectedMode;
            if ($expectedMode === 'hybrid') {
                $ok = $ok && (int) ($plan['live_days'] ?? 0) === 3;
            }
            $this->check($label, $ok, 'mode='.($plan['mode'] ?? '?').'; live_days='.(int) ($plan['live_days'] ?? 0));
        }

        try {
            // Live-only status never queries historical coverage, so this directly proves
            // that current business date cannot become HTTP 409 solely due to stale materialization.
            $status = $hotWindow->readContractStatus(['__I04_LIVE_PROBE__'], $todayString, $todayString, $timezone);
            $this->check(
                'Current business date no-409 invariant',
                ($status['ready'] ?? false) === true
                    && ($status['read_mode'] ?? null) === 'live'
                    && ($status['recovery_pipeline'] ?? null) === null
                    && ($status['http_backfill'] ?? true) === false,
                'ready='.json_encode($status['ready'] ?? null).'; state='.($status['state'] ?? '?').'; recovery='.($status['recovery_pipeline'] ?? 'none')
            );
        } catch (Throwable $e) {
            $this->check('Current business date no-409 invariant', false, $this->shortError($e));
        }
    }

    private function verifyCacheBoundary(): void
    {
        $liveTtl = AnalyticsResponseCache::reportingTtlSeconds(['read_mode' => 'live']);
        $hybridTtl = AnalyticsResponseCache::reportingTtlSeconds(['read_mode' => 'hybrid']);
        $historicalTtl = AnalyticsResponseCache::reportingTtlSeconds(['read_mode' => 'materialized']);

        $this->check('LIVE cache TTL = 30s', $liveTtl === 30, "ttl={$liveTtl}");
        $this->check('HYBRID cache TTL = 30s', $hybridTtl === 30, "ttl={$hybridTtl}");
        $this->check('Historical materialized cache TTL >= 15m', $historicalTtl >= 900, "ttl={$historicalTtl}");

        $cacheSource = (string) @file_get_contents(app_path('Support/AnalyticsResponseCache.php'));
        $this->check(
            'Reporting cache key includes read boundary',
            str_contains($cacheSource, '__report_cache_boundary')
                && str_contains($cacheSource, 'hot_window_from')
                && str_contains($cacheSource, 'materialized_date_from'),
            'pre-I04 long-lived keys cannot shadow the new live boundary'
        );
    }

    private function verifyConsumers(): void
    {
        $checks = [
            'Owner Overview' => 'Http/Controllers/Api/V1/OwnerOverviewController.php',
            'Daily Sales Analytic' => 'Http/Controllers/Api/V1/Operational/OperationalSalesAnalyticController.php',
            'Finance Overview' => 'Http/Controllers/Api/V1/Finance/FinanceOverviewController.php',
            'Finance Sales Summary' => 'Http/Controllers/Api/V1/Finance/SalesSummaryController.php',
            'Finance Category Summary' => 'Http/Controllers/Api/V1/Finance/CategorySummaryController.php',
            'Finance Item Summary' => 'Http/Controllers/Api/V1/Finance/ItemSummaryController.php',
        ];

        foreach ($checks as $label => $relative) {
            $source = (string) @file_get_contents(app_path($relative));
            $this->check(
                $label.' uses reporting-aware short cache',
                $source !== '' && str_contains($source, 'AnalyticsResponseCache::rememberReporting'),
                $relative
            );
        }

        $recoverySource = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/Console/MaterializationDemandRecoveryController.php'));
        $this->check(
            'Demand Recovery rejects LIVE-only Daily ranges and trims HYBRID to history',
            str_contains($recoverySource, '($plan[\'mode\'] ?? null) === \'live\'')
                && str_contains($recoverySource, '($plan[\'mode\'] ?? null) === \'hybrid\'')
                && str_contains($recoverySource, "['historical_from']")
                && str_contains($recoverySource, "['historical_to']"),
            'MaterializationDemandRecoveryController.php'
        );

        foreach ([
            'Services/OwnerOverviewService.php',
            'Services/Operational/OperationalSalesAnalyticService.php',
            'Http/Controllers/Api/V1/Finance/FinanceOverviewController.php',
            'Http/Controllers/Api/V1/Finance/SalesSummaryController.php',
            'Http/Controllers/Api/V1/Finance/CategorySummaryController.php',
            'Http/Controllers/Api/V1/Finance/ItemSummaryController.php',
        ] as $relative) {
            $source = (string) @file_get_contents(app_path($relative));
            $this->check(
                basename($relative).' hot-window read wiring',
                str_contains($source, 'ReportHotWindowReadService') || str_contains($source, 'overviewReadContract'),
                $relative
            );
        }
    }

    private function verifyMonthlyParity(ReportMonthlySummaryService $monthly, CarbonImmutable $today): void
    {
        try {
            if (! Schema::hasTable('report_monthly_summary_coverage') || ! Schema::hasTable('report_daily_summary_coverage')) {
                $this->check('Monthly coverage tables available', false, 'run I03/prior reporting migrations first');
                return;
            }

            $monthOption = trim((string) $this->option('month'));
            if ($monthOption !== '' && ! preg_match('/^\d{4}-\d{2}$/', $monthOption)) {
                $this->check('Monthly parity month format', false, '--month must be YYYY-MM');
                return;
            }

            $month = $monthOption !== ''
                ? CarbonImmutable::parse($monthOption.'-01')->startOfMonth()
                : $this->resolveParityMonth($today);

            if (! $month->endOfMonth()->lt($today->startOfDay())) {
                $this->check('Monthly parity uses a closed month', false, $month->format('Y-m').' is not closed');
                return;
            }

            $requestedOutlets = array_values(array_unique(array_filter(array_map('strval', (array) $this->option('outlet')))));
            $outletIds = $requestedOutlets;
            if ($outletIds === []) {
                $outletIds = DB::table('report_monthly_summary_coverage')
                    ->where('business_month', $month->toDateString())
                    ->orderBy('outlet_id')
                    ->pluck('outlet_id')
                    ->map(fn ($id) => (string) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }
            if ($outletIds === []) {
                $outletIds = DB::table('outlets')
                    ->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")
                    ->orderBy('id')
                    ->limit(50)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->filter()
                    ->values()
                    ->all();
            }

            if ($outletIds === []) {
                $this->check('Monthly parity has outlet scope', false, 'no outlet rows found');
                return;
            }

            $aggregate = $monthly->readContractStatus($outletIds, $month->toDateString());
            $singleReadyRows = 0;
            $allSingleReady = true;
            foreach ($outletIds as $outletId) {
                $single = $monthly->readContractStatus([$outletId], $month->toDateString());
                $singleReadyRows += (int) ($single['ready_rows'] ?? 0);
                $allSingleReady = $allSingleReady && (bool) ($single['ready'] ?? false);
            }

            $expected = count($outletIds);
            $aggregateReadyRows = (int) ($aggregate['ready_rows'] ?? -1);
            $aggregateReady = (bool) ($aggregate['ready'] ?? false);
            $expectedAggregateReady = $allSingleReady && $singleReadyRows === $expected;

            $this->check(
                'Monthly ready_rows aggregate equals sum of per-outlet canonical status',
                $aggregateReadyRows === $singleReadyRows,
                sprintf('%s aggregate=%d singles=%d outlets=%d', $month->format('Y-m'), $aggregateReadyRows, $singleReadyRows, $expected)
            );
            $this->check(
                'Monthly aggregate READY equals all per-outlet READY',
                $aggregateReady === $expectedAggregateReady,
                'aggregate='.($aggregateReady ? 'READY' : 'NOT_READY').'; outlets='.($expectedAggregateReady ? 'ALL_READY' : 'NOT_ALL_READY')
            );
        } catch (Throwable $e) {
            $this->check('Monthly aggregate / per-outlet readiness parity', false, $this->shortError($e));
        }
    }

    private function verifySchedulerRegistration(): void
    {
        $catalog = ReportingScheduleRegistry::catalog([
            'daily_enabled' => true,
            'hourly_enabled' => true,
            'monthly_enabled' => true,
        ], 'HEALTHY');
        $names = array_values(array_filter(array_map(fn (array $row) => (string) ($row['schedule_name'] ?? ''), $catalog)));
        $this->check('Canonical reporting schedule catalog has 5 entries', count($names) === 5, 'count='.count($names));
        $this->check('Canonical schedule names are unique', count($names) === count(array_unique($names)), implode(', ', $names));
        foreach (['reporting.pipeline.daily', 'reporting.pipeline.hourly', 'reporting.pipeline.monthly'] as $required) {
            $this->check('Schedule registered: '.$required, in_array($required, $names, true), $required);
        }

        $schedulerSources = $this->schedulerSourceText();
        $registryCalls = substr_count($schedulerSources, 'ReportingScheduleRegistry::register');
        $this->check('ReportingScheduleRegistry is registered exactly once', $registryCalls === 1, "count={$registryCalls}");
        $this->check(
            'No legacy direct Daily refresh scheduler remains',
            ! str_contains($schedulerSources, 'report-daily-summaries:refresh-dirty'),
            'bootstrap/routes scan'
        );
        $this->check(
            'No legacy direct Hourly refresh scheduler remains',
            ! str_contains($schedulerSources, 'report-hourly-summaries:refresh-dirty'),
            'bootstrap/routes scan'
        );

        $registrySource = (string) @file_get_contents(app_path('Support/Reporting/ReportingScheduleRegistry.php'));
        foreach (['reporting.pipeline.daily', 'reporting.pipeline.hourly', 'reporting.pipeline.monthly'] as $name) {
            $count = substr_count($registrySource, "->name('{$name}')");
            $this->check($name.' appears once in registry implementation', $count === 1, "count={$count}");
        }
    }

    private function verifyQueueConfiguration(): void
    {
        $driver = (string) config('queue.connections.reporting.driver', '');
        $queue = (string) config('queue.connections.reporting.queue', '');
        $retryAfter = (int) config('queue.connections.reporting.retry_after', 0);
        $job = new ProcessReportingMaterializationChunkJob('01I04VERIFYCHUNK0000000000', '01I04VERIFYTOKEN0000000000');

        $this->check('reporting queue driver = database', $driver === 'database', "driver={$driver}");
        $this->check('reporting queue name = reporting', $queue === 'reporting', "queue={$queue}");
        $this->check('reporting retry_after > worker job timeout', $retryAfter > (int) $job->timeout, "retry_after={$retryAfter}; timeout={$job->timeout}");

        try {
            $this->check('jobs table exists', Schema::hasTable((string) config('queue.connections.reporting.table', 'jobs')), (string) config('queue.connections.reporting.table', 'jobs'));
            $this->check('report_pipeline_runtime_status exists', Schema::hasTable('report_pipeline_runtime_status'), 'I03 runtime telemetry');
            $this->check('report_materialization_settings exists', Schema::hasTable('report_materialization_settings'), 'scheduler/worker heartbeat state');
        } catch (Throwable $e) {
            $this->check('Reporting queue/runtime database prerequisites', false, $this->shortError($e));
        }
    }

    private function resolveParityMonth(CarbonImmutable $today): CarbonImmutable
    {
        $latest = DB::table('report_monthly_summary_coverage')
            ->where('business_month', '<', $today->startOfMonth()->toDateString())
            ->max('business_month');

        if ($latest) {
            return CarbonImmutable::parse((string) $latest)->startOfMonth();
        }

        return $today->subMonth()->startOfMonth();
    }

    private function schedulerSourceText(): string
    {
        $text = (string) @file_get_contents(base_path('bootstrap/app.php'));
        $routes = base_path('routes');
        if (! is_dir($routes)) return $text;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($routes, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $text .= "\n".(string) @file_get_contents($file->getPathname());
        }

        return $text;
    }

    private function frontendFile(string $relative): ?string
    {
        $relative = ltrim($relative, '/');
        $candidates = [
            dirname(base_path()).'/frontend - Backoffice/'.$relative,
            base_path('../frontend - Backoffice/'.$relative),
            base_path('frontend - Backoffice/'.$relative),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return realpath($candidate) ?: $candidate;
        }

        return null;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info($title);
    }

    private function check(string $label, bool $ok, string $detail = ''): bool
    {
        $this->line(sprintf('[%s] %s%s', $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' — '.$detail : ''));
        if (! $ok) $this->failures++;
        return $ok;
    }

    private function recordWarning(string $label, string $detail = ''): void
    {
        $this->warnings++;
        $this->line(sprintf('[WARN] %s%s', $label, $detail !== '' ? ' — '.$detail : ''));
    }

    private function shortError(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', trim($e->getMessage()));
        return mb_substr((string) $message, 0, 220);
    }
}
