<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Illuminate\Console\Command;

class ErpFinanceHf02CanonicalBusinessDateCheckCommand extends Command
{
    protected $signature = 'erp-finance:hf02-canonical-business-date-check';

    protected $description = 'Verify HF02 canonical Cashier-aligned business-date contract and dirty-date fallback wiring.';

    public function handle(): int
    {
        $window = TransactionDate::businessDateWindow('2026-09-25', '2026-09-25', 'Asia/Makassar');

        $checks = [
            'Makassar cutoff = 01:00' => TransactionDate::businessDayStartHour('Asia/Makassar') === 1,
            'Jakarta cutoff = 00:00' => TransactionDate::businessDayStartHour('Asia/Jakarta') === 0,
            'Makassar window starts 25-Sep 01:00' => $window['from_local']->format('Y-m-d H:i:s') === '2026-09-25 01:00:00',
            'Makassar window ends exclusive 26-Sep 01:00' => $window['to_exclusive_local']->format('Y-m-d H:i:s') === '2026-09-26 01:00:00',
            '00:59:59 WITA belongs to previous business date' => TransactionDate::businessDateForSale('2026-09-26T00:59:59+08:00', 'Asia/Makassar') === '2026-09-25',
            '01:00:00 WITA starts next business date' => TransactionDate::businessDateForSale('2026-09-26T01:00:00+08:00', 'Asia/Makassar') === '2026-09-26',
            'Standard sale token parsed' => TransactionDate::saleNumberDateToken('S.KTA-20260925-RD4K-001') === '20260925',
            'Shifted sale token parsed' => TransactionDate::saleNumberDateToken('POS-S.KTA-20260925-RD4K-001') === '20260925',
        ];

        $transactionDate = $this->read(base_path('app/Support/TransactionDate.php'));
        $reportService = $this->read(base_path('app/Services/ReportService.php'));
        $hotWindow = $this->read(base_path('app/Services/Reporting/ReportHotWindowReadService.php'));
        $salesCollected = $this->read(base_path('app/Http/Controllers/Api/V1/Finance/SalesCollectedController.php'));
        $daily = $this->read(base_path('app/Services/ReportDailySummaryService.php'));
        $index = $this->read(base_path('app/Services/ReportSaleBusinessDateIndexService.php'));

        $checks += [
            'SQL sale-token parser is position independent' => str_contains($transactionDate, 'for ($position = 2; $position <= 16; $position++)'),
            'Cashier Report delegates window to TransactionDate' => str_contains($reportService, 'return TransactionDate::businessDateWindow('),
            'Daily exposes coverage anomaly contract' => str_contains($daily, 'public function coverageAnomalies('),
            'Canonical index invalidates pending refresh coverage' => str_contains($index, "report_daily_summary_refresh_queue"),
            'Hot Window has dirty-date exact fallback' => str_contains($hotWindow, 'MAX_CANONICAL_RECOVERY_DAYS')
                && str_contains($hotWindow, 'canonical_dirty_date_live_fallback')
                && str_contains($hotWindow, 'public function saleScopeQuery('),
            'Sales Collected consumes canonical saleScopeQuery' => str_contains($salesCollected, '->saleScopeQuery(')
                && ! str_contains($salesCollected, "DB::table('report_sale_business_dates as rsbd')"),
        ];

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
            $this->error('HF02 Canonical Business Date Contract check FAILED.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('HF02 Canonical Business Date Contract Hardening is READY.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
