<?php

namespace App\Console\Commands;

use App\Support\AnalyticsResponseCache;
use App\Support\TransactionDate;
use Illuminate\Console\Command;

class ErpFinanceHf03CashierBackendCheckCommand extends Command
{
    protected $signature = 'erp-finance:hf03-cashier-backend-check';
    protected $description = 'Verify HF03 Cashier backend acceleration and HF02 defect fixes.';

    public function handle(): int
    {
        $recoveryTtl = AnalyticsResponseCache::reportingTtlSeconds(['read_mode' => 'hybrid_recovery']);

        $files = [
            'index' => $this->read(app_path('Services/ReportSaleBusinessDateIndexService.php')),
            'scope' => $this->read(app_path('Services/CashierAlignedSaleScopeService.php')),
            'refresh' => $this->read(app_path('Services/ReportDailySummaryRefreshService.php')),
            'controller' => $this->read(app_path('Http/Controllers/Api/V1/ReportController.php')),
            'hot' => $this->read(app_path('Services/Reporting/ReportHotWindowReadService.php')),
        ];

        $checks = [
            'Makassar cutoff remains 01:00' => TransactionDate::businessDayStartHour('Asia/Makassar') === 1,
            'HF02 recovery cache TTL is short' => $recoveryTtl === AnalyticsResponseCache::HOT_WINDOW_TTL_SECONDS,
            'HF02 contractReady assigned before use' =>
                strpos($files['hot'], '$contractReady =') !== false
                && strpos($files['hot'], '$readyRows = $contractReady') !== false
                && strpos($files['hot'], '$contractReady =') < strpos($files['hot'], '$readyRows = $contractReady'),
            'Single-sale canonical upsert exists' => str_contains($files['index'], 'public function upsertSaleIndex('),
            'Cashier-readable coverage ignores Daily queue freshness' => str_contains($files['index'], 'saleIdsCashierReadableSubquery'),
            'HF02 premature coverage check is timezone-aware' => str_contains($files['index'], 'coverageWasPremature')
                && str_contains($files['index'], 'setTimezone(TransactionDate::normalizeTimezone'),
            'Cashier scope consumes Cashier-readable index' => str_contains($files['scope'], 'saleIdsCashierReadableSubquery'),
            'Checkout/mutation bumps Cashier cache version' => str_contains($files['refresh'], 'CashierReportCacheVersion::bump'),
            'Cashier endpoints use runtime coalescing cache' => str_contains($files['controller'], 'CashierReportRuntimeCache'),
            'Android HTTP response remains no-store' => str_contains($files['controller'], "'Cache-Control' => 'no-store, no-cache"),
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
            $this->error('HF03 check FAILED.');
            return self::FAILURE;
        }

        $this->info('HF03 Cashier backend acceleration + HF02 fixes READY.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
