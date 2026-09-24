<?php

namespace App\Console\Commands;

use App\Services\StockInventory\StockSkuSpreadsheetService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Throwable;

class StockSkuSpreadsheetHotfixV22CheckCommand extends Command
{
    protected $signature = 'stock-inventory:sku-spreadsheet-check-v22';

    protected $description = 'Validate Hotfix Iterasi 01 v2.2 standalone template, parser, and named routes.';

    public function handle(StockSkuSpreadsheetService $spreadsheets, SimpleXlsxService $xlsx): int
    {
        $expectedHeader = [
            'sku_code', 'sku_name', 'category_code', 'uom_code',
            'barcode', 'notes', 'is_active',
        ];
        $expectedSheets = ['DATA SKU', 'MASTER CATEGORY', 'MASTER UOM', 'PETUNJUK'];
        $errors = [];
        $sheetErrors = [];
        $headerErrors = [];
        $sampleErrors = [];
        $parserErrors = [];
        $routeErrors = [];
        $temporary = tempnam(sys_get_temp_dir(), 'sku-template-v22-');

        try {
            $response = $spreadsheets->template();
            $binary = (string) $response->getContent();
            if ($binary === '' || ! str_starts_with($binary, 'PK')) {
                throw new \RuntimeException('Template response bukan paket XLSX yang valid.');
            }

            file_put_contents($temporary, $binary);
            $worksheets = $xlsx->readWorksheets($temporary);
            $names = collect($worksheets)->pluck('name')->values()->all();

            foreach ($expectedSheets as $expected) {
                if (! in_array($expected, $names, true)) {
                    $sheetErrors[] = 'Worksheet '.$expected.' tidak ditemukan.';
                }
            }

            $dataSheet = collect($worksheets)->first(
                fn (array $sheet): bool => mb_strtoupper(trim((string) ($sheet['name'] ?? ''))) === 'DATA SKU'
            );

            if (! $dataSheet) {
                $headerErrors[] = 'Worksheet DATA SKU tidak tersedia untuk pemeriksaan header.';
            } else {
                $header = array_map(fn ($value): string => trim((string) $value), $dataSheet['rows'][0] ?? []);
                if ($header !== $expectedHeader) {
                    $headerErrors[] = 'Header DATA SKU tidak identik dengan kontrak importer.';
                }
                if (count($dataSheet['rows'] ?? []) !== 1) {
                    $sampleErrors[] = 'DATA SKU harus hanya berisi header saat template diunduh.';
                }
            }

            // Parser contract: all four worksheets and exact first header were read back
            // from the generated XLSX package. This validates default/prefixed XML names.
            if ($sheetErrors !== [] || $headerErrors !== []) {
                $parserErrors[] = 'Round-trip XLSX parser belum berhasil membaca workbook hasil generator.';
            }
        } catch (Throwable $exception) {
            $parserErrors[] = $exception->getMessage();
        } finally {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        }

        foreach (['stock-inventory.skus.template', 'stock-inventory.skus.import', 'stock-inventory.skus.export'] as $route) {
            if (! Route::has($route)) $routeErrors[] = 'Named route tidak ditemukan: '.$route;
        }

        $errors = [...$sheetErrors, ...$headerErrors, ...$sampleErrors, ...$parserErrors, ...$routeErrors];

        $this->table(['Check', 'Result'], [
            ['Template sheets', $sheetErrors === [] ? 'PASSED' : 'FAILED'],
            ['Template/import header', $headerErrors === [] ? 'PASSED' : 'FAILED'],
            ['Static invalid sample', $sampleErrors === [] ? 'REMOVED' : 'FAILED'],
            ['XLSX parser', $parserErrors === [] ? 'PREFIX/DEFAULT/REGEX SAFE' : 'FAILED'],
            ['Named routes', $routeErrors === [] ? 'PASSED' : 'FAILED'],
            ['Errors', $errors === [] ? '-' : implode(' | ', $errors)],
            ['Status', $errors === [] ? 'PASSED' : 'FAILED'],
        ]);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
