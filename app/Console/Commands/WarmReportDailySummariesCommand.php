<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Support\Reporting\WarmCommonRangeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\ProgressBar;

class WarmReportDailySummariesCommand extends Command
{
    protected $signature = 'report-daily-summaries:warm-common
        {--days=370 : Range hari rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan tertentu, format YYYY-MM; bulan berjalan dipotong sampai hari ini}
        {--from= : Tanggal awal exact range, format YYYY-MM-DD}
        {--to= : Tanggal akhir exact range, format YYYY-MM-DD}
        {--mode=normal : Preset chunk: safe, normal, fast}
        {--outlet-chunk=0 : Override jumlah outlet per chunk; 0 memakai preset mode}
        {--date-chunk=0 : Override jumlah hari per chunk; 0 memakai preset mode}';

    protected $description = 'Warm rolling report daily summaries outside HTTP requests with visible chunk progress and coverage.';

    public function handle(ReportDailySummaryService $dailySummaryService): int
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

        $days = \Carbon\CarbonImmutable::parse($dateFrom, $timezone)->diffInDays(\Carbon\CarbonImmutable::parse($dateTo, $timezone)) + 1;
        $outletIds = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($outletIds === []) {
            $this->warn('No outlets found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('ERP Finance V8 - Daily Summary Warm');
        $this->line(sprintf('Range        : %s -> %s (%d days)', $dateFrom, $dateTo, $days));
        if ($resolvedMonth !== null) {
            $this->line(sprintf('Month        : %s', $resolvedMonth));
        }
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));
        $this->line(sprintf('Outlets      : %d', count($outletIds)));
        $this->line(sprintf('Chunk        : %d outlet(s) x %d day(s)', $outletChunk, $dateChunk));

        $before = $this->safeCoverageStatus($dailySummaryService, $outletIds, $dateFrom, $dateTo, $timezone);
        if ($before !== null) {
            $this->line(sprintf(
                'Coverage     : %s%% (%s/%s outlet-days) before warm',
                number_format((float) ($before['coverage_percent'] ?? 0), 2),
                number_format((int) ($before['covered_rows'] ?? 0)),
                number_format((int) ($before['expected_coverage_rows'] ?? 0))
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
            if ($phase === '' || ! isset($phaseLabels[$phase])) {
                return;
            }

            if ($kind === 'plan') {
                $total = max(0, (int) ($event['total'] ?? 0));
                $phasePlanned[$phase] = $total;
                $this->line(sprintf('%s%s', $phaseLabels[$phase], $total === 0 ? ' - already ready, no rebuild needed.' : ''));

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

            if (! isset($bars[$phase])) {
                return;
            }

            $from = (string) ($event['date_from'] ?? '?');
            $to = (string) ($event['date_to'] ?? $from);
            $outletCount = (int) ($event['outlet_count'] ?? 0);
            $timezone = (string) ($event['timezone'] ?? '');
            $durationMs = (int) ($event['duration_ms'] ?? 0);
            $duration = $durationMs > 0 ? sprintf(' | %.2fs', $durationMs / 1000) : '';
            $message = sprintf('%s -> %s | %d outlet(s)%s%s', $from, $to, $outletCount, $timezone !== '' ? ' | '.$timezone : '', $duration);
            $bars[$phase]->setMessage($message);

            if ($kind === 'complete') {
                $bars[$phase]->advance();

                if ($this->output->isVeryVerbose()) {
                    $bars[$phase]->clear();
                    $this->line(sprintf('  OK %s', $message));
                    $bars[$phase]->display();
                }

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
        $dailySummaryService->ensureCoverage($outletIds, $dateFrom, $dateTo, $timezone, [
            'outlet_chunk' => $outletChunk,
            'date_chunk_days' => $dateChunk,
            'progress_callback' => $progressCallback,
        ]);
        $elapsedSeconds = microtime(true) - $startedAt;

        foreach ($bars as $phase => $bar) {
            if (! ($phaseFinished[$phase] ?? false)) {
                $bar->finish();
                $this->newLine(2);
            }
        }

        $after = $this->safeCoverageStatus($dailySummaryService, $outletIds, $dateFrom, $dateTo, $timezone);
        if ($after !== null) {
            $this->info(sprintf(
                'Coverage After: %s%% (%s/%s outlet-days)',
                number_format((float) ($after['coverage_percent'] ?? 0), 2),
                number_format((int) ($after['covered_rows'] ?? 0)),
                number_format((int) ($after['expected_coverage_rows'] ?? 0))
            ));
        }

        if (($phasePlanned['business_index'] ?? 0) === 0 && ($phasePlanned['daily_summary'] ?? 0) === 0) {
            $this->comment('No rebuild was required; requested coverage was already ready.');
        }

        $this->info('Report daily summaries warmed successfully.');
        $this->line(sprintf(
            'Completed in %s | range: %s to %s | outlet chunk: %d | date chunk: %d',
            $this->formatDuration($elapsedSeconds),
            $dateFrom,
            $dateTo,
            $outletChunk,
            $dateChunk
        ));

        return self::SUCCESS;
    }

    private function safeCoverageStatus(
        ReportDailySummaryService $service,
        array $outletIds,
        string $dateFrom,
        string $dateTo,
        string $timezone
    ): ?array {
        try {
            return $service->readContractStatus($outletIds, $dateFrom, $dateTo, $timezone);
        } catch (\Throwable $e) {
            if ($this->output->isVerbose()) {
                $this->warn('Coverage status unavailable: '.$e->getMessage());
            }

            return null;
        }
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds)
            : sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }
}
