<?php

namespace App\Console\Commands;

use App\Services\Spreadsheet\SpreadsheetImportResult;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use App\Services\Spreadsheet\SpreadsheetChunkImportService;
use App\Services\Spreadsheet\UniversalSpreadsheetXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

final class ErpPosV10I17SpreadsheetCoreCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i17-spreadsheet-core-check';
    protected $description = 'Verify ERP POS V10 I17 Universal Spreadsheet Import/Export Core';

    public function handle(UniversalSpreadsheetXlsxService $xlsx, SpreadsheetChunkImportService $chunks): int
    {
        $checks = [
            'table spreadsheet_transfer_batches' => Schema::hasTable('spreadsheet_transfer_batches'),
            'table spreadsheet_transfer_rows' => Schema::hasTable('spreadsheet_transfer_rows'),
            'batch source_rows' => Schema::hasColumn('spreadsheet_transfer_batches', 'source_rows'),
            'batch cancel_requested_at' => Schema::hasColumn('spreadsheet_transfer_batches', 'cancel_requested_at'),
            'batch audit purge marker' => Schema::hasColumn('spreadsheet_transfer_batches', 'files_purged_at'),
            'row fingerprint' => Schema::hasColumn('spreadsheet_transfer_rows', 'fingerprint'),
            'exactly-once row processor' => method_exists(SpreadsheetTransferBatchService::class, 'processRowOnce'),
            'route store' => Route::has('spreadsheet-transfers.batches.store'),
            'route cancel' => Route::has('spreadsheet-transfers.batches.cancel'),
            'reference Stock SKU route' => Route::has('stock-inventory.skus.import-core'),
            'reference reusable modal' => is_file(base_path('../frontend - Backoffice/src/components/shared/SpreadsheetImportModal.vue')),
            'reference Stock SKU page' => is_file(base_path('../frontend - Backoffice/src/pages/stock-inventory/StockSkusPage.vue')),
        ];

        $result = SpreadsheetImportResult::empty()
            ->addRow('INSERTED', 2, 'A001')
            ->addRow('UPDATED', 3, 'A002')
            ->addRow('UNCHANGED', 4, 'A003')
            ->addRow('RESTORED', 5, 'A004')
            ->addRow('ERROR', 6, 'A005', [], [['column' => 'Kode', 'message' => 'Contoh error']]);
        $payload = $result->toArray();
        $checks['result contract'] = $payload['inserted'] === 1 && $payload['updated'] === 1 && $payload['unchanged'] === 1 && $payload['restored'] === 1 && $payload['error_count'] === 1;
        try {
            $header = $chunks->resolveHeader(['NISJ', 'Nama Lengkap', 'Outlet'], [
                'nisj' => ['NISJ'], 'full_name' => ['Nama Lengkap', 'Full Name'], 'outlet' => ['Outlet'],
            ], ['nisj', 'full_name']);
            $checks['header alias resolver'] = ($header['map']['nisj'] ?? null) === 0 && ($header['map']['full_name'] ?? null) === 1;
        } catch (\Throwable) { $checks['header alias resolver'] = false; }

        $tmp = storage_path('app/private/spreadsheet-transfers/i17-check-'.getmypid().'.xlsx');
        try {
            $xlsx->write($tmp, 'I17 Check', [
                ['label' => 'ID', 'type' => 'text'], ['label' => 'Amount', 'type' => 'currency'],
                ['label' => 'Date', 'type' => 'date'], ['label' => 'Date Time', 'type' => 'datetime'], ['label' => 'Time', 'type' => 'time'],
            ], [['001234', 12500.5, '2026-09-30', '2026-09-30 20:15:30', '08:30:15']]);
            if (class_exists(ZipArchive::class)) {
                $zip = new ZipArchive(); $opened = $zip->open($tmp) === true;
                $styles = $opened ? (string) $zip->getFromName('xl/styles.xml') : '';
                $sheet = $opened ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
                if ($opened) $zip->close();
                $checks['typed XLSX'] = $opened && str_contains($styles, 'hh:mm:ss') && str_contains($styles, 'yyyy-mm-dd') && str_contains($sheet, '001234') && str_contains($sheet, '0.3543402778');
            } else {
                $binary = (string) file_get_contents($tmp);
                $checks['typed XLSX'] = str_contains($binary, 'hh:mm:ss') && str_contains($binary, 'yyyy-mm-dd') && str_contains($binary, '001234') && str_contains($binary, '0.3543402778');
            }
        } catch (\Throwable $e) {
            $checks['typed XLSX'] = false;
            $this->warn('XLSX smoke: '.$e->getMessage());
        } finally { @unlink($tmp); }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-38s %s', $label, $ok ? '<info>PASS</info>' : '<error>FAIL</error>'));
            if (! $ok) $failed = true;
        }
        if ($failed) { $this->error('ERP POS V10 I17 Universal Spreadsheet Core is NOT READY.'); return self::FAILURE; }
        $this->info('ERP POS V10 I17 Universal Spreadsheet Core is READY.');
        return self::SUCCESS;
    }
}
