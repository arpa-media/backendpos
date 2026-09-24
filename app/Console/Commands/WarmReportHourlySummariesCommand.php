<?php

namespace App\Console\Commands;

use App\Services\Operational\ReportHourlySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\WarmCommonRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class WarmReportHourlySummariesCommand extends Command
{
    protected $signature = 'report-hourly-summaries:warm-common
        {--days=370 : Range hari rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan tertentu, format YYYY-MM; bulan berjalan dipotong sampai hari ini}
        {--from= : Tanggal awal exact range, format YYYY-MM-DD}
        {--to= : Tanggal akhir exact range, format YYYY-MM-DD}
        {--mode=normal : Preset eksekusi: safe, normal, fast}';

    protected $description = 'Warm/repair hourly Sales Analytic materialization with month/range mode and visible progress.';

    public function handle(ReportHourlySummaryService $service): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        try {
            [$from, $to, $resolvedMonth] = WarmCommonRangeResolver::resolveDateRange(
                $this->option('month'),
                $this->option('from'),
                $this->option('to'),
                (int) $this->option('days'),
                $timezone
            );
            $mode = WarmCommonRangeResolver::normalizeMode((string) $this->option('mode'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $days = CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) + 1;
        $pairs = $service->stalePairs($from, $to);

        $this->newLine();
        $this->info('ERP Finance V8 - Hourly Summary Warm');
        $this->line(sprintf('Range        : %s -> %s (%d days)', $from, $to, $days));
        if ($resolvedMonth !== null) {
            $this->line(sprintf('Month        : %s', $resolvedMonth));
        }
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));

        if ($pairs->isEmpty()) {
            $this->info('Hourly requested window already warm.');

            return self::SUCCESS;
        }

        $processed = 0;
        $groups = $pairs->groupBy(fn ($row) => (string) ($row->business_date ?? ''));
        $this->line(sprintf('Work Items   : %d date group(s), %d outlet-date row(s)', $groups->count(), $pairs->count()));
        $this->newLine();

        $bar = $this->output->createProgressBar($groups->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% elapsed | ETA %estimated:-6s% | %message%');
        $bar->setMessage('planning...');
        $bar->start();

        foreach ($groups as $date => $rows) {
            $outletIds = $rows->pluck('outlet_id')->map(fn ($value) => (string) $value)->filter()->unique()->values()->all();
            if ($date !== '' && $outletIds !== []) {
                $startedAt = microtime(true);
                $bar->setMessage(sprintf('%s | %d outlet(s)', $date, count($outletIds)));
                $service->refreshDate($outletIds, $date);
                $processed += count($outletIds);
                if ($this->output->isVeryVerbose()) {
                    $bar->clear();
                    $this->line(sprintf('  OK %s | %d outlet(s) | %.2fs', $date, count($outletIds), microtime(true) - $startedAt));
                    $bar->display();
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($processed > 0) {
            AnalyticsResponseCache::bumpVersion('hourly-summary-warm:'.$processed);
        }

        $this->info("Hourly warm complete: {$processed} outlet-date row(s) across {$days} day(s).");

        return self::SUCCESS;
    }
}
