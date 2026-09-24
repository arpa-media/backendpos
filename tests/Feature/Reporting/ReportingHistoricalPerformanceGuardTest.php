<?php

namespace Tests\Feature\Reporting;

use Tests\TestCase;

class ReportingHistoricalPerformanceGuardTest extends TestCase
{
    public function test_i12_hot_paths_do_not_wrap_indexed_date_columns_in_date_function(): void
    {
        foreach ([
            app_path('Services/Cogs/HistoryStockService.php'),
            app_path('Services/Finance/FinanceSettlementService.php'),
            app_path('Http/Controllers/Api/V1/Finance/FinanceSettlementController.php'),
            app_path('Services/Warehouse/FinanceV8/WarehouseFinanceReportingV8Service.php'),
            app_path('Services/Warehouse/WarehouseV2DashboardService.php'),
            app_path('Services/Warehouse/PettyCash/I08/WarehousePettyCashI08Service.php'),
            app_path('Services/Warehouse/PettyCash/I09/WarehouseExpenseReportI09Service.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('whereDate(', $source, $path);
        }
    }

    public function test_settlement_historical_list_does_not_sync_sources_inside_http(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/Finance/FinanceSettlementController.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('->syncSources(', $source);
    }

    public function test_warehouse_cash_flow_is_set_based(): void
    {
        $source = file_get_contents(app_path('Services/Warehouse/FinanceV8/WarehouseFinanceReportingV8Service.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('set-based Cash Flow', $source);
        $this->assertStringNotContainsString('foreach($postings as $p)', $source);
    }

    public function test_performance_budgets_are_defined(): void
    {
        $this->assertSame(3000, (int) config('report_performance_budget.aggregate_370_ms'));
        $this->assertSame(3000, (int) config('report_performance_budget.detail_first_page_ms'));
        $this->assertSame(1000, (int) config('report_performance_budget.lightweight_read_ms'));
    }

    public function test_finance_warehouse_and_cogs_hot_paths_do_not_reintroduce_where_date(): void
    {
        $directories = [
            app_path('Services/Finance'),
            app_path('Http/Controllers/Api/V1/Finance'),
            app_path('Services/Warehouse'),
            app_path('Services/Cogs'),
            app_path('Http/Controllers/Api/V1/Cogs'),
        ];

        $violations = [];
        foreach ($directories as $directory) {
            if (! is_dir($directory)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
                if (str_contains((string) file_get_contents($file->getPathname()), 'whereDate(')) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, 'Historical hot paths must keep indexed date predicates sargable.');
    }
}
