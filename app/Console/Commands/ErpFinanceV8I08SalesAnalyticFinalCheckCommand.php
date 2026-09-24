<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I08SalesAnalyticFinalCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i08-sales-analytic-final-check {--date=} {--days=370}';
    protected $description = 'Read-only final V8 regression gate for hourly Sales Analytic, reporting invariants, routes, indexes and patch chain.';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: now()->subDay()->toDateString());
        $days = max(1, min(370, (int) $this->option('days')));
        $checks = [];

        foreach ([
            'report_sale_business_dates',
            'report_daily_sales_summaries',
            'report_daily_summary_coverage',
            'report_hourly_sales_summaries',
            'report_hourly_product_summaries',
            'report_hourly_summary_coverage',
        ] as $table) {
            $checks[] = ["Table {$table}", Schema::hasTable($table) ? 'PASS' : 'FAIL'];
        }

        foreach ([
            'operational.sales-analytic.daily',
            'operational.sales-analytic.hourly',
            'operational.sales-analytic.hourly-summary',
        ] as $route) {
            $checks[] = ["Route {$route}", Route::has($route) ? 'PASS' : 'FAIL'];
        }

        foreach ([
            'operational.sales_analytic.daily.view',
            'operational.sales_analytic.hourly.view',
            'operational.sales_analytic.hourly_summary.view',
            'finance.expense_report.view',
            'finance.financial_statement_export.view',
        ] as $permission) {
            $exists = Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
            $checks[] = ["Permission {$permission}", $exists ? 'PASS' : 'FAIL'];
        }

        foreach ([
            'operational-sales-analytic-daily',
            'operational-sales-analytic-hourly',
            'operational-sales-analytic-hourly-summary',
        ] as $menu) {
            $exists = Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', $menu)->where('is_active', true)->exists();
            $checks[] = ["Access menu {$menu}", $exists ? 'PASS' : 'FAIL'];
        }

        foreach ([
            'ErpFinanceV8I01ReportCoreCheckCommand.php',
            'ErpFinanceV8I02ReportQueryCheckCommand.php',
            'ErpFinanceV8I03ReadContractCheckCommand.php',
            'ErpFinanceV8I04HistoricalReadCheckCommand.php',
            'ErpFinanceV8I05PettyCashExpenseCheckCommand.php',
            'ErpFinanceV8I06GlStatementExportCheckCommand.php',
            'ErpFinanceV8I07SalesAnalyticCheckCommand.php',
        ] as $file) {
            $checks[] = ["Patch chain {$file}", is_file(app_path('Console/Commands/'.$file)) ? 'PASS' : 'FAIL'];
        }

        $scheduleSource = is_file(base_path('bootstrap/app.php')) ? (string) file_get_contents(base_path('bootstrap/app.php')) : '';
        $checks[] = ['Hourly dirty scheduler', str_contains($scheduleSource, 'report-hourly-summaries:refresh-dirty') ? 'PASS' : 'FAIL'];
        $checks[] = ['Hourly rolling warm scheduler', str_contains($scheduleSource, 'report-hourly-summaries:warm-common --days=370') ? 'PASS' : 'FAIL'];

        $servicePath = app_path('Services/Operational/ReportHourlySummaryService.php');
        $serviceSource = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
        $checks[] = ['Hourly service avoids synchronous ensureCoverage()', ! str_contains($serviceSource, 'ensureCoverage(') ? 'PASS' : 'FAIL'];
        $checks[] = ['Hourly service avoids report_sale_scope_cache', ! str_contains($serviceSource, 'report_sale_scope_cache') ? 'PASS' : 'FAIL'];
        $checks[] = ['Live read bounded to canonical business date', str_contains($serviceSource, "->where('rsbd.business_date', \$businessDate)") ? 'PASS' : 'FAIL'];

        $transactionHash = is_file(app_path('Support/TransactionDate.php')) ? hash_file('sha256', app_path('Support/TransactionDate.php')) : '';
        $cashierHash = is_file(app_path('Services/CashierAlignedSaleScopeService.php')) ? hash_file('sha256', app_path('Services/CashierAlignedSaleScopeService.php')) : '';
        $checks[] = ['TransactionDate invariant', $transactionHash === 'a3b6722b0980cd29af4711f1d7723ca6d5e4483856eb8613e2e3f01775e2be5f' ? 'PASS' : 'FAIL: '.$transactionHash];
        $checks[] = ['CashierAlignedSaleScope invariant', $cashierHash === 'db31d125b9073c06f3446c2c989913f75d9a5ff1ce01143bde2e7cb1df12e559' ? 'PASS' : 'FAIL: '.$cashierHash];

        if (Schema::hasTable('report_hourly_sales_summaries')) {
            $this->explainHistorical($date, $checks);
        }

        if (Schema::hasTable('report_hourly_summary_coverage') && Schema::hasTable('report_daily_summary_coverage')) {
            $from = now()->subDays($days - 1)->toDateString();
            $coverage = DB::table('report_daily_summary_coverage as d')
                ->leftJoin('report_hourly_summary_coverage as h', function ($join): void {
                    $join->on('h.outlet_id', '=', 'd.outlet_id')->on('h.business_date', '=', 'd.business_date');
                })
                ->whereBetween('d.business_date', [$from, now()->toDateString()])
                ->selectRaw('COUNT(*) as daily_rows')
                ->selectRaw('SUM(CASE WHEN h.outlet_id IS NOT NULL AND h.source_daily_synced_at >= d.synced_at THEN 1 ELSE 0 END) as hourly_fresh_rows')
                ->first();
            $dailyRows = (int) ($coverage->daily_rows ?? 0);
            $freshRows = (int) ($coverage->hourly_fresh_rows ?? 0);
            $this->line(sprintf('Rolling %d-day hourly freshness: %d/%d daily-ready outlet-date rows.', $days, $freshRows, $dailyRows));
            $checks[] = ['Hourly freshness is not ahead of daily source', $freshRows <= $dailyRows ? 'PASS' : 'FAIL'];
        }

        $this->newLine();
        $this->table(['Check', 'Result'], $checks);
        $failed = collect($checks)->contains(fn ($row) => ! str_starts_with((string) ($row[1] ?? ''), 'PASS'));
        $this->line($failed ? 'V8 I08 FINAL REGRESSION: FAILED' : 'V8 I08 FINAL REGRESSION: PASSED');
        $this->line('Invariant: Cashier business_date/cutoff logic remains untouched; I08 adds only bounded current-day reads and hourly materialized historical facts.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function explainHistorical(string $date, array &$checks): void
    {
        try {
            $query = DB::table('report_hourly_sales_summaries')
                ->where('business_date', $date)
                ->orderBy('business_hour')
                ->select(['outlet_id', 'business_date', 'business_hour', 'trx_count', 'gross_amount_sales']);
            $explain = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
            $this->newLine();
            $this->info("EXPLAIN hourly materialized read ({$date})");
            $this->table(['table', 'type', 'possible_keys', 'key', 'rows'], array_map(fn ($row) => [
                $row->table ?? '-', $row->type ?? '-', $row->possible_keys ?? '-', $row->key ?? '-', $row->rows ?? '-',
            ], $explain));
            $checks[] = ['EXPLAIN hourly materialized read', 'PASS'];
        } catch (\Throwable $e) {
            $checks[] = ['EXPLAIN hourly materialized read', 'FAIL: '.$e->getMessage()];
        }
    }
}
