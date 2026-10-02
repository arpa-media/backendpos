<?php

use App\Http\Controllers\Api\V1\Support\I18\I18SpreadsheetImportController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function (): void {
    Route::post('api/v1/warehouse/stock-v3/prices/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'warehouse.stock_price')
        ->middleware(['permission_or_snapshot:warehouse.inventory.price.create','permission_or_snapshot:warehouse.inventory.price.update'])
        ->name('warehouse.stock-v3.prices.import-core.i18');

    Route::post('api/v1/warehouse/inventory/uom-bulk/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'warehouse.sku_uom')
        ->middleware('permission_or_snapshot:warehouse.inventory.item.update')
        ->name('warehouse.inventory.uom-bulk.import-core.i18');

    Route::post('api/v1/warehouse/par-stocks/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'warehouse.par_stock')
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.update')
        ->name('warehouse.par-stocks.import-core.i18');
});
