<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\MaterializationCliSelfHealing;
use App\Support\Reporting\WarmCommonRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class WarmReportDailySummariesCommand extends Command
{
    protected $signature = 'report-daily-summaries:warm-common
        {--days=370 : Range hari rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan tertentu, format YYYY-MM; bulan berjalan dipotong sampai hari ini}
        {--from= : Tanggal awal exact range, format YYYY-MM-DD}
        {--to= : Tanggal akhir exact range, format YYYY-MM-DD}
        {--outlet=* : Filter outlet berdasarkan ID/kode/nama; dapat diulang atau dipisah koma}
        {--mode=normal : Preset chunk: safe, normal, fast}
        {--outlet-chunk=0 : Override jumlah outlet per chunk; 0 memakai preset mode}
        {--date-chunk=0 : Override jumlah hari per chunk; 0 memakai preset mode}
        {--adjacent-days=0 : Perluas repair 0-7 hari di kedua sisi range untuk kasus cutoff}
        {--force : Abaikan ready/stale gate dan rebuild exact Business-Date Index + Daily Summary}
        {--audit : Read-only integrity audit; tidak mengubah materialization}
        {--verify : Verifikasi Raw -> Business-Date Index -> Daily setelah warm/repair}';

    protected $description = 'Warm/repair Daily materialization with outlet targeting, force rebuild, audit, and integrity verification.';

    public function handle(ReportDailySummaryService $dailySummaryService, MaterializationCliSelfHealing $selfHealing): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        try {
            [$dateFrom, $dateTo, $resolvedMonth] = WarmCommonRangeResolver::resolveDateRange(
                $this->option('month'),
                $this->option('from'),
                $this->option('to'),
                (int) $this->option('days'),
                $timezone
            );
            [$outletChunk, $dateChunk, $mode] = WarmCommonRangeResolver::resolveChunks(
                (string) $this->option('mode'),
                (int) $this->option('outlet-chunk'),
                (int) $this->option('date-chunk')
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::INVALID;
        }

        $adjacentDays = max(0, min(7, (int) $this->option('adjacent-days')));
        [$effectiveFrom, $effectiveTo] = $selfHealing->expandDateRange($dateFrom, $dateTo, $adjacentDays, $timezone);
        $outlets = $selfHealing->resolveOutlets((array) $this->option('outlet'));
        if ($outlets === []) {
            $this->error($this->option('outlet') ? 'Outlet filter tidak ditemukan.' : 'No outlets found.');
            return self::INVALID;
        }
        $outletIds = array_map(fn ($row) => (string) $row['id'], $outlets);
        $days = CarbonImmutable::parse($effectiveFrom, $timezone)->diffInDays(CarbonImmutable::parse($effectiveTo, $timezone)) + 1;
        $force = (bool) $this->option('force');
        $auditOnly = (bool) $this->option('audit');
        $verify = (bool) $this->option('verify');

        $this->newLine();
        $this->info('HF Materialization CLI Self-Healing - Daily');
        $this->line(sprintf('Requested    : %s -> %s', $dateFrom, $dateTo));
        if ($effectiveFrom !== $dateFrom || $effectiveTo !== $dateTo) {
            $this->line(sprintf('Effective    : %s -> %s (%d days; adjacent=%d)', $effectiveFrom, $effectiveTo, $days, $adjacentDays));
        } else {
            $this->line(sprintf('Range        : %s -> %s (%d days)', $effectiveFrom, $effectiveTo, $days));
        }
        if ($resolvedMonth !== null) {
            $this->line(sprintf('Month        : %s', $resolvedMonth));
        }
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));
        $this->line(sprintf('Operation    : %s', $auditOnly ? 'AUDIT ONLY' : ($force ? 'FORCE EXACT REBUILD' : 'NORMAL WARM')));
        $this->line(sprintf('Outlets      : %d (%s)', count($outlets), implode(', ', array_map(fn ($row) => $row['code'] ?: $row['id'], $outlets))));
        $this->line(sprintf('Chunk        : %d outlet(s) x %d day(s)', $outletChunk, $dateChunk));

        if ($auditOnly) {
            $audit = $selfHealing->auditDaily($outlets, $effectiveFrom, $effectiveTo, $timezone, true);
            $this->renderDailyAudit($audit);
            return $audit['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $before = $this->safeCoverageStatus($dailySummaryService, $outletIds, $effectiveFrom, $effectiveTo, $timezone);
        if ($before !== null) {
            $this->line(sprintf(
                'Coverage     : %s%% (%s/%s outlet-days) before %s | pending refresh: %d',
                number_format((float) ($before['coverage_percent'] ?? 0), 2),
                number_format((int) ($before['covered_rows'] ?? 0)),
                number_format((int) ($before['expected_coverage_rows'] ?? 0)),
                $force ? 'repair' : 'warm',
                (int) ($before['pending_refresh_rows'] ?? 0)
            ));
        }
        $this->newLine();

        /** @var array<string, ProgressBar> $bars */
        $bars = [];
        $phaseFinished = [];
        $phasePlanned = [];
        $phaseLabels = [
            'business_index' => '[1/2] Canonical Business-Date Index',
            'daily_summary' => '[2/2] Daily Summary Rebuild',
        ];

        $progressCallback = function (array $event) use (&$bars, &$phaseFinished, &$phasePlanned, $phaseLabels): void {
            $phase = (string) ($event['phase'] ?? '');
            $kind = (string) ($event['event'] ?? '');
            if ($phase === '' || ! isset($phaseLabels[$phase])) return;

            if ($kind === 'plan') {
                $total = max(0, (int) ($event['total'] ?? 0));
                $phasePlanned[$phase] = $total;
                $forced = (bool) ($event['forced'] ?? false);
                $this->line(sprintf('%s%s%s', $phaseLabels[$phase], $forced ? ' [FORCE]' : '', $total === 0 ? ' - already ready, no rebuild needed.' : ''));
                if ($total > 0) {
                    $bar = $this->output->createProgressBar($total);
                    $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% elapsed | ETA %estimated:-6s% | %message%');
                    $bar->setMessage('planning...');
                    $bar->setRedrawFrequency(1);
                    $bar->start();
                    $bars[$phase] = $bar;
                }
                return;
            }

            if (! isset($bars[$phase])) return;
            $from = (string) ($event['date_from'] ?? '?');
            $to = (string) ($event['date_to'] ?? $from);
            $outletCount = (int) ($event['outlet_count'] ?? 0);
            $eventTimezone = (string) ($event['timezone'] ?? '');
            $durationMs = (int) ($event['duration_ms'] ?? 0);
            $duration = $durationMs > 0 ? sprintf(' | %.2fs', $durationMs / 1000) : '';
            $message = sprintf('%s -> %s | %d outlet(s)%s%s', $from, $to, $outletCount, $eventTimezone !== '' ? ' | '.$eventTimezone : '', $duration);
            $bars[$phase]->setMessage($message);

            if ($kind === 'complete') {
                $bars[$phase]->advance();
                $current = (int) ($event['current'] ?? $bars[$phase]->getProgress());
                $total = max(0, (int) ($event['total'] ?? ($phasePlanned[$phase] ?? 0)));
                if ($total > 0 && $current >= $total && ! ($phaseFinished[$phase] ?? false)) {
                    $bars[$phase]->finish();
                    $this->newLine(2);
                    $phaseFinished[$phase] = true;
                }
            }
        };

        $startedAt = microtime(true);
        try {
            $options = [
                'outlet_chunk' => $outletChunk,
                'date_chunk_days' => $dateChunk,
                'progress_callback' => $progressCallback,
            ];
            if ($force) {
                $dailySummaryService->refreshExactCoverage($outletIds, $effectiveFrom, $effectiveTo, $timezone, $options);
            } else {
                $dailySummaryService->ensureCoverage($outletIds, $effectiveFrom, $effectiveTo, $timezone, $options);
            }
        } catch (\Throwable $e) {
            foreach ($bars as $phase => $bar) {
                if (! ($phaseFinished[$phase] ?? false)) {
                    $bar->finish();
                    $this->newLine(2);
                }
            }
            $this->error('Daily materialization failed: '.$e->getMessage());
            return self::FAILURE;
        }
        $elapsedSeconds = microtime(true) - $startedAt;

        foreach ($bars as $phase => $bar) {
            if (! ($phaseFinished[$phase] ?? false)) {
                $bar->finish();
                $this->newLine(2);
            }
        }

        AnalyticsResponseCache::bumpVersion(($force ? 'daily-summary-force:' : 'daily-summary-warm:').count($outletIds).':'.$effectiveFrom.':'.$effectiveTo);

        $after = $this->safeCoverageStatus($dailySummaryService, $outletIds, $effectiveFrom, $effectiveTo, $timezone);
        if ($after !== null) {
            $this->info(sprintf(
                'Coverage After: %s%% (%s/%s outlet-days)',
                number_format((float) ($after['coverage_percent'] ?? 0), 2),
                number_format((int) ($after['covered_rows'] ?? 0)),
                number_format((int) ($after['expected_coverage_rows'] ?? 0))
            ));
        }

        if ($verify) {
            $this->newLine();
            $this->info('Integrity Verification: Raw -> Business-Date Index -> Daily');
            $audit = $selfHealing->auditDaily($outlets, $effectiveFrom, $effectiveTo, $timezone, false);
            $this->renderDailyAudit($audit);
            if (! $audit['ok']) {
                $this->error(sprintf('Verification FAILED: %d outlet-date mismatch(es). Refresh queue is preserved.', $audit['issues']));
                return self::FAILURE;
            }

            if ($force) {
                $cleared = $selfHealing->clearDailyRefreshQueue($outletIds, $effectiveFrom, $effectiveTo);
                if ($cleared > 0) {
                    $this->comment(sprintf('Refresh queue reconciled: %d row(s) cleared after PASS.', $cleared));
                }
            }
        }

        if (! $force && ($phasePlanned['business_index'] ?? 0) === 0 && ($phasePlanned['daily_summary'] ?? 0) === 0) {
            $this->comment('No rebuild was required; requested coverage was already ready. Use --force --verify for integrity repair.');
        }

        $this->info($force ? 'Daily FORCE repair completed.' : 'Report daily summaries warmed successfully.');
        $this->line(sprintf('Completed in %s | effective range: %s to %s', $this->formatDuration($elapsedSeconds), $effectiveFrom, $effectiveTo));

        return self::SUCCESS;
    }

    private function renderDailyAudit(array $audit): void
    {
        $this->table(
            ['Outlet', 'Date', 'Raw Trx', 'Index Trx', 'Daily Trx', 'Raw Sales', 'Daily Sales', 'Coverage', 'Queue', 'Touch', 'Attempt', 'Status'],
            array_map(fn ($row) => [
                $row['outlet'], $row['date'], $row['raw_trx'], $row['index_trx'], $row['daily_trx'],
                number_format((int) $row['raw_sales']), number_format((int) $row['daily_sales']),
                $row['coverage'], $row['queue'], $row['touch'], $row['attempt'], $row['status'],
            ], $audit['rows'])
        );
        $audit['ok']
            ? $this->info('Integrity: PASS')
            : $this->warn(sprintf('Integrity: MISMATCH (%d row(s)); pending queue: %d.', $audit['issues'], $audit['pending_queue'] ?? 0));
    }

    private function safeCoverageStatus(ReportDailySummaryService $service, array $outletIds, string $dateFrom, string $dateTo, string $timezone): ?array
    {
        try {
            return $service->readContractStatus($outletIds, $dateFrom, $dateTo, $timezone);
        } catch (\Throwable $e) {
            if ($this->output->isVerbose()) $this->warn('Coverage status unavailable: '.$e->getMessage());
            return null;
        }
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        return $hours > 0 ? sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds) : sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }
}
