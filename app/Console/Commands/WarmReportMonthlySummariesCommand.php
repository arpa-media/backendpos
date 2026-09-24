<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Services\Reporting\ReportMonthlySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\WarmCommonRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\ProgressBar;

class WarmReportMonthlySummariesCommand extends Command
{
    protected $signature = 'report-monthly-summaries:warm-common
        {--months=12 : Jumlah bulan closed rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan closed tertentu, format YYYY-MM}
        {--from= : Bulan awal range, format YYYY-MM atau YYYY-MM-DD}
        {--to= : Bulan akhir range, format YYYY-MM atau YYYY-MM-DD}
        {--mode=normal : Preset eksekusi: safe, normal, fast}
        {--outlet-chunk=0 : Override jumlah outlet per monthly chunk; 0 memakai preset mode}
        {--date-chunk=0 : Override jumlah hari per daily chunk saat --with-daily; 0 memakai preset mode}
        {--with-daily : Lengkapi Daily coverage yang kurang sebelum membuat Monthly}';

    protected $description = 'Warm/repair closed-month report monthly summaries with month/range mode and progress visibility.';

    public function handle(ReportMonthlySummaryService $monthly, ReportDailySummaryService $daily): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        try {
            [$monthFrom, $monthTo] = WarmCommonRangeResolver::resolveMonthRange(
                $this->option('month'),
                $this->option('from'),
                $this->option('to'),
                (int) $this->option('months'),
                $timezone,
                false
            );
            [$outletChunk, $mode] = WarmCommonRangeResolver::resolveOutletChunk((string) $this->option('mode'), (int) $this->option('outlet-chunk'));
            [, $dateChunk] = WarmCommonRangeResolver::resolveChunks($mode, $outletChunk, (int) $this->option('date-chunk'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

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

        $months = WarmCommonRangeResolver::monthCursor($monthFrom, $monthTo);
        $monthLabels = array_map(fn (CarbonImmutable $month) => $month->format('Y-m'), $months);
        $withDaily = (bool) $this->option('with-daily');

        $this->newLine();
        $this->info('ERP Finance V8 - Monthly Summary Warm');
        $this->line(sprintf('Range        : %s -> %s (%d month(s))', reset($monthLabels), end($monthLabels), count($months)));
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));
        $this->line(sprintf('Outlets      : %d', count($outletIds)));
        $this->line(sprintf('Chunk        : %d outlet(s)', $outletChunk));
        $this->line(sprintf('With Daily   : %s', $withDaily ? 'YES' : 'NO'));
        $this->newLine();

        $workItems = [];
        $alreadyReady = 0;
        foreach ($months as $month) {
            $monthStart = $month->startOfMonth()->toDateString();
            $status = $this->safeMonthlyStatus($monthly, $outletIds, $monthStart);
            if (($status['ready'] ?? false) === true) {
                $alreadyReady++;
                continue;
            }

            foreach (array_chunk($outletIds, $outletChunk) as $outletChunkIds) {
                $chunkStatus = $this->safeMonthlyStatus($monthly, $outletChunkIds, $monthStart);
                if (($chunkStatus['ready'] ?? false) === true) {
                    continue;
                }
                $workItems[] = [$month, $outletChunkIds];
            }
        }

        if ($workItems === []) {
            $this->info(sprintf('Monthly requested window already ready. %d month(s) checked.', count($months)));

            return self::SUCCESS;
        }

        if ($alreadyReady > 0) {
            $this->comment(sprintf('%d month(s) already ready and skipped.', $alreadyReady));
        }
        $this->line(sprintf('Work Items   : %d monthly chunk(s)', count($workItems)));
        $this->newLine();

        $bar = $this->output->createProgressBar(count($workItems));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% elapsed | ETA %estimated:-6s% | %message%');
        $bar->setMessage('planning...');
        $bar->start();

        $processedOutlets = 0;
        $skippedChunks = 0;
        foreach ($workItems as [$month, $chunkOutletIds]) {
            $monthStart = $month->startOfMonth()->toDateString();
            $monthEnd = $month->endOfMonth()->toDateString();
            $message = sprintf('%s | %d outlet(s)', $month->format('Y-m'), count($chunkOutletIds));
            $bar->setMessage($message);
            $startedAt = microtime(true);

            try {
                if ($withDaily) {
                    $daily->ensureCoverage($chunkOutletIds, $monthStart, $monthEnd, $timezone, [
                        'outlet_chunk' => $outletChunk,
                        'date_chunk_days' => $dateChunk,
                    ]);
                }

                $monthly->refreshMonth($chunkOutletIds, $monthStart);
                $processedOutlets += count($chunkOutletIds);

                if ($this->output->isVeryVerbose()) {
                    $bar->clear();
                    $this->line(sprintf('  OK %s | %.2fs', $message, microtime(true) - $startedAt));
                    $bar->display();
                }
            } catch (\Throwable $e) {
                $skippedChunks++;
                $bar->clear();
                $this->warn(sprintf('  SKIP %s | %s', $message, $e->getMessage()));
                if (! $withDaily && str_contains($e->getMessage(), 'Daily coverage incomplete')) {
                    $this->comment('       Jalankan Daily bulan ini dulu, atau ulangi Monthly dengan --with-daily.');
                }
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($processedOutlets > 0) {
            AnalyticsResponseCache::bumpVersion('monthly-summary-warm:'.$processedOutlets);
        }

        $this->info(sprintf(
            'Monthly warm complete: %d outlet-month row(s) processed, %d chunk(s) skipped.',
            $processedOutlets,
            $skippedChunks
        ));

        if ($skippedChunks > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function safeMonthlyStatus(ReportMonthlySummaryService $service, array $outletIds, string $businessMonth): array
    {
        try {
            return $service->readContractStatus($outletIds, $businessMonth);
        } catch (\Throwable $e) {
            if ($this->output->isVerbose()) {
                $this->warn('Monthly status unavailable: '.$e->getMessage());
            }

            return ['ready' => false];
        }
    }
}
