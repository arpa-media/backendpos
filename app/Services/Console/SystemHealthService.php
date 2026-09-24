<?php

namespace App\Services\Console;

use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemHealthService
{
    public function __construct(private readonly ReportingMaterializationOrchestrator $orchestrator) {}

    public function snapshot(bool $fresh = false): array
    {
        $key = 'erp-finance-v8:i13:system-health';
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, max(5, (int) config('system_health.cache_seconds', 20)), fn (): array => $this->buildSnapshot());
    }

    private function buildSnapshot(): array
    {
        $generatedAt = now();
        $database = $this->databaseHealth();
        $materialization = $this->materializationHealth();
        $queue = $this->queueHealth();
        $reports = $this->reportHealth();
        $gate = $this->latestGate();

        $components = [
            'database' => $database,
            'scheduler' => $materialization['scheduler'],
            'worker' => $materialization['worker'],
            'queue' => $queue,
            'daily_coverage' => $materialization['daily_coverage'],
            'hourly_coverage' => $materialization['hourly_coverage'],
            'monthly_coverage' => $materialization['monthly_coverage'],
            'report_performance' => $reports['component'],
            'deployment_gate' => $gate['component'],
        ];

        $overall = $this->worstStatus(array_column($components, 'status'));
        $recommendations = [];
        foreach ($components as $key => $component) {
            if (in_array($component['status'], ['warning', 'critical'], true) && filled($component['recommendation'] ?? null)) {
                $recommendations[] = ['component' => $key, 'status' => $component['status'], 'message' => $component['recommendation']];
            }
        }

        return [
            'contract' => 'erp_finance_v8_i13',
            'generated_at' => $generatedAt->toIso8601String(),
            'overall_status' => $overall,
            'components' => $components,
            'materialization' => [
                'active_run' => $materialization['active_run'],
                'coverage' => $materialization['coverage'],
                'queue_depth' => $queue['value'],
            ],
            'reports' => $reports['metrics'],
            'top_slow_endpoints' => $reports['top_slow_endpoints'],
            'recent_gate' => $gate['run'],
            'recommendations' => $recommendations,
            'thresholds' => [
                'report_p95_ms' => (float) config('system_health.reports.p95_healthy_ms', 3000),
                'coverage_percent' => (float) config('system_health.coverage.healthy_percent', 99.5),
                'scheduler_healthy_seconds' => (int) config('system_health.scheduler.healthy_seconds', 180),
                'worker_healthy_seconds' => (int) config('system_health.worker.healthy_seconds', 600),
            ],
        ];
    }

    private function databaseHealth(): array
    {
        $started = microtime(true);
        try {
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $started) * 1000, 2);
            $status = $this->upperBoundStatus(
                $ms,
                (float) config('system_health.database.healthy_ms', 150),
                (float) config('system_health.database.critical_ms', 500)
            );

            return $this->component($status, $ms, 'ms', 'Database latency',
                $status === 'critical' ? 'Periksa MySQL load, connection saturation, disk I/O, dan slow query.' : null
            );
        } catch (\Throwable $e) {
            return $this->component('critical', null, null, 'Database unavailable', 'Periksa koneksi MySQL. '.$e->getMessage());
        }
    }

    private function materializationHealth(): array
    {
        try {
            $dashboard = $this->orchestrator->dashboard();
        } catch (\Throwable $e) {
            $critical = $this->component('critical', null, null, 'Reporting engine unavailable', 'Periksa migration I09-I11 dan database. '.$e->getMessage());
            return [
                'scheduler' => $critical,
                'worker' => $critical,
                'daily_coverage' => $critical,
                'hourly_coverage' => $critical,
                'monthly_coverage' => $critical,
                'coverage' => [],
                'active_run' => null,
            ];
        }

        $engine = (array) ($dashboard['engine_health'] ?? []);
        $settings = (array) ($dashboard['settings'] ?? []);
        $schedulerAge = $this->ageSeconds($settings['last_tick_at'] ?? null);
        $workerAge = $this->ageSeconds($settings['last_worker_heartbeat_at'] ?? null);

        $schedulerStatus = $this->ageStatus(
            $schedulerAge,
            (int) config('system_health.scheduler.healthy_seconds', 180),
            (int) config('system_health.scheduler.critical_seconds', 600)
        );
        $workerStatus = $this->ageStatus(
            $workerAge,
            (int) config('system_health.worker.healthy_seconds', 600),
            (int) config('system_health.worker.critical_seconds', 1200)
        );

        $coverage = (array) ($dashboard['coverage'] ?? []);

        return [
            'scheduler' => $this->component(
                $schedulerStatus,
                $schedulerAge,
                'seconds',
                'Scheduler heartbeat',
                $schedulerStatus !== 'healthy' ? 'Pastikan cron `php artisan schedule:run` berjalan setiap menit.' : null,
                ['last_tick_at' => $settings['last_tick_at'] ?? null]
            ),
            'worker' => $this->component(
                $workerStatus,
                $workerAge,
                'seconds',
                'Reporting worker heartbeat',
                $workerStatus !== 'healthy' ? 'Pastikan dedicated `queue:work reporting --queue=reporting` aktif.' : null,
                [
                    'last_worker_heartbeat_at' => $settings['last_worker_heartbeat_at'] ?? null,
                    'engine_state' => $engine['worker_state'] ?? $engine['status'] ?? null,
                ]
            ),
            'daily_coverage' => $this->coverageComponent('Daily coverage', (array) ($coverage['daily'] ?? [])),
            'hourly_coverage' => $this->coverageComponent('Hourly coverage', (array) ($coverage['hourly'] ?? [])),
            'monthly_coverage' => $this->coverageComponent('Monthly coverage', (array) ($coverage['monthly'] ?? [])),
            'coverage' => $coverage,
            'active_run' => $dashboard['active_run'] ?? null,
        ];
    }

    private function queueHealth(): array
    {
        $depth = 0;
        $failed = 0;
        try {
            if (Schema::hasTable('jobs')) {
                $depth = DB::table('jobs')->where('queue', 'reporting')->count();
            }
            if (Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();
            }
        } catch (\Throwable) {
            return $this->component('warning', null, null, 'Queue status unavailable', 'Periksa tabel jobs/failed_jobs dan koneksi queue.');
        }

        $depthStatus = $this->upperBoundStatus(
            $depth,
            (int) config('system_health.queue.healthy_depth', 10),
            (int) config('system_health.queue.critical_depth', 50)
        );
        $failedStatus = $failed >= (int) config('system_health.queue.failed_jobs_critical', 4)
            ? 'critical'
            : ($failed >= (int) config('system_health.queue.failed_jobs_warning', 1) ? 'warning' : 'healthy');
        $status = $this->worstStatus([$depthStatus, $failedStatus]);

        return $this->component(
            $status,
            $depth,
            'jobs',
            'Reporting queue',
            $status !== 'healthy' ? 'Periksa worker reporting dan failed jobs sebelum antrean bertambah.' : null,
            ['failed_jobs_24h' => $failed]
        );
    }

    private function reportHealth(): array
    {
        $hours = max(1, (int) config('system_health.metrics_window_hours', 24));
        $maxRows = max(100, (int) config('system_health.metrics_max_rows', 5000));
        $rows = collect();
        if (Schema::hasTable('report_request_metrics')) {
            try {
                $rows = DB::table('report_request_metrics')
                    ->where('occurred_at', '>=', now()->subHours($hours))
                    ->orderByDesc('occurred_at')
                    ->limit($maxRows)
                    ->get();
            } catch (\Throwable) {
                $rows = collect();
            }
        }

        if ($rows->isEmpty()) {
            return [
                'component' => $this->component('unknown', null, 'ms', 'Report performance p95', 'Belum ada metric report dalam window observability.'),
                'metrics' => ['window_hours' => $hours, 'sample_count' => 0, 'p50_ms' => null, 'p95_ms' => null, 'max_ms' => null, 'slow_count' => 0, 'error_count' => 0],
                'top_slow_endpoints' => [],
            ];
        }

        $durations = $rows->pluck('duration_ms')->map(fn ($v) => (float) $v)->sort()->values()->all();
        $p50 = $this->percentile($durations, 50);
        $p95 = $this->percentile($durations, 95);
        $max = max($durations);
        $status = $this->upperBoundStatus(
            $p95,
            (float) config('system_health.reports.p95_healthy_ms', 3000),
            (float) config('system_health.reports.p95_critical_ms', 6000)
        );

        $top = $rows->groupBy('route_key')->map(function ($group, $route): array {
            $dur = $group->pluck('duration_ms')->map(fn ($v) => (float) $v)->sort()->values()->all();
            return [
                'route' => (string) $route,
                'samples' => count($dur),
                'p95_ms' => round($this->percentile($dur, 95), 2),
                'max_ms' => round(max($dur), 2),
                'slow_count' => $group->where('is_slow', 1)->count(),
                'error_count' => $group->filter(fn ($r) => (int) $r->status_code >= 500)->count(),
            ];
        })->sortByDesc('p95_ms')->take(10)->values()->all();

        return [
            'component' => $this->component(
                $status,
                round($p95, 2),
                'ms',
                'Report performance p95',
                $status !== 'healthy' ? 'Jalankan I12 performance gate dan cek endpoint paling lambat di bawah.' : null
            ),
            'metrics' => [
                'window_hours' => $hours,
                'sample_count' => $rows->count(),
                'p50_ms' => round($p50, 2),
                'p95_ms' => round($p95, 2),
                'max_ms' => round($max, 2),
                'slow_count' => $rows->where('is_slow', 1)->count(),
                'error_count' => $rows->filter(fn ($r) => (int) $r->status_code >= 500)->count(),
            ],
            'top_slow_endpoints' => $top,
        ];
    }

    private function latestGate(): array
    {
        if (! Schema::hasTable('system_health_gate_runs')) {
            return ['component' => $this->component('unknown', null, null, 'Deployment gate', 'Jalankan migration dan I13 deployment gate.'), 'run' => null];
        }
        $row = DB::table('system_health_gate_runs')->orderByDesc('created_at')->first();
        if (! $row) {
            return ['component' => $this->component('unknown', null, null, 'Deployment gate', 'Belum ada deployment gate I13 yang direkam.'), 'run' => null];
        }
        $status = match ((string) $row->status) {
            'pass' => 'healthy',
            'warn' => 'warning',
            default => 'critical',
        };
        return [
            'component' => $this->component($status, null, null, 'Deployment gate', $status === 'critical' ? 'Perbaiki gate yang gagal sebelum deployment berikutnya.' : null),
            'run' => [
                'id' => (string) $row->id,
                'gate_name' => (string) $row->gate_name,
                'status' => (string) $row->status,
                'summary' => json_decode((string) ($row->summary ?? '{}'), true) ?: [],
                'commit_sha' => $row->commit_sha,
                'finished_at' => $row->finished_at,
            ],
        ];
    }

    private function coverageComponent(string $label, array $coverage): array
    {
        $percent = (float) ($coverage['coverage_percent'] ?? 0);
        $healthy = (float) config('system_health.coverage.healthy_percent', 99.5);
        $critical = (float) config('system_health.coverage.critical_percent', 95);
        $status = $percent >= $healthy ? 'healthy' : ($percent >= $critical ? 'warning' : 'critical');
        return $this->component(
            $status,
            round($percent, 2),
            '%',
            $label,
            $status !== 'healthy' ? 'Gunakan Console > Control Center untuk Repair Missing/Stale pada range yang dibutuhkan.' : null,
            $coverage
        );
    }

    private function ageSeconds(mixed $timestamp): ?int
    {
        if (! filled($timestamp)) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $timestamp)->diffInSeconds(now());
        } catch (\Throwable) {
            return null;
        }
    }

    private function ageStatus(?int $seconds, int $healthy, int $critical): string
    {
        if ($seconds === null) {
            return 'critical';
        }
        return $this->upperBoundStatus($seconds, $healthy, $critical);
    }

    private function upperBoundStatus(float|int $value, float|int $healthy, float|int $critical): string
    {
        if ($value <= $healthy) {
            return 'healthy';
        }
        if ($value <= $critical) {
            return 'warning';
        }
        return 'critical';
    }

    private function worstStatus(array $statuses): string
    {
        $rank = ['unknown' => 0, 'healthy' => 1, 'warning' => 2, 'critical' => 3];
        $worst = 'unknown';
        foreach ($statuses as $status) {
            $status = isset($rank[$status]) ? $status : 'unknown';
            if ($rank[$status] > $rank[$worst]) {
                $worst = $status;
            }
        }
        return $worst;
    }

    private function percentile(array $sorted, int $percentile): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float) $sorted[0];
        }
        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }
        $weight = $index - $lower;
        return ((float) $sorted[$lower] * (1 - $weight)) + ((float) $sorted[$upper] * $weight);
    }

    private function component(string $status, mixed $value, ?string $unit, string $label, ?string $recommendation = null, array $details = []): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'recommendation' => $recommendation,
            'details' => $details,
        ];
    }
}
