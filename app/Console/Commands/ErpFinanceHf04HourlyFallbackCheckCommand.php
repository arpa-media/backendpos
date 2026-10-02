<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Illuminate\Console\Command;

class ErpFinanceHf04HourlyFallbackCheckCommand extends Command
{
    protected $signature = 'erp-finance:hf04-hourly-fallback-check';

    protected $description = 'Verify HF04 read-only one-business-date hourly historical fallback wiring.';

    public function handle(): int
    {
        $hourly = $this->read(app_path('Services/Operational/ReportHourlySummaryService.php'));
        $analytic = $this->read(app_path('Services/Operational/OperationalHourlySalesAnalyticService.php'));
        $top = $this->read(app_path('Services/Operational/OperationalDailyTopItemsByCategoryService.php'));
        $controller = $this->read(app_path('Http/Controllers/Api/V1/Operational/OperationalSalesAnalyticController.php'));

        $checks = [
            'Makassar cutoff remains 01:00' => TransactionDate::businessDayStartHour('Asia/Makassar') === 1,
            '00:59:59 WITA remains previous business date' =>
                TransactionDate::businessDateForSale('2026-09-26T00:59:59+08:00', 'Asia/Makassar') === '2026-09-25',
            '01:00:00 WITA starts next business date' =>
                TransactionDate::businessDateForSale('2026-09-26T01:00:00+08:00', 'Asia/Makassar') === '2026-09-26',
            'Exact hourly one-day fallback exists' => str_contains($hourly, 'public function exactSalesSeries('),
            'Exact fallback uses TransactionDate scope' => str_contains($hourly, 'TransactionDate::applyExactBusinessDateScope('),
            'Hourly comparison falls back instead of returning not-ready' =>
                str_contains($analytic, 'served_by_exact_historical_fallback')
                && str_contains($analytic, 'exactSalesSeries('),
            'Summary Per Hour exact top-item fallback exists' =>
                str_contains($top, 'exactHistoricalFallback')
                && str_contains($top, 'private function exactRows('),
            'Browser path does not backfill hourly tables' =>
                ! str_contains($analytic, 'refreshDate(')
                && ! str_contains($top, 'refreshDate('),
            'Controller still keeps 409 only as safety guard' =>
                str_contains($controller, 'REPORT_HOURLY_SUMMARY_NOT_READY'),
        ];

        $failed = [];
        foreach ($checks as $label => $ok) {
            if ($ok) {
                $this->components->info('PASS — '.$label);
            } else {
                $this->components->error('FAIL — '.$label);
                $failed[] = $label;
            }
        }

        if ($failed !== []) {
            $this->error('HF04 Hourly Historical Live Fallback check FAILED.');
            return self::FAILURE;
        }

        $this->info('HF04 Hourly Historical Live Fallback READY.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
