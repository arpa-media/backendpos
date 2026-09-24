<?php

namespace App\Jobs\Reporting;

use App\Services\Operational\ReportHourlySummaryService;
use App\Services\ReportDailySummaryRefreshService;
use App\Services\Reporting\ReportMonthlySummaryService;
use App\Support\AnalyticsResponseCache;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportingPipelineMaintenanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACTIVE_RUN_STATUSES = ['queued', 'running', 'pause_requested', 'paused', 'cancel_requested', 'waiting_window'];

    public int $tries = 1;
    public int $timeout = 1800;
    public bool $failOnTimeout = true;
    public int $uniqueFor = 7200;

    public function __construct(public readonly string $pipeline)
    {
        if (! in_array($pipeline, ['daily', 'hourly', 'monthly'], true)) {
            throw new \InvalidArgumentException('Unknown reporting maintenance pipeline: '.$pipeline);
        }

        $this->onConnection('reporting');
        $this->onQueue('reporting');
    }

    public function uniqueId(): string
    {
        return 'erp-pos-reporting-maintenance-'.$this->pipeline;
    }

    public function handle(
        ReportDailySummaryRefreshService $dailyRefresh,
        ReportHourlySummaryService $hourly,
        ReportMonthlySummaryService $monthly,
    ): void {
        if (! Schema::hasTable('report_materialization_settings')) {
            return;
        }

        $settings = DB::table('report_materialization_settings')->where('id', 'default')->first();
        $enabledColumn = $this->pipeline.'_enabled';
        $enabled = (bool) ($settings->{$enabledColumn} ?? true);

        if (! $enabled) {
            $this->finishRuntime('disabled', ['reason' => 'pipeline_disabled']);
            return;
        }

        if (Schema::hasTable('report_materialization_runs') && DB::table('report_materialization_runs')->whereIn('status', self::ACTIVE_RUN_STATUSES)->exists()) {
            $this->finishRuntime('skipped_busy', ['reason' => 'materialization_run_active']);
            return;
        }

        $this->startRuntime();
        $started = microtime(true);

        try {
            $result = match ($this->pipeline) {
                'daily' => $this->runDaily($dailyRefresh, $settings),
                'hourly' => $this->runHourly($hourly, $settings),
                'monthly' => $this->runMonthly($monthly, $settings),
            };

            $this->finishRuntime('idle', $result, null, (int) round((microtime(true) - $started) * 1000));
        } catch (\Throwable $e) {
            $this->finishRuntime('failed', null, mb_substr($e->getMessage(), 0, 4000), (int) round((microtime(true) - $started) * 1000));
            throw $e;
        } finally {
            DB::table('report_materialization_settings')->where('id', 'default')->update([
                'last_worker_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function runDaily(ReportDailySummaryRefreshService $service, object $settings): array
    {
        if (! Schema::hasTable('report_daily_summary_refresh_queue')) {
            return ['state' => 'schema_not_ready'];
        }

        return $service->processPending(
            120,
            max(1, min(12, (int) ($settings->outlet_chunk ?? 6))),
            max(1, min(7, (int) ($settings->date_chunk ?? 2))),
        ) + ['state' => 'ok'];
    }

    private function runHourly(ReportHourlySummaryService $service, object $settings): array
    {
        $timezone = (string) ($settings->timezone ?? config('app.timezone', 'Asia/Jakarta'));
        $rollingDays = max(1, min(730, (int) ($settings->rolling_days ?? 370)));
        $to = CarbonImmutable::now($timezone)->toDateString();
        $from = CarbonImmutable::now($timezone)->subDays($rollingDays - 1)->toDateString();
        $pairs = $service->stalePairs($from, $to, 120);

        if ($pairs->isEmpty()) {
            return ['state' => 'already_fresh', 'processed_outlet_dates' => 0, 'date_groups' => 0];
        }

        $processed = 0;
        $groups = 0;
        foreach ($pairs->groupBy(fn ($row) => (string) ($row->business_date ?? '')) as $date => $rows) {
            $outletIds = $rows->pluck('outlet_id')->map(fn ($value) => (string) $value)->filter()->unique()->values()->all();
            if ($date === '' || $outletIds === []) {
                continue;
            }
            $service->refreshDate($outletIds, $date);
            $processed += count($outletIds);
            $groups++;
        }

        if ($processed > 0) {
            AnalyticsResponseCache::bumpVersion('hourly-summary-scheduled:'.$processed);
        }

        return ['state' => 'ok', 'processed_outlet_dates' => $processed, 'date_groups' => $groups];
    }

    private function runMonthly(ReportMonthlySummaryService $service, object $settings): array
    {
        if (! Schema::hasTable('report_monthly_summary_coverage') || ! Schema::hasTable('report_daily_summary_coverage')) {
            return ['state' => 'schema_not_ready'];
        }

        $timezone = (string) ($settings->timezone ?? config('app.timezone', 'Asia/Jakarta'));
        $clock = CarbonImmutable::now($timezone);
        $lastClosedMonth = $clock->subMonthNoOverflow()->startOfMonth();
        $rollingDays = max(31, min(730, (int) ($settings->rolling_days ?? 370)));
        $firstMonth = $clock->subDays($rollingDays - 1)->startOfMonth();
        if ($firstMonth->gt($lastClosedMonth)) {
            return ['state' => 'no_closed_month'];
        }

        $outletIds = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($outletIds === []) {
            return ['state' => 'no_outlets'];
        }

        $staleMonths = array_reverse($service->staleMonths($firstMonth->toDateString(), $lastClosedMonth->toDateString(), $outletIds));
        if ($staleMonths === []) {
            return ['state' => 'already_fresh', 'processed_chunks' => 0, 'stale_months' => 0];
        }

        $chunkSize = max(1, min(12, (int) ($settings->outlet_chunk ?? 6)));
        $processedChunks = 0;
        $processedOutlets = 0;
        $blockedDaily = 0;
        $maxChunksPerRun = 2;

        foreach ($staleMonths as $month) {
            foreach (array_chunk($outletIds, $chunkSize) as $ids) {
                if (($service->readContractStatus($ids, $month)['ready'] ?? false) === true) {
                    continue;
                }

                try {
                    $service->refreshMonth($ids, $month);
                    $processedChunks++;
                    $processedOutlets += count($ids);
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'Daily coverage incomplete')) {
                        $blockedDaily++;
                        continue;
                    }
                    throw $e;
                }

                if ($processedChunks >= $maxChunksPerRun) {
                    break 2;
                }
            }
        }

        if ($processedOutlets > 0) {
            AnalyticsResponseCache::bumpVersion('monthly-summary-scheduled:'.$processedOutlets);
        }

        return [
            'state' => $processedChunks > 0 ? 'ok' : ($blockedDaily > 0 ? 'waiting_daily' : 'already_fresh'),
            'processed_chunks' => $processedChunks,
            'processed_outlets' => $processedOutlets,
            'blocked_daily_chunks' => $blockedDaily,
            'stale_months' => count($staleMonths),
        ];
    }

    private function startRuntime(): void
    {
        if (! Schema::hasTable('report_pipeline_runtime_status')) {
            return;
        }

        $now = now();
        DB::table('report_pipeline_runtime_status')->updateOrInsert(
            ['pipeline' => $this->pipeline],
            [
                'state' => 'running',
                'last_started_at' => $now,
                'last_error' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
        DB::table('report_pipeline_runtime_status')->where('pipeline', $this->pipeline)->increment('run_count');
    }

    private function finishRuntime(string $state, ?array $result = null, ?string $error = null, ?int $durationMs = null): void
    {
        if (! Schema::hasTable('report_pipeline_runtime_status')) {
            return;
        }

        $now = now();
        $payload = [
            'state' => $state,
            'last_finished_at' => $now,
            'last_duration_ms' => $durationMs,
            'last_result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'last_error' => $error,
            'updated_at' => $now,
            'created_at' => $now,
        ];
        if ($state === 'failed') {
            $payload['last_failed_at'] = $now;
        } elseif (! in_array($state, ['disabled', 'skipped_busy'], true)) {
            $payload['last_succeeded_at'] = $now;
        }

        DB::table('report_pipeline_runtime_status')->updateOrInsert(['pipeline' => $this->pipeline], $payload);
        if (in_array($state, ['disabled', 'skipped_busy'], true)) {
            DB::table('report_pipeline_runtime_status')->where('pipeline', $this->pipeline)->increment('skip_count');
        }
    }
}
