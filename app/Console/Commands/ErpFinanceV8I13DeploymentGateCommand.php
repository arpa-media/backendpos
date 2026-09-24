<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ErpFinanceV8I13DeploymentGateCommand extends Command
{
    protected $signature = 'erp-finance-v8:i13-deployment-gate {--strict} {--commit=}';
    protected $description = 'Run ERP Finance V8 I13 deployment/static reliability gate and persist the result.';

    public function handle(): int
    {
        $startedAt = now();
        $checks = [];

        $this->check($checks, 'system_health_route', Route::has('console.system-health.show'), 'System Health API route registered.');
        $this->check($checks, 'control_center_route', Route::has('console.control-center.dashboard'), 'Control Center API route registered.');
        $this->check($checks, 'metrics_table', Schema::hasTable('report_request_metrics'), 'Persisted report metric table exists.');
        $this->check($checks, 'gate_table', Schema::hasTable('system_health_gate_runs'), 'Deployment gate history table exists.');
        $this->check($checks, 'materialization_settings', Schema::hasTable('report_materialization_settings'), 'Reporting engine settings table exists.');
        $this->check($checks, 'reporting_jobs_table', Schema::hasTable('jobs'), 'Database queue jobs table exists.');

        $retryAfter = (int) config('queue.connections.reporting.retry_after', 0);
        $this->check($checks, 'reporting_retry_after', $retryAfter > 3300, "reporting retry_after={$retryAfter}s must be greater than worker timeout 3300s.");

        $reportService = base_path('app/Services/ReportService.php');
        $httpPath = base_path('app/Http');
        $badEnsureCoverage = [];
        foreach (array_filter(array_merge([$reportService], $this->phpFiles($httpPath))) as $file) {
            $content = @file_get_contents($file) ?: '';
            if (str_contains($content, '->ensureCoverage(')) {
                $badEnsureCoverage[] = str_replace(base_path().'/', '', $file);
            }
        }
        $this->check($checks, 'no_http_backfill', $badEnsureCoverage === [], $badEnsureCoverage === [] ? 'No HTTP report path calls ensureCoverage().' : 'HTTP backfill references: '.implode(', ', $badEnsureCoverage));

        $requiredTests = [
            base_path('tests/Feature/Reporting/TransactionDateInvariantTest.php'),
            base_path('tests/Feature/Reporting/ReportingHttpBackfillGuardTest.php'),
            base_path('tests/Feature/Reporting/ReportingWorkerReliabilityGuardTest.php'),
            base_path('tests/Feature/Reporting/ReportingHistoricalPerformanceGuardTest.php'),
        ];
        $this->check($checks, 'reporting_tests_present', collect($requiredTests)->every(fn ($f) => is_file($f)), 'Core reporting regression tests are present.');

        $settings = Schema::hasTable('report_materialization_settings') ? DB::table('report_materialization_settings')->where('id', 'default')->first() : null;
        if ($settings) {
            $schedulerAge = $settings->last_tick_at ? CarbonImmutable::parse((string) $settings->last_tick_at)->diffInSeconds(now()) : null;
            $workerAge = $settings->last_worker_heartbeat_at ? CarbonImmutable::parse((string) $settings->last_worker_heartbeat_at)->diffInSeconds(now()) : null;
            $this->runtimeCheck($checks, 'scheduler_heartbeat', $schedulerAge, (int) config('system_health.scheduler.critical_seconds', 600), 'scheduler heartbeat');
            $this->runtimeCheck($checks, 'worker_heartbeat', $workerAge, (int) config('system_health.worker.critical_seconds', 1200), 'reporting worker heartbeat');
        } else {
            $checks[] = ['name' => 'scheduler_heartbeat', 'status' => 'warn', 'message' => 'No materialization settings row yet.'];
            $checks[] = ['name' => 'worker_heartbeat', 'status' => 'warn', 'message' => 'No materialization settings row yet.'];
        }

        $hasFail = collect($checks)->contains(fn ($c) => $c['status'] === 'fail');
        $hasWarn = collect($checks)->contains(fn ($c) => $c['status'] === 'warn');
        $status = $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'pass');

        foreach ($checks as $check) {
            $method = $check['status'] === 'pass' ? 'info' : ($check['status'] === 'warn' ? 'warn' : 'error');
            $this->{$method}(sprintf('[%s] %s - %s', strtoupper($check['status']), $check['name'], $check['message']));
        }

        $summary = ['status' => $status, 'checks' => $checks, 'strict' => (bool) $this->option('strict')];
        $this->persistRun($summary, $startedAt);

        if ($hasFail || ((bool) $this->option('strict') && $hasWarn)) {
            $this->error('I13 deployment gate failed.');
            return self::FAILURE;
        }

        $this->info('I13 deployment gate '.$status.'.');
        return self::SUCCESS;
    }

    private function check(array &$checks, string $name, bool $ok, string $message): void
    {
        $checks[] = ['name' => $name, 'status' => $ok ? 'pass' : 'fail', 'message' => $message];
    }

    private function runtimeCheck(array &$checks, string $name, ?int $age, int $critical, string $label): void
    {
        if ($age === null) {
            $checks[] = ['name' => $name, 'status' => 'warn', 'message' => ucfirst($label).' has no heartbeat yet.'];
            return;
        }
        $checks[] = [
            'name' => $name,
            'status' => $age <= $critical ? 'pass' : 'warn',
            'message' => ucfirst($label)." age={$age}s (warning threshold {$critical}s).",
        ];
    }

    private function persistRun(array $summary, $startedAt): void
    {
        if (! Schema::hasTable('system_health_gate_runs')) {
            return;
        }
        DB::table('system_health_gate_runs')->insert([
            'id' => (string) Str::ulid(),
            'gate_name' => 'erp_finance_v8_i13',
            'status' => $summary['status'],
            'summary' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            'commit_sha' => $this->option('commit') ?: env('APP_COMMIT_SHA'),
            'started_at' => $startedAt,
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int,string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}
