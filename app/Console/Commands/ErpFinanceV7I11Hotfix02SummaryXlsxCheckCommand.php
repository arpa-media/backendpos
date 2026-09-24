<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceSummaryXlsxExportService;
use Illuminate\Console\Command;

final class ErpFinanceV7I11Hotfix02SummaryXlsxCheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i11-hotfix02-check';
    protected $description = 'Read-only/source smoke check untuk Finance Summary XLSX export.';

    public function handle(FinanceSummaryXlsxExportService $service): int
    {
        $checks = [];
        $checks['XLSX export service tersedia'] = class_exists(FinanceSummaryXlsxExportService::class);
        $checks['Route module tersedia'] = is_file(base_path('routes/finance_modules/22_summary_xlsx_export.php'));

        foreach ([
            'OverviewFinancePage.vue',
            'SalesCollectedPage.vue',
            'SalesSummaryPage.vue',
            'CategorySummaryPage.vue',
            'ItemSummaryPage.vue',
            'ReportsPage.vue',
        ] as $page) {
            $path = base_path('../frontend - Backoffice/src/pages/'.$page);
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            $checks[$page.' memakai XLSX'] = str_contains($source, 'downloadXlsx') && ! str_contains($source, 'Download CSV');
        }

        try {
            $sample = $service->build([
                'filename' => 'finance_summary_smoke.xlsx',
                'sheet_name' => 'Smoke',
                'rows' => [
                    ['Finance Summary Smoke'],
                    ['Period', '2026-09-01 s.d. 2026-09-30'],
                    [],
                    ['Metric', 'Amount'],
                    ['Gross Sales', 123456.78],
                ],
            ], 'I11 Hotfix02 Check');
            $path = (string) ($sample['path'] ?? '');
            $checks['XLSX binary dapat dibuat'] = is_file($path) && filesize($path) > 1000 && file_get_contents($path, false, null, 0, 2) === 'PK';
            if ($path !== '') @unlink($path);
        } catch (\Throwable $e) {
            $checks['XLSX binary dapat dibuat'] = false;
            $this->warn('Smoke XLSX: '.$e->getMessage());
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            $failed = $failed || ! $ok;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
