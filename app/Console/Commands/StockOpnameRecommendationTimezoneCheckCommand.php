<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class StockOpnameRecommendationTimezoneCheckCommand extends Command
{
    protected $signature = 'stock-inventory:smoke-check-opname-recommendation-timezone';

    protected $description = 'Validate Stock Opname local-date and Stock Request recommendation timezone hotfix.';

    public function handle(): int
    {
        $service = app_path('Services/Warehouse/WarehouseStockRequestService.php');
        $opnamePage = base_path('../frontend - Backoffice/src/pages/stock-inventory/StockOpnamePage.vue');
        $requestPage = base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseStockRequestPage.vue');

        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $opnameSource = is_file($opnamePage) ? (string) file_get_contents($opnamePage) : '';
        $requestSource = is_file($requestPage) ? (string) file_get_contents($requestPage) : '';

        $checks = [
            ['Stock Opname tables', Schema::hasTable('stk_stock_opnames') && Schema::hasTable('stk_stock_opname_items') ? 'OK' : 'MISSING'],
            ['Frontend local date', str_contains($opnameSource, "timeZone: 'Asia/Jakarta'") && ! str_contains($opnameSource, 'new Date().toISOString().slice(0, 10)') ? 'OK' : 'FAILED'],
            ['Outlet timezone lookup', str_contains($serviceSource, '$outletTimezone') && str_contains($serviceSource, 'CarbonImmutable::now($outletTimezone)') ? 'OK' : 'FAILED'],
            ['Legacy UTC recovery', str_contains($serviceSource, 'date_recovered_from_submission') && str_contains($serviceSource, "whereBetween('submitted_at'") ? 'OK' : 'FAILED'],
            ['Recovery UI notice', str_contains($requestSource, 'date_recovered_from_submission') ? 'OK' : 'FAILED'],
        ];

        $failed = collect($checks)->contains(fn (array $row): bool => ! in_array($row[1], ['OK'], true));
        $checks[] = ['Status', $failed ? 'FAILED' : 'PASSED'];

        $this->table(['Check', 'Result'], $checks);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
