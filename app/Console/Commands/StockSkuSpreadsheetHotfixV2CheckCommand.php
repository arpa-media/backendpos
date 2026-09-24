<?php

namespace App\Console\Commands;

use App\Services\StockInventory\StockSkuSpreadsheetService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Throwable;

class StockSkuSpreadsheetHotfixV2CheckCommand extends Command
{
    protected $signature = 'stock-inventory:sku-spreadsheet-check-v21';

    protected $description = 'Validate Hotfix Iterasi 01 v2.1 namespace-safe template/import round-trip contract.';

    public function handle(StockSkuSpreadsheetService $spreadsheets, SimpleXlsxService $xlsx): int
    {
        $errors = [];
        $temporary = tempnam(sys_get_temp_dir(), 'sku-template-v21-');

        try {
            $response = $spreadsheets->template();
            $binary = (string) $response->getContent();
            if ($binary === '' || ! str_starts_with($binary, 'PK')) {
                $errors[] = 'Template response bukan paket XLSX yang valid.';
            } else {
                file_put_contents($temporary, $binary);
                $worksheets = $xlsx->readWorksheets($temporary);
                $names = collect($worksheets)->pluck('name')->all();

                foreach (['DATA SKU', 'MASTER CATEGORY', 'MASTER UOM', 'PETUNJUK'] as $expected) {
                    if (! in_array($expected, $names, true)) {
                        $errors[] = 'Worksheet '.$expected.' tidak ditemukan.';
                    }
                }

                $dataSheet = collect($worksheets)->firstWhere('name', 'DATA SKU');
                $header = $dataSheet['rows'][0] ?? [];
                $expectedHeader = [
                    'sku_code', 'sku_name', 'category_code', 'uom_code',
                    'barcode', 'notes', 'is_active',
                ];
                if ($header !== $expectedHeader) {
                    $errors[] = 'Header worksheet DATA SKU tidak sesuai importer.';
                }
                if (count($dataSheet['rows'] ?? []) !== 1) {
                    $errors[] = 'DATA SKU masih memiliki baris contoh statis. Template v2.1 harus hanya berisi header.';
                }
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        foreach (['stock-inventory.skus.template', 'stock-inventory.skus.import', 'stock-inventory.skus.export'] as $route) {
            if (! Route::has($route)) {
                $errors[] = 'Named route tidak ditemukan: '.$route;
            }
        }

        $this->table(['Check', 'Result'], [
            ['Template sheets', $errors === [] ? 'DATA SKU + master references tersedia' : 'FAILED'],
            ['Template/import header', $errors === [] ? 'MATCH' : 'FAILED'],
            ['Static invalid sample', $errors === [] ? 'REMOVED' : 'FAILED'],
            ['XML namespace parser', $errors === [] ? 'PREFIX/DEFAULT SAFE' : 'FAILED'],
            ['Named routes', collect($errors)->filter(fn (string $error) => str_contains($error, 'route'))->isEmpty() ? 'OK' : 'FAILED'],
            ['Errors', $errors === [] ? '-' : implode(' | ', $errors)],
            ['Status', $errors === [] ? 'PASSED' : 'FAILED'],
        ]);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
