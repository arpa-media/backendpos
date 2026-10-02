<?php

use App\Http\Controllers\Api\V1\Support\I18\I18SpreadsheetImportController;
use Illuminate\Support\Facades\Route;

Route::post('api/v1/cogs/uom-conversions/import-core', I18SpreadsheetImportController::class)
    ->defaults('spreadsheet_module', 'cogs.uom_conversion')
    ->middleware(['api','auth:sanctum','permission_or_snapshot:cogs.uom_conversion.create','permission_or_snapshot:cogs.uom_conversion.update'])
    ->name('cogs.uom-conversions.import-core.i18');
