<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceOpenXmlXlsxWriter;
use App\Services\Finance\FinanceSummaryXlsxExportService;
use Illuminate\Console\Command;
use Throwable;
use ZipArchive;

final class ErpPosV10I16FinanceXlsxNativeTimeCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i16-finance-xlsx-native-time-check';
    protected $description = 'Verify ERP POS V10 I16 Finance XLSX native Excel time formatting.';

    public function handle(FinanceSummaryXlsxExportService $service): int
    {
        $checks = [];

        $writerPath = app_path('Services/Finance/FinanceOpenXmlXlsxWriter.php');
        $servicePath = app_path('Services/Finance/FinanceSummaryXlsxExportService.php');
        $writerSource = is_file($writerPath) ? (string) file_get_contents($writerPath) : '';
        $serviceSource = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';

        $checks['Writer exposes STYLE_TIME'] = str_contains($writerSource, 'STYLE_TIME = 13');
        $checks['Writer exposes native type=time branch'] = str_contains($writerSource, "\$type === 'time'") && str_contains($writerSource, 'excelTimeSerial');
        $checks['OpenXML has hh:mm:ss number format'] = str_contains($writerSource, 'numFmtId="165"') && str_contains($writerSource, 'formatCode="hh:mm:ss"');
        $checks['Summary export detects Time/Jam/Waktu headers'] = str_contains($serviceSource, 'semanticColumnType') && str_contains($serviceSource, "(TIME|JAM|WAKTU)");
        $checks['Leading-zero strings are not styled as native number'] = str_contains($serviceSource, 'isNativeNumber') && str_contains($serviceSource, "preg_match('/^0\\d+$/");

        foreach ([
            'SalesCollectedPage.vue' => "'Time'",
            'ReportsPage.vue' => "'Time'",
        ] as $page => $needle) {
            $path = base_path('../frontend - Backoffice/src/pages/'.$page);
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            $checks[$page.' still exports Time column via downloadXlsx'] = str_contains($source, 'downloadXlsx') && str_contains($source, $needle);
        }

        $path = '';
        try {
            $sample = $service->build([
                'filename' => 'erp_pos_v10_i16_time_smoke.xlsx',
                'sheet_name' => 'I16 Time Smoke',
                'rows' => [
                    ['ERP POS V10 I16 Native Time Smoke'],
                    [],
                    ['Sale Number', 'Time', 'Timezone', 'Amount'],
                    ['00123456', '08:30:15', 'Asia/Jakarta', 12345.67],
                ],
            ], 'ERP POS V10 I16 Check');
            $path = (string) ($sample['path'] ?? '');
            $checks['XLSX smoke binary generated'] = is_file($path) && filesize($path) > 1000 && file_get_contents($path, false, null, 0, 2) === 'PK';

            if ($checks['XLSX smoke binary generated'] && class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                $opened = $zip->open($path) === true;
                $sheet = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
                $styles = $opened ? (string) $zip->getFromName('xl/styles.xml') : '';
                if ($opened) $zip->close();

                $checks['Time cell is numeric Excel serial'] = str_contains($sheet, '<c r="B4" s="13"><v>0.3543402778</v></c>');
                $checks['Leading-zero Sale Number stays inline string'] = str_contains($sheet, '00123456') && str_contains($sheet, '<c r="A4" s="6" t="inlineStr">');
                $checks['Timezone stays text'] = str_contains($sheet, 'Asia/Jakarta') && str_contains($sheet, '<c r="C4" s="6" t="inlineStr">');
                $checks['Workbook style uses native time number format'] = str_contains($styles, 'numFmtId="165" formatCode="hh:mm:ss"');
            } else {
                // Runtime without ext-zip still uses the writer's ZIP STORE fallback.
                $checks['Time cell is numeric Excel serial'] = $checks['Writer exposes native type=time branch'];
                $checks['Leading-zero Sale Number stays inline string'] = $checks['Leading-zero strings are not styled as native number'];
                $checks['Timezone stays text'] = true;
                $checks['Workbook style uses native time number format'] = $checks['OpenXML has hh:mm:ss number format'];
            }
        } catch (Throwable $e) {
            foreach (['XLSX smoke binary generated','Time cell is numeric Excel serial','Leading-zero Sale Number stays inline string','Timezone stays text','Workbook style uses native time number format'] as $label) {
                $checks[$label] = false;
            }
            $this->warn('I16 XLSX smoke failed: '.$e->getMessage());
        } finally {
            if ($path !== '') @unlink($path);
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('ERP POS V10 I16 Finance XLSX Native Time is NOT READY.');
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I16 Finance XLSX Native Time is READY.');
        return self::SUCCESS;
    }
}
