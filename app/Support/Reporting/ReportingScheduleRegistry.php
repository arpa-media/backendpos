<?php

namespace App\Support\Reporting;

use App\Jobs\Reporting\ReportingPipelineMaintenanceJob;
use App\Jobs\Reporting\ReportingWorkerHeartbeatJob;
use Illuminate\Console\Scheduling\Schedule;

class ReportingScheduleRegistry
{
    public static function register(Schedule $schedule): void
    {
        // I03: one canonical scheduler registration for the reporting runtime.
        // The scheduler only orchestrates/dispatches; Daily/Hourly/Monthly work is
        // executed by the isolated `reporting` queue worker.
        $schedule->command('reporting-engine:tick --max-dispatch=4')
            ->name('reporting.engine.tick')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule->job(new ReportingWorkerHeartbeatJob(), 'reporting', 'reporting')
            ->name('reporting.worker.heartbeat')
            ->everyMinute()
            ->withoutOverlapping(5);

        $schedule->job(new ReportingPipelineMaintenanceJob('daily'), 'reporting', 'reporting')
            ->name('reporting.pipeline.daily')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        $schedule->job(new ReportingPipelineMaintenanceJob('hourly'), 'reporting', 'reporting')
            ->name('reporting.pipeline.hourly')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        $schedule->job(new ReportingPipelineMaintenanceJob('monthly'), 'reporting', 'reporting')
            ->name('reporting.pipeline.monthly')
            ->everyThirtyMinutes()
            ->withoutOverlapping(35);
    }

    public static function catalog(array $settings = [], string $schedulerState = 'UNKNOWN'): array
    {
        $pipelines = [
            [
                'key' => 'engine',
                'label' => 'Reporting Engine',
                'schedule_name' => 'reporting.engine.tick',
                'cadence' => 'Setiap 1 menit',
                'cron' => '* * * * *',
                'execution' => 'Scheduler / orchestration',
                'enabled' => true,
            ],
            [
                'key' => 'worker_heartbeat',
                'label' => 'Worker Heartbeat',
                'schedule_name' => 'reporting.worker.heartbeat',
                'cadence' => 'Setiap 1 menit',
                'cron' => '* * * * *',
                'execution' => 'Queue reporting',
                'enabled' => true,
            ],
            [
                'key' => 'daily',
                'label' => 'Data Harian',
                'schedule_name' => 'reporting.pipeline.daily',
                'cadence' => 'Setiap 5 menit',
                'cron' => '*/5 * * * *',
                'execution' => 'Queue reporting',
                'enabled' => (bool) ($settings['daily_enabled'] ?? true),
            ],
            [
                'key' => 'hourly',
                'label' => 'Data Per Jam',
                'schedule_name' => 'reporting.pipeline.hourly',
                'cadence' => 'Setiap 5 menit',
                'cron' => '*/5 * * * *',
                'execution' => 'Queue reporting',
                'enabled' => (bool) ($settings['hourly_enabled'] ?? true),
            ],
            [
                'key' => 'monthly',
                'label' => 'Ringkasan Bulanan',
                'schedule_name' => 'reporting.pipeline.monthly',
                'cadence' => 'Setiap 30 menit',
                'cron' => '*/30 * * * *',
                'execution' => 'Queue reporting',
                'enabled' => (bool) ($settings['monthly_enabled'] ?? true),
            ],
        ];

        return array_map(static function (array $row) use ($schedulerState): array {
            $row['registered'] = true;
            $row['scheduler_state'] = $schedulerState;
            $row['runtime_state'] = ! $row['enabled']
                ? 'DISABLED'
                : ($schedulerState === 'HEALTHY' ? 'ACTIVE' : ($schedulerState === 'STALE' ? 'SCHEDULER_STALE' : 'WAITING_SCHEDULER'));
            return $row;
        }, $pipelines);
    }
}
