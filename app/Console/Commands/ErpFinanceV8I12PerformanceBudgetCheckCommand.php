<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpFinanceV8I12PerformanceBudgetCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i12-performance-budget-check
        {--days=370 : Historical range to probe}
        {--outlet= : Optional outlet/warehouse ULID}
        {--strict : Return non-zero when a measured budget is exceeded}';

    protected $description = 'V8 I12 runtime/static gate for historical query performance budgets and sargable read paths.';

    private bool $failed = false;

    public function handle(): int
    {
        $maxDays = max(1, (int) config('report_performance_budget.max_range_days', 370));
        $days = min($maxDays, max(1, (int) $this->option('days')));
        $to = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $from = CarbonImmutable::parse($to, 'Asia/Jakarta')->subDays($days - 1)->toDateString();
        $outlet = trim((string) ($this->option('outlet') ?? '')) ?: null;

        $this->info("ERP FINANCE V8 I12 performance budget gate {$from} .. {$to} ({$days} days)");
        $this->line(sprintf(
            'Budgets: aggregate=%dms | detail-page=%dms | lightweight=%dms',
            (int) config('report_performance_budget.aggregate_370_ms', 3000),
            (int) config('report_performance_budget.detail_first_page_ms', 3000),
            (int) config('report_performance_budget.lightweight_read_ms', 1000),
        ));

        $this->staticGuards();
        $this->indexGuards();
        $this->runtimeProbes($from, $to, $outlet);

        if ($this->failed && $this->option('strict')) {
            $this->error('I12 strict performance gate FAILED.');
            return self::FAILURE;
        }

        $this->info($this->failed
            ? 'I12 completed with warnings/budget violations. Re-run with --strict after tuning.'
            : 'I12 performance/static gate passed.');

        return self::SUCCESS;
    }

    private function staticGuards(): void
    {
        $targets = [
            app_path('Services/Cogs/HistoryStockService.php'),
            app_path('Services/Finance/FinanceSettlementService.php'),
            app_path('Http/Controllers/Api/V1/Finance/FinanceSettlementController.php'),
            app_path('Services/Warehouse/FinanceV8/WarehouseFinanceReportingV8Service.php'),
            app_path('Services/Warehouse/WarehouseV2DashboardService.php'),
            app_path('Services/Warehouse/PettyCash/I08/WarehousePettyCashI08Service.php'),
            app_path('Services/Warehouse/PettyCash/I09/WarehouseExpenseReportI09Service.php'),
        ];

        foreach ($targets as $path) {
            if (! is_file($path)) {
                $this->warn('[SKIP] Missing source '.$path);
                continue;
            }
            $source = (string) file_get_contents($path);
            $ok = ! str_contains($source, 'whereDate(');
            $this->status($ok, 'Sargable DATE predicates: '.basename($path));
        }


        foreach ([
            app_path('Services/Finance'),
            app_path('Http/Controllers/Api/V1/Finance'),
            app_path('Services/Warehouse'),
            app_path('Services/Cogs'),
            app_path('Http/Controllers/Api/V1/Cogs'),
        ] as $directory) {
            if (! is_dir($directory)) continue;
            $violations = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
                $source = (string) file_get_contents($file->getPathname());
                if (str_contains($source, 'whereDate(')) $violations[] = $file->getPathname();
            }
            $this->status($violations === [], 'No non-sargable whereDate() in '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $directory));
            foreach (array_slice($violations, 0, 5) as $violation) $this->line('  '.$violation);
        }

        $settlementController = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/Finance/FinanceSettlementController.php'));
        $this->status(! str_contains($settlementController, '->syncSources('), 'Settlement list has no synchronous HTTP source rebuild');

        $warehouseFinance = (string) @file_get_contents(app_path('Services/Warehouse/FinanceV8/WarehouseFinanceReportingV8Service.php'));
        $this->status(str_contains($warehouseFinance, 'set-based Cash Flow') && ! str_contains($warehouseFinance, 'foreach($postings as $p)'), 'Warehouse Cash Flow avoids per-posting N+1');
    }

    private function indexGuards(): void
    {
        foreach ([
            ['finance_settlement_sources', 'fin_v8_i12_stls_out_date_stat_idx'],
            ['finance_settlements', 'fin_v8_i12_stl_out_date_stat_idx'],
            ['stk_inventory_movements', 'fin_v8_i12_inv_out_date_type_idx'],
            ['wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_biz_idx'],
            ['wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_jrn_idx'],
            ['wh_receivings', 'fin_v8_i12_recv_wh_stat_done_idx'],
            ['wh_stock_ins', 'fin_v8_i12_stkin_wh_stat_appr_idx'],
        ] as [$table, $index]) {
            if (! Schema::hasTable($table)) {
                $this->warn("[SKIP] {$table} does not exist.");
                continue;
            }
            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
            $this->status($exists, "Index {$index}");
        }
    }

    private function runtimeProbes(string $from, string $to, ?string $outlet): void
    {
        $pageSize = max(10, min(200, (int) config('report_performance_budget.sample_page_size', 100)));
        $aggregateBudget = (int) config('report_performance_budget.aggregate_370_ms', 3000);
        $detailBudget = (int) config('report_performance_budget.detail_first_page_ms', 3000);
        $lightBudget = (int) config('report_performance_budget.lightweight_read_ms', 1000);

        if (Schema::hasTable('report_monthly_sales_summaries')) {
            $fromMonth = CarbonImmutable::parse($from)->startOfMonth()->toDateString();
            $toMonth = CarbonImmutable::parse($to)->startOfMonth()->toDateString();
            $q = DB::table('report_monthly_sales_summaries')->whereBetween('business_month', [$fromMonth, $toMonth]);
            if ($outlet) $q->where('outlet_id', $outlet);
            $this->probe('Monthly sales aggregate', fn () => $q->selectRaw('SUM(grand_sales) gross, SUM(trx_count) trx')->first(), $aggregateBudget);
        }

        if (Schema::hasTable('report_daily_sales_summaries')) {
            $q = DB::table('report_daily_sales_summaries')->whereBetween('business_date', [$from, $to]);
            if ($outlet) $q->where('outlet_id', $outlet);
            $this->probe('Daily sales aggregate fallback', fn () => $q->selectRaw('SUM(grand_sales) gross, SUM(trx_count) trx')->first(), $aggregateBudget);
        }

        if (Schema::hasTable('finance_settlement_sources')) {
            $q = DB::table('finance_settlement_sources')->whereBetween('business_date', [$from, $to]);
            if ($outlet) $q->where('outlet_id', $outlet);
            $this->probe('Settlement source first page', fn () => $q->orderByDesc('business_date')->limit($pageSize)->get(['id','business_date','status','outlet_id']), $detailBudget, $q);
        }

        if (Schema::hasTable('stk_inventory_movements')) {
            $q = DB::table('stk_inventory_movements')->whereBetween('business_date', [$from, $to]);
            if ($outlet) $q->where('outlet_id', $outlet);
            $this->probe('COGS inventory movement first page', fn () => $q->orderByDesc('business_date')->orderByDesc('created_at')->limit($pageSize)->get(['id','outlet_id','business_date','movement_type']), $detailBudget, $q);
        }

        if (Schema::hasTable('wh_v4_finance_general_postings')) {
            $q = DB::table('wh_v4_finance_general_postings')->whereIn('status', ['POSTED','REVERSED'])->whereBetween('business_date', [$from, $to]);
            if ($outlet) $q->where('warehouse_id', $outlet);
            $this->probe('Warehouse posting scope', fn () => $q->orderByDesc('business_date')->limit($pageSize)->get(['id','warehouse_id','business_date','source_type']), $lightBudget, $q);
        }

        if (Schema::hasTable('wh_receivings')) {
            $endExclusive = CarbonImmutable::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s');
            $q = DB::table('wh_receivings')
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $from.' 00:00:00')
                ->where('completed_at', '<', $endExclusive);
            if ($outlet) $q->where('warehouse_id', $outlet);
            $this->probe('Warehouse receiving historical page', fn () => $q->orderByDesc('completed_at')->limit($pageSize)->get(['id','warehouse_id','status','completed_at']), $detailBudget, $q);
        }

        if (Schema::hasTable('wh_stock_ins')) {
            $endExclusive = CarbonImmutable::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s');
            $q = DB::table('wh_stock_ins')
                ->whereNotNull('approved_at')
                ->where('approved_at', '>=', $from.' 00:00:00')
                ->where('approved_at', '<', $endExclusive);
            if ($outlet) $q->where('warehouse_id', $outlet);
            $this->probe('Warehouse stock-in historical page', fn () => $q->orderByDesc('approved_at')->limit($pageSize)->get(['id','warehouse_id','status','approved_at']), $detailBudget, $q);
        }
    }

    private function probe(string $label, callable $callback, int $budgetMs, ?Builder $explainQuery = null): void
    {
        try {
            $started = microtime(true);
            $callback();
            $elapsed = round((microtime(true) - $started) * 1000, 2);
            $ok = $elapsed <= $budgetMs;
            $this->status($ok, "{$label}: {$elapsed}ms / {$budgetMs}ms");

            if ($explainQuery) {
                $plan = DB::select('EXPLAIN '.$explainQuery->toSql(), $explainQuery->getBindings());
                $first = (array) ($plan[0] ?? []);
                if ($first !== []) {
                    $this->line('  EXPLAIN type='.($first['type'] ?? '-').' key='.($first['key'] ?? '-').' rows='.($first['rows'] ?? '-'));
                }
            }
        } catch (Throwable $e) {
            $this->failed = true;
            $this->warn("[ERROR] {$label}: {$e->getMessage()}");
        }
    }

    private function status(bool $ok, string $label): void
    {
        if ($ok) {
            $this->line('[PASS] '.$label);
            return;
        }
        $this->warn('[WARN] '.$label);
        $this->failed = true;
    }
}
