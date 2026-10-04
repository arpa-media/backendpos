<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceSummaryXlsxExportService;
use Illuminate\Console\Command;
use Throwable;
use ZipArchive;

final class ErpPosV10I26SalesCollectedNativeTimeHardeningCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i26-sales-collected-native-time-check';
    protected $description = 'Verify V10 I26 Sales Collected XLSX explicit native Excel time contract.';

    public function handle(FinanceSummaryXlsxExportService $service): int
    {
        $checks = [];

        $exportHelperPath = base_path('../frontend - Backoffice/src/lib/export.js');
        $salesPagePath = base_path('../frontend - Backoffice/src/pages/SalesCollectedPage.vue');
        $controllerPath = app_path('Http/Controllers/Api/V1/Finance/FinanceSummaryXlsxExportController.php');
        $servicePath = app_path('Services/Finance/FinanceSummaryXlsxExportService.php');
        $writerPath = app_path('Services/Finance/FinanceOpenXmlXlsxWriter.php');

        $exportHelper = is_file($exportHelperPath) ? (string) file_get_contents($exportHelperPath) : '';
        $salesPage = is_file($salesPagePath) ? (string) file_get_contents($salesPagePath) : '';
        $controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
        $serviceSource = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
        $writer = is_file($writerPath) ? (string) file_get_contents($writerPath) : '';

        $checks['Sales Collected explicitly marks zero-based Time column D'] = str_contains($salesPage, 'nativeTimeColumns: [3]');
        $checks['downloadXlsx forwards native_time_columns'] = str_contains($exportHelper, 'native_time_columns:')
            && str_contains($exportHelper, 'options.nativeTimeColumns');
        $checks['Export controller validates explicit native-time columns'] = str_contains($controller, "'native_time_columns'")
            && str_contains($controller, "'native_time_columns.*'");
        $checks['Export service applies explicit time type before semantic inference'] = str_contains($serviceSource, 'nativeTimeColumns($payload)')
            && str_contains($serviceSource, '$explicitTypes[$column] ?? $this->semanticColumnType($heading)');
        $checks['Writer still emits native hh:mm:ss style'] = str_contains($writer, 'STYLE_TIME = 13')
            && str_contains($writer, 'numFmtId="165" formatCode="hh:mm:ss"')
            && str_contains($writer, 'excelTimeSerial');

        $path = '';
        try {
            // Deliberately use a non-semantic header "Clock". The Time cell must
            // still become native Excel time because I26 supplies column index 3.
            $sample = $service->build([
                'filename' => 'erp_pos_v10_i26_sales_collected_time_smoke.xlsx',
                'sheet_name' => 'I26 Time Smoke',
                'native_time_columns' => [3],
                'rows' => [
                    ['ERP POS V10 I26 Sales Collected Native Time'],
                    [],
                    ['Sale Number', 'Outlet', 'Date', 'Clock', 'Amount'],
                    ['00123456', 'DPN', '2026-10-03', '08:30:15', 12345.67],
                ],
            ], 'ERP POS V10 I26 Check');

            $path = (string) ($sample['path'] ?? '');
            $checks['XLSX smoke binary generated'] = is_file($path)
                && filesize($path) > 1000
                && file_get_contents($path, false, null, 0, 2) === 'PK';

            if ($checks['XLSX smoke binary generated'] && class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                $opened = $zip->open($path) === true;
                $sheet = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
                $styles = $opened ? (string) $zip->getFromName('xl/styles.xml') : '';
                if ($opened) $zip->close();

                $checks['Clock cell is numeric Excel time despite non-Time header'] = str_contains($sheet, '<c r="D4" s="13"><v>0.3543402778</v></c>');
                $checks['Sale Number with leading zero remains text'] = str_contains($sheet, '<c r="A4" s="6" t="inlineStr">')
                    && str_contains($sheet, '00123456');
                $checks['Time style remains hh:mm:ss'] = str_contains($styles, 'numFmtId="165" formatCode="hh:mm:ss"');
            } else {
                $checks['Clock cell is numeric Excel time despite non-Time header'] = $checks['Export service applies explicit time type before semantic inference'];
                $checks['Sale Number with leading zero remains text'] = str_contains($serviceSource, "preg_match('/^0\\d+$/");
                $checks['Time style remains hh:mm:ss'] = $checks['Writer still emits native hh:mm:ss style'];
            }
        } catch (Throwable $e) {
            foreach ([
                'XLSX smoke binary generated',
                'Clock cell is numeric Excel time despite non-Time header',
                'Sale Number with leading zero remains text',
                'Time style remains hh:mm:ss',
            ] as $label) {
                $checks[$label] = false;
            }
            $this->warn('I26 XLSX smoke failed: '.$e->getMessage());
        } finally {
            if ($path !== '') @unlink($path);
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('ERP POS V10 I26 Sales Collected Native Time is NOT READY.');
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I26 Sales Collected Native Time is READY.');
        return self::SUCCESS;
    }
}
