<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportCorrectnessAuditService;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I10ReportingCorrectnessCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i10-reporting-correctness-check
        {--date= : Closed business date to compare Raw vs Daily}
        {--month= : Full calendar month (YYYY-MM or YYYY-MM-DD) for Daily vs Monthly vs Hybrid}
        {--outlet= : Audit one outlet ULID; defaults to an outlet with materialized coverage}
        {--require-monthly : Fail when monthly materialization is not ready}';

    protected $description = 'V8 I10 correctness gate: raw/daily/monthly/hybrid parity and no-HTTP-backfill invariants.';

    public function handle(ReportCorrectnessAuditService $audit): int
    {
        $failures = 0;
        $this->info('ERP FINANCE V8 I10 — Reporting Correctness Gate');

        foreach ($this->staticChecks() as [$label, $ok, $detail]) {
            $this->line(sprintf('[%s] %s%s', $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : ''));
            if (! $ok) $failures++;
        }

        $date = trim((string) $this->option('date'));
        if ($date === '') {
            $date = $this->latestClosedCoverageDate();
        }
        $outletId = trim((string) $this->option('outlet'));
        if ($outletId === '' && $date !== '') {
            $outletId = (string) (DB::table('report_daily_summary_coverage')->where('business_date', $date)->orderBy('outlet_id')->value('outlet_id') ?? '');
        }

        if ($date === '' || $outletId === '') {
            $this->error('[FAIL] No materialized closed business date/outlet is available for Raw vs Daily parity. Materialize one date from Console > Control Center first.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Raw vs Daily parity — outlet={$outletId}, business_date={$date}");
        $day = $audit->auditDay([$outletId], $date);
        if (! ($day['coverage']['ready'] ?? false)) {
            $this->error('[FAIL] Daily materialization is not ready for the selected date. Use Console > Control Center and rerun this gate.');
            $failures++;
        } else {
            foreach ($day['families'] as $family => $result) {
                $ok = (bool) ($result['match'] ?? false);
                $this->line(sprintf('[%s] %-8s raw=%d daily=%d%s',
                    $ok ? 'PASS' : 'FAIL',
                    strtoupper($family),
                    (int) ($result['raw_rows'] ?? 0),
                    (int) ($result['daily_rows'] ?? 0),
                    $ok ? '' : ' mismatch=' . implode(',', $result['sample_mismatch_keys'] ?? [])
                ));
                if (! $ok) $failures++;
            }
        }

        $monthRaw = trim((string) $this->option('month'));
        $month = $monthRaw !== ''
            ? CarbonImmutable::parse($monthRaw)->startOfMonth()->toDateString()
            : CarbonImmutable::parse($date)->startOfMonth()->toDateString();

        $this->newLine();
        $this->info("Daily vs Monthly vs Hybrid parity — outlet={$outletId}, month={$month}");
        $monthAudit = $audit->auditMonth([$outletId], $month);
        if (! ($monthAudit['monthly_status']['ready'] ?? false)) {
            $message = 'Monthly facts are not ready for this month. Rebuild this full month through Console > Control Center, then rerun.';
            if ($this->option('require-monthly')) {
                $this->error('[FAIL] ' . $message);
                $failures++;
            } else {
                $this->warn('[WARN] ' . $message . ' Use --require-monthly for a strict deployment gate.');
            }
        } else {
            foreach ($monthAudit['families'] as $family => $result) {
                $ok = (bool) ($result['match'] ?? false);
                $this->line(sprintf('[%s] %-8s daily=monthly=%s daily=hybrid=%s',
                    $ok ? 'PASS' : 'FAIL',
                    strtoupper($family),
                    ($result['daily_vs_monthly']['match'] ?? false) ? 'YES' : 'NO',
                    ($result['daily_vs_hybrid']['match'] ?? false) ? 'YES' : 'NO'
                ));
                if (! $ok) $failures++;
            }
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("I10 correctness gate FAILED with {$failures} issue(s). Do not treat reporting deployment as verified until these are resolved.");
            return self::FAILURE;
        }

        $this->info('I10 correctness gate PASS. Raw/Daily parity is clean; monthly parity is clean when materialization is ready.');
        return self::SUCCESS;
    }

    private function staticChecks(): array
    {
        $root = dirname(__DIR__, 2);
        $reportService = @file_get_contents($root . '/Services/ReportService.php') ?: '';
        $transactionDate = $root . '/Support/TransactionDate.php';
        $cashierScope = $root . '/Services/CashierAlignedSaleScopeService.php';

        return [
            ['ReportService has no synchronous ->ensureCoverage()', ! str_contains($reportService, '->ensureCoverage('), 'HTTP item reports are read-only'],
            ['Daily coverage generation column exists', Schema::hasColumn('report_daily_summary_coverage', 'generation_ulid'), 'generation_ulid'],
            ['Monthly coverage generation column exists', Schema::hasColumn('report_monthly_summary_coverage', 'source_daily_generation_ulid'), 'source_daily_generation_ulid'],
            ['Monthly category fact uses surrogate id', Schema::hasColumn('report_monthly_category_summaries', 'id'), 'dimension collisions allowed safely'],
            ['Monthly product fact uses surrogate id', Schema::hasColumn('report_monthly_product_summaries', 'id'), 'dimension collisions allowed safely'],
            ['Monthly variant fact uses surrogate id', Schema::hasColumn('report_monthly_variant_summaries', 'id'), 'dimension collisions allowed safely'],
            ['TransactionDate invariant file present', is_file($transactionDate), is_file($transactionDate) ? hash_file('sha256', $transactionDate) : 'missing'],
            ['Cashier scope invariant file present', is_file($cashierScope), is_file($cashierScope) ? hash_file('sha256', $cashierScope) : 'missing'],
        ];
    }

    private function latestClosedCoverageDate(): string
    {
        if (! Schema::hasTable('report_daily_summary_coverage')) return '';
        $today = TransactionDate::businessTodayDateString(config('app.timezone', 'Asia/Jakarta'));
        return (string) (DB::table('report_daily_summary_coverage')
            ->where('business_date', '<', $today)
            ->max('business_date') ?? '');
    }
}
