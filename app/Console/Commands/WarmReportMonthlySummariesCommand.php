<?php

namespace App\Console\Commands;

use App\Services\ReportDailySummaryService;
use App\Services\Reporting\ReportMonthlySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\MaterializationCliSelfHealing;
use App\Support\Reporting\WarmCommonRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class WarmReportMonthlySummariesCommand extends Command
{
    protected $signature = 'report-monthly-summaries:warm-common
        {--months=12 : Jumlah bulan closed rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan closed tertentu, format YYYY-MM}
        {--from= : Bulan awal range, format YYYY-MM atau YYYY-MM-DD}
        {--to= : Bulan akhir range, format YYYY-MM atau YYYY-MM-DD}
        {--outlet=* : Filter outlet berdasarkan ID/kode/nama; dapat diulang atau dipisah koma}
        {--mode=normal : Preset eksekusi: safe, normal, fast}
        {--outlet-chunk=0 : Override jumlah outlet per monthly chunk; 0 memakai preset mode}
        {--date-chunk=0 : Override jumlah hari per daily chunk saat --with-daily; 0 memakai preset mode}
        {--with-daily : Lengkapi Daily coverage yang kurang sebelum membuat Monthly}
        {--force-daily : Saat --with-daily, force exact rebuild Daily seluruh bulan}
        {--force : Abaikan Monthly ready gate dan rebuild exact month}
        {--audit : Read-only integrity audit SUM Daily -> Monthly}
        {--verify : Verifikasi SUM Daily = Monthly setelah warm/repair}';

    protected $description = 'Warm/repair closed-month Monthly materialization with outlet targeting, force rebuild, audit, and verification.';

    public function handle(
        ReportMonthlySummaryService $monthly,
        ReportDailySummaryService $daily,
        MaterializationCliSelfHealing $selfHealing
    ): int {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        try {
            [$monthFrom, $monthTo] = WarmCommonRangeResolver::resolveMonthRange(
                $this->option('month'), $this->option('from'), $this->option('to'), (int) $this->option('months'), $timezone, false
            );
            [$outletChunk, $mode] = WarmCommonRangeResolver::resolveOutletChunk((string) $this->option('mode'), (int) $this->option('outlet-chunk'));
            [, $dateChunk] = WarmCommonRangeResolver::resolveChunks($mode, $outletChunk, (int) $this->option('date-chunk'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::INVALID;
        }

        $outlets = $selfHealing->resolveOutlets((array) $this->option('outlet'));
        if ($outlets === []) {
            $this->error($this->option('outlet') ? 'Outlet filter tidak ditemukan.' : 'No outlets found.');
            return self::INVALID;
        }
        $outletIds = array_map(fn ($row) => (string) $row['id'], $outlets);
        $months = WarmCommonRangeResolver::monthCursor($monthFrom, $monthTo);
        $monthLabels = array_map(fn (CarbonImmutable $month) => $month->format('Y-m'), $months);
        $withDaily = (bool) $this->option('with-daily');
        $forceDaily = (bool) $this->option('force-daily');
        $force = (bool) $this->option('force');
        $auditOnly = (bool) $this->option('audit');
        $verify = (bool) $this->option('verify');

        if ($forceDaily && ! $withDaily) {
            $this->error('--force-daily membutuhkan --with-daily.');
            return self::INVALID;
        }

        $this->newLine();
        $this->info('HF Materialization CLI Self-Healing - Monthly');
        $this->line(sprintf('Range        : %s -> %s (%d month(s))', reset($monthLabels), end($monthLabels), count($months)));
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));
        $this->line(sprintf('Operation    : %s', $auditOnly ? 'AUDIT ONLY' : ($force ? 'FORCE MONTHLY REBUILD' : 'NORMAL WARM')));
        $this->line(sprintf('Outlets      : %d (%s)', count($outlets), implode(', ', array_map(fn ($row) => $row['code'] ?: $row['id'], $outlets))));
        $this->line(sprintf('Chunk        : %d outlet(s)', $outletChunk));
        $this->line(sprintf('With Daily   : %s%s', $withDaily ? 'YES' : 'NO', $forceDaily ? ' [FORCE DAILY]' : ''));

        if ($auditOnly) {
            $audit = $selfHealing->auditMonthly($outlets, $months);
            $this->renderAudit($audit);
            return $audit['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $workItems = [];
        $alreadyReady = 0;
        foreach ($months as $month) {
            $monthStart = $month->startOfMonth()->toDateString();
            $status = $this->safeMonthlyStatus($monthly, $outletIds, $monthStart);
            if (! $force && ($status['ready'] ?? false) === true) {
                $alreadyReady++;
                continue;
            }

            foreach (array_chunk($outletIds, $outletChunk) as $chunkOutletIds) {
                if (! $force) {
                    $chunkStatus = $this->safeMonthlyStatus($monthly, $chunkOutletIds, $monthStart);
                    if (($chunkStatus['ready'] ?? false) === true) continue;
                }
                $workItems[] = [$month, $chunkOutletIds];
            }
        }

        if ($workItems === []) {
            $this->info(sprintf('Monthly requested window already ready. %d month(s) checked. Use --force --verify for integrity repair.', count($months)));
            if ($verify) {
                $audit = $selfHealing->auditMonthly($outlets, $months);
                $this->renderAudit($audit);
                return $audit['ok'] ? self::SUCCESS : self::FAILURE;
            }
            return self::SUCCESS;
        }

        if ($alreadyReady > 0) $this->comment(sprintf('%d month(s) already ready and skipped.', $alreadyReady));
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
            $message = sprintf('%s | %d outlet(s)%s', $month->format('Y-m'), count($chunkOutletIds), $force ? ' | FORCE' : '');
            $bar->setMessage($message);
            $startedAt = microtime(true);

            try {
                if ($withDaily) {
                    $dailyOptions = ['outlet_chunk' => $outletChunk, 'date_chunk_days' => $dateChunk];
                    if ($forceDaily) {
                        $daily->refreshExactCoverage($chunkOutletIds, $monthStart, $monthEnd, $timezone, $dailyOptions);
                    } else {
                        $daily->ensureCoverage($chunkOutletIds, $monthStart, $monthEnd, $timezone, $dailyOptions);
                    }
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
                    $this->comment('       Jalankan Daily bulan ini dulu, atau ulangi Monthly dengan --with-daily / --with-daily --force-daily.');
                }
                $bar->display();
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        if ($processedOutlets > 0) AnalyticsResponseCache::bumpVersion('monthly-summary-warm:'.$processedOutlets);

        if ($skippedChunks > 0) {
            $this->error(sprintf('Monthly materialization incomplete: %d chunk(s) skipped.', $skippedChunks));
            return self::FAILURE;
        }

        if ($verify) {
            $this->info('Integrity Verification: SUM Daily -> Monthly');
            $audit = $selfHealing->auditMonthly($outlets, $months);
            $this->renderAudit($audit);
            if (! $audit['ok']) {
                $this->error(sprintf('Monthly verification FAILED: %d outlet-month mismatch(es).', $audit['issues']));
                return self::FAILURE;
            }
        }

        $this->info(sprintf('Monthly %s complete: %d outlet-month row(s) processed.', $force ? 'FORCE repair' : 'warm', $processedOutlets));
        return self::SUCCESS;
    }

    private function renderAudit(array $audit): void
    {
        $this->table(
            ['Outlet', 'Month', 'Daily Cov', 'Daily Trx', 'Monthly Trx', 'Daily Sales', 'Monthly Sales', 'Coverage', 'Status'],
            array_map(fn ($row) => [
                $row['outlet'], $row['month'], $row['daily_cov'], $row['daily_trx'], $row['monthly_trx'],
                number_format((int) $row['daily_sales']), number_format((int) $row['monthly_sales']),
                $row['coverage'], $row['status'],
            ], $audit['rows'])
        );
        $audit['ok'] ? $this->info('Integrity: PASS') : $this->warn(sprintf('Integrity: MISMATCH (%d row(s)).', $audit['issues']));
    }

    private function safeMonthlyStatus(ReportMonthlySummaryService $service, array $outletIds, string $businessMonth): array
    {
        try {
            return $service->readContractStatus($outletIds, $businessMonth);
        } catch (\Throwable $e) {
            if ($this->output->isVerbose()) $this->warn('Monthly status unavailable: '.$e->getMessage());
            return ['ready' => false];
        }
    }
}
