<?php

namespace App\Console\Commands;

use App\Services\Operational\ReportHourlySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\Reporting\MaterializationCliSelfHealing;
use App\Support\Reporting\WarmCommonRangeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarmReportHourlySummariesCommand extends Command
{
    protected $signature = 'report-hourly-summaries:warm-common
        {--days=370 : Range hari rolling jika --month/--from/--to tidak diisi}
        {--month= : Proses satu bulan tertentu, format YYYY-MM; bulan berjalan dipotong sampai hari ini}
        {--from= : Tanggal awal exact range, format YYYY-MM-DD}
        {--to= : Tanggal akhir exact range, format YYYY-MM-DD}
        {--outlet=* : Filter outlet berdasarkan ID/kode/nama; dapat diulang atau dipisah koma}
        {--mode=normal : Preset eksekusi: safe, normal, fast}
        {--force : Abaikan stale gate dan rebuild seluruh outlet-date dalam range}
        {--audit : Read-only integrity audit Daily -> Hourly}
        {--verify : Verifikasi total Daily = SUM Hourly dan freshness coverage}';

    protected $description = 'Warm/repair Hourly materialization with outlet targeting, force rebuild, audit, and verification.';

    public function handle(ReportHourlySummaryService $service, MaterializationCliSelfHealing $selfHealing): int
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');

        try {
            [$from, $to, $resolvedMonth] = WarmCommonRangeResolver::resolveDateRange(
                $this->option('month'), $this->option('from'), $this->option('to'), (int) $this->option('days'), $timezone
            );
            $mode = WarmCommonRangeResolver::normalizeMode((string) $this->option('mode'));
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
        $outletSet = array_fill_keys($outletIds, true);
        $days = CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) + 1;
        $force = (bool) $this->option('force');
        $auditOnly = (bool) $this->option('audit');
        $verify = (bool) $this->option('verify');

        $this->newLine();
        $this->info('HF Materialization CLI Self-Healing - Hourly');
        $this->line(sprintf('Range        : %s -> %s (%d days)', $from, $to, $days));
        if ($resolvedMonth !== null) $this->line(sprintf('Month        : %s', $resolvedMonth));
        $this->line(sprintf('Mode         : %s', strtoupper($mode)));
        $this->line(sprintf('Operation    : %s', $auditOnly ? 'AUDIT ONLY' : ($force ? 'FORCE REBUILD' : 'NORMAL WARM')));
        $this->line(sprintf('Outlets      : %d (%s)', count($outlets), implode(', ', array_map(fn ($row) => $row['code'] ?: $row['id'], $outlets))));

        if ($auditOnly) {
            $audit = $selfHealing->auditHourly($outlets, $from, $to, $timezone);
            $this->renderAudit($audit);
            return $audit['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if ($force && Schema::hasTable('report_daily_summary_coverage')) {
            $dailyCoverageRows = DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id', $outletIds)->whereBetween('business_date', [$from, $to])->count();
            $expected = count($outletIds) * $days;
            if ($dailyCoverageRows < $expected) {
                $this->error(sprintf('Daily coverage belum lengkap: %d/%d outlet-days. Jalankan Daily --force --verify terlebih dahulu.', $dailyCoverageRows, $expected));
                return self::FAILURE;
            }
        }

        if ($force) {
            $pairs = collect();
            for ($cursor = CarbonImmutable::parse($from, $timezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($to, $timezone)); $cursor = $cursor->addDay()) {
                foreach ($outletIds as $outletId) {
                    $pairs->push((object) ['outlet_id' => $outletId, 'business_date' => $cursor->toDateString()]);
                }
            }
        } else {
            $pairs = $service->stalePairs($from, $to)
                ->filter(fn ($row) => isset($outletSet[(string) ($row->outlet_id ?? '')]))
                ->values();
        }

        if ($pairs->isEmpty()) {
            $this->info('Hourly requested window already warm. Use --force --verify if integrity repair is required.');
            if ($verify) {
                $audit = $selfHealing->auditHourly($outlets, $from, $to, $timezone);
                $this->renderAudit($audit);
                return $audit['ok'] ? self::SUCCESS : self::FAILURE;
            }
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

        try {
            foreach ($groups as $date => $rows) {
                $dateOutletIds = $rows->pluck('outlet_id')->map(fn ($value) => (string) $value)->filter()->unique()->values()->all();
                if ($date !== '' && $dateOutletIds !== []) {
                    $startedAt = microtime(true);
                    $bar->setMessage(sprintf('%s | %d outlet(s)%s', $date, count($dateOutletIds), $force ? ' | FORCE' : ''));
                    $service->refreshDate($dateOutletIds, $date);
                    $processed += count($dateOutletIds);
                    if ($this->output->isVeryVerbose()) {
                        $bar->clear();
                        $this->line(sprintf('  OK %s | %d outlet(s) | %.2fs', $date, count($dateOutletIds), microtime(true) - $startedAt));
                        $bar->display();
                    }
                }
                $bar->advance();
            }
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('Hourly materialization failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);
        if ($processed > 0) AnalyticsResponseCache::bumpVersion('hourly-summary-warm:'.$processed);

        if ($verify) {
            $this->info('Integrity Verification: Daily -> SUM Hourly');
            $audit = $selfHealing->auditHourly($outlets, $from, $to, $timezone);
            $this->renderAudit($audit);
            if (! $audit['ok']) {
                $this->error(sprintf('Hourly verification FAILED: %d outlet-date mismatch(es).', $audit['issues']));
                return self::FAILURE;
            }
        }

        $this->info(sprintf('Hourly %s complete: %d outlet-date row(s) across %d day(s).', $force ? 'FORCE repair' : 'warm', $processed, $days));
        return self::SUCCESS;
    }

    private function renderAudit(array $audit): void
    {
        $this->table(
            ['Outlet', 'Date', 'Daily Trx', 'Hourly Trx', 'Daily Sales', 'Hourly Sales', 'Coverage', 'Status'],
            array_map(fn ($row) => [
                $row['outlet'], $row['date'], $row['daily_trx'], $row['hourly_trx'],
                number_format((int) $row['daily_sales']), number_format((int) $row['hourly_sales']),
                $row['coverage'], $row['status'],
            ], $audit['rows'])
        );
        $audit['ok'] ? $this->info('Integrity: PASS') : $this->warn(sprintf('Integrity: MISMATCH (%d row(s)).', $audit['issues']));
    }
}
