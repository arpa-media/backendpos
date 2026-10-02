<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I14OperationalBusinessDateCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i14-operational-check';

    protected $description = 'Verify V10 I14 Operational rename, Daily Analytic range, and cashier-aligned business-date contract.';

    public function handle(): int
    {
        $checks = [];

        $checks['access portal renamed to Operational'] = Schema::hasTable('access_portals')
            && (string) DB::table('access_portals')->where('code', 'operational')->value('name') === 'Operational';

        $checks['canonical business-date index exists'] = Schema::hasTable('report_sale_business_dates');
        $checks['daily materialization exists'] = Schema::hasTable('report_daily_sales_summaries');
        $checks['hourly materialization exists'] = Schema::hasTable('report_hourly_sales_summaries');

        $checks['Jakarta cashier cutoff = 00:00'] = TransactionDate::businessDayStartHour('Asia/Jakarta') === 0;
        $checks['Makassar cashier cutoff = 01:00'] = TransactionDate::businessDayStartHour('Asia/Makassar') === 1;

        $checks['Daily Analytic API route registered'] = Route::getRoutes()->getByName('operational.sales-analytic.daily') !== null;
        $checks['Omzet Per Hour API route registered'] = Route::getRoutes()->getByName('operational.sales-analytic.hourly') !== null;
        $checks['Summary Per Hour API route registered'] = Route::getRoutes()->getByName('operational.sales-analytic.hourly-summary') !== null;

        $controller = $this->read(base_path('app/Http/Controllers/Api/V1/Operational/OperationalSalesAnalyticController.php'));
        $service = $this->read(base_path('app/Services/Operational/OperationalSalesAnalyticService.php'));
        $dailyPage = $this->read(base_path('../frontend - Backoffice/src/modules/operational/pages/OperationalDailyAnalyticPage.vue'));
        $hourlyPage = $this->read(base_path('../frontend - Backoffice/src/modules/operational/pages/OperationalHourlyAnalyticPage.vue'));
        $summaryPage = $this->read(base_path('../frontend - Backoffice/src/modules/operational/pages/OperationalHourlySummaryPage.vue'));
        $runtimeBrand = $this->read(base_path('../frontend - Backoffice/src/modules/operational/route-modules/14-v10-i14-operational-brand.js'));

        $checks['Daily API accepts date_from/date_to'] = str_contains($controller, "'date_from'")
            && str_contains($controller, "'date_to'")
            && str_contains($controller, 'reportingStatusRange');
        $checks['Daily service aggregates range'] = str_contains($service, 'dailyRange(')
            && str_contains($service, 'salesSummaryQuery($outletIds, $dateFrom, $dateTo, $timezone)');
        $checks['Business-date metadata exposed'] = str_contains($controller, "'business_date_contract' => 'cashier_aligned_v1'")
            && str_contains($controller, 'business_day_start_hour');
        $checks['Daily UI has Date From / Date To'] = str_contains($dailyPage, 'filters.date_from')
            && str_contains($dailyPage, 'filters.date_to')
            && str_contains($dailyPage, 'Date From')
            && str_contains($dailyPage, 'Date To');
        $checks['Sales Analytic UI branded Operational'] = ! str_contains($dailyPage, 'Chambers Operational')
            && ! str_contains($hourlyPage, 'Chambers Operational')
            && ! str_contains($summaryPage, 'Chambers Operational');
        $checks['Frontend fallback portal name overridden'] = str_contains($runtimeBrand, "PORTAL_DEFINITIONS.operational.name = 'Operational'");

        $failed = [];
        foreach ($checks as $label => $ok) {
            if ($ok) {
                $this->components->info("PASS — {$label}");
            } else {
                $this->components->error("FAIL — {$label}");
                $failed[] = $label;
            }
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('ERP POS V10 I14 Operational check FAILED.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I14 Operational Business Date is READY.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
