<?php

namespace App\Services\Reporting;

use App\Support\Reporting\ReportingScheduleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportingRuntimeStatusService
{
    public function snapshot(array $settings, array $engineHealth): array
    {
        return [
            'schedules' => $this->scheduleRows($settings, $engineHealth),
            'queues' => $this->queueRows($engineHealth),
            'pipelines' => $this->pipelineRows($settings, $engineHealth),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function scheduleRows(array $settings, array $engineHealth): array
    {
        return ReportingScheduleRegistry::catalog($settings, (string) ($engineHealth['scheduler_state'] ?? 'UNKNOWN'));
    }

    private function queueRows(array $engineHealth): array
    {
        $connection = 'reporting';
        $queue = (string) config('queue.connections.reporting.queue', 'reporting');
        $driver = (string) config('queue.connections.reporting.driver', 'database');
        $pending = $ready = $reserved = $delayed = null;

        if ($driver === 'database' && Schema::hasTable((string) config('queue.connections.reporting.table', 'jobs'))) {
            $table = (string) config('queue.connections.reporting.table', 'jobs');
            $base = DB::table($table)->where('queue', $queue);
            $pending = (clone $base)->count();
            if (Schema::hasColumn($table, 'reserved_at')) {
                $reserved = (clone $base)->whereNotNull('reserved_at')->count();
            }
            if (Schema::hasColumn($table, 'available_at') && Schema::hasColumn($table, 'reserved_at')) {
                $ready = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->count();
                $delayed = (clone $base)->whereNull('reserved_at')->where('available_at', '>', now()->timestamp)->count();
            }
        }

        $failed = null;
        if (Schema::hasTable('failed_jobs') && Schema::hasColumn('failed_jobs', 'queue')) {
            $failed = DB::table('failed_jobs')->where('queue', $queue)->count();
        }

        $workerState = (string) ($engineHealth['worker_state'] ?? 'WAITING_WORKER');
        return [[
            'connection' => $connection,
            'driver' => $driver,
            'queue' => $queue,
            'pending_jobs' => $pending,
            'ready_jobs' => $ready,
            'reserved_jobs' => $reserved,
            'delayed_jobs' => $delayed,
            'failed_jobs' => $failed,
            'worker_state' => $workerState,
            'worker_active' => in_array($workerState, ['HEALTHY', 'BUSY'], true),
            'last_worker_activity_age_seconds' => $engineHealth['worker_last_activity_age_seconds'] ?? null,
        ]];
    }

    private function pipelineRows(array $settings, array $engineHealth): array
    {
        $runtime = collect();
        if (Schema::hasTable('report_pipeline_runtime_status')) {
            $runtime = DB::table('report_pipeline_runtime_status')->whereIn('pipeline', ['daily', 'hourly', 'monthly'])->get()->keyBy('pipeline');
        }

        $rows = [];
        foreach ([
            'daily' => ['label' => 'Data Harian', 'cadence' => '5 menit'],
            'hourly' => ['label' => 'Data Per Jam', 'cadence' => '5 menit'],
            'monthly' => ['label' => 'Ringkasan Bulanan', 'cadence' => '30 menit'],
        ] as $pipeline => $meta) {
            $row = $runtime->get($pipeline);
            $enabled = (bool) ($settings[$pipeline.'_enabled'] ?? true);
            $state = ! $enabled ? 'DISABLED' : strtoupper((string) ($row->state ?? 'WAITING_FIRST_RUN'));
            $lastFinished = $row->last_finished_at ?? null;
            $age = $lastFinished ? CarbonImmutable::parse($lastFinished)->diffInSeconds(now()) : null;
            $result = null;
            if (! empty($row?->last_result)) {
                $decoded = json_decode((string) $row->last_result, true);
                $result = is_array($decoded) ? $decoded : null;
            }

            $rows[] = [
                'pipeline' => $pipeline,
                'label' => $meta['label'],
                'enabled' => $enabled,
                'cadence' => $meta['cadence'],
                'state' => $state,
                'last_started_at' => $row->last_started_at ?? null,
                'last_finished_at' => $lastFinished,
                'last_succeeded_at' => $row->last_succeeded_at ?? null,
                'last_failed_at' => $row->last_failed_at ?? null,
                'last_activity_age_seconds' => $age,
                'last_duration_ms' => isset($row->last_duration_ms) ? (int) $row->last_duration_ms : null,
                'last_result' => $result,
                'last_error' => $row->last_error ?? null,
                'run_count' => (int) ($row->run_count ?? 0),
                'skip_count' => (int) ($row->skip_count ?? 0),
                'scheduler_state' => (string) ($engineHealth['scheduler_state'] ?? 'UNKNOWN'),
                'worker_state' => (string) ($engineHealth['worker_state'] ?? 'WAITING_WORKER'),
            ];
        }

        return $rows;
    }
}
