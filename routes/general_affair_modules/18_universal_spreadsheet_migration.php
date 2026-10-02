<?php

use App\Http\Controllers\Api\V1\Support\I18\I18SpreadsheetImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api','auth:sanctum'])->prefix('api/v1/general-affair')->group(function (): void {
    Route::post('/bill-due-date/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'ga.bill_due_date')
        ->middleware(['permission_or_snapshot:ga.bill_due_date.import,ga.bill_due_date.create','permission_or_snapshot:ga.bill_due_date.import,ga.bill_due_date.update'])
        ->name('ga.bill-due-date.import-core.i18');
    Route::post('/assets/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'ga.asset')
        ->middleware(['permission_or_snapshot:ga.asset.import,ga.asset.create','permission_or_snapshot:ga.asset.import,ga.asset.update'])
        ->name('ga.assets.import-core.i18');
    Route::post('/inventory/import-core', I18SpreadsheetImportController::class)
        ->defaults('spreadsheet_module', 'ga.inventory')
        ->middleware(['permission_or_snapshot:ga.inventory.import,ga.inventory.create','permission_or_snapshot:ga.inventory.import,ga.inventory.update'])
        ->name('ga.inventory.import-core.i18');
});
