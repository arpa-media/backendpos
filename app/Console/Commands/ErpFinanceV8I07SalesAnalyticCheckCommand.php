<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I07SalesAnalyticCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i07-sales-analytic-check {--date=}';
    protected $description = 'Read-only health gate for V8 I07 Sales Analytic foundation and Daily Analytic.';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: now()->subDay()->toDateString());
        $checks = [];

        foreach (['report_daily_sales_summaries', 'report_daily_summary_coverage', 'outlets', 'access_portals', 'access_menus'] as $table) {
            $checks[] = ["Table {$table}", Schema::hasTable($table) ? 'PASS' : 'FAIL'];
        }

        $checks[] = ['Daily API route', Route::has('operational.sales-analytic.daily') ? 'PASS' : 'FAIL'];

        foreach ([
            'operational.sales_analytic.daily.view',
            'operational.sales_analytic.hourly.view',
            'operational.sales_analytic.hourly_summary.view',
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

        $servicePath = app_path('Services/Operational/OperationalSalesAnalyticService.php');
        $serviceSource = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
        $rawSalesHydration = str_contains($serviceSource, "DB::table('sales") || str_contains($serviceSource, 'Sale::query(');
        $checks[] = ['Daily Analytic avoids raw all-sales hydration', ! $rawSalesHydration ? 'PASS' : 'FAIL'];

        if (Schema::hasTable('report_daily_sales_summaries')) {
            try {
                $sql = DB::table('report_daily_sales_summaries')
                    ->where('business_date', $date)
                    ->select(['outlet_id', 'business_date', 'trx_count', 'grand_sales'])
                    ->toSql();
                $explain = DB::select('EXPLAIN '.$sql, [$date]);
                $this->newLine();
                $this->info("EXPLAIN daily materialized read ({$date})");
                $this->table(['table', 'type', 'possible_keys', 'key', 'rows'], array_map(fn ($row) => [
                    $row->table ?? '-', $row->type ?? '-', $row->possible_keys ?? '-', $row->key ?? '-', $row->rows ?? '-',
                ], $explain));
            } catch (\Throwable $e) {
                $checks[] = ['EXPLAIN daily materialized read', 'FAIL: '.$e->getMessage()];
            }
        }

        $this->newLine();
        $this->table(['Check', 'Result'], $checks);
        $failed = collect($checks)->contains(fn ($row) => ! str_starts_with((string) ($row[1] ?? ''), 'PASS'));
        $this->line($failed ? 'I07 CHECK: FAILED' : 'I07 CHECK: PASSED');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
