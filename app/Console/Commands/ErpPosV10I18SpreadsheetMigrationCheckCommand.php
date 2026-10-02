<?php

namespace App\Console\Commands;

use App\Services\Spreadsheet\I18\I18SpreadsheetImportRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpPosV10I18SpreadsheetMigrationCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i18-spreadsheet-migration-check';
    protected $description = 'Verify ERP POS V10 I18 spreadsheet migration contracts.';

    public function handle(I18SpreadsheetImportRegistry $registry): int
    {
        $ok = true;
        foreach (['spreadsheet_transfer_batches','spreadsheet_transfer_rows'] as $table) {
            $exists = Schema::hasTable($table);
            $this->line(($exists ? '<info>PASS</info>' : '<error>FAIL</error>')." table {$table}");
            $ok = $ok && $exists;
        }

        $modules = ['ga.bill_due_date','ga.asset','ga.inventory','cogs.uom_conversion','warehouse.stock_price','warehouse.sku_uom','warehouse.par_stock'];
        foreach ($modules as $module) {
            try { $adapter = $registry->get($module); $pass = $adapter->moduleKey() === $module; }
            catch (Throwable $e) { $pass = false; }
            $this->line(($pass ? '<info>PASS</info>' : '<error>FAIL</error>')." adapter {$module}");
            $ok = $ok && $pass;
        }

        $routes = [
            'cogs.uom-conversions.import-core.i18',
            'ga.bill-due-date.import-core.i18','ga.assets.import-core.i18','ga.inventory.import-core.i18',
            'warehouse.stock-v3.prices.import-core.i18','warehouse.inventory.uom-bulk.import-core.i18','warehouse.par-stocks.import-core.i18',
        ];
        foreach ($routes as $name) {
            $pass = Route::getRoutes()->getByName($name) !== null;
            $this->line(($pass ? '<info>PASS</info>' : '<error>FAIL</error>')." route {$name}");
            $ok = $ok && $pass;
        }

        if (! $ok) {
            $this->error('ERP POS V10 I18 Spreadsheet Migration is NOT READY. Check failures above.');
            return self::FAILURE;
        }
        $this->newLine();
        $this->info('ERP POS V10 I18 Spreadsheet Migration is READY.');
        $this->line('Migrated master/upsert flows: 8 (Stock SKU from I17 + 7 adapters in I18).');
        return self::SUCCESS;
    }
}
