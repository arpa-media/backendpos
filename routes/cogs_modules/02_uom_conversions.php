<?php

use App\Http\Controllers\Api\V1\Cogs\UomConversionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/uom-conversions')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/template', [UomConversionController::class, 'template'])->middleware('permission_or_snapshot:cogs.uom_conversion.view')->name('cogs.uom-conversions.template');
        Route::get('/export', [UomConversionController::class, 'export'])->middleware('permission_or_snapshot:cogs.uom_conversion.view')->name('cogs.uom-conversions.export');
        Route::post('/import', [UomConversionController::class, 'import'])->middleware('permission_or_snapshot:cogs.uom_conversion.create,cogs.uom_conversion.update')->name('cogs.uom-conversions.import');
        Route::get('/catalogs', [UomConversionController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.view,cogs.uom_conversion.create,cogs.uom_conversion.update')
            ->name('cogs.uom-conversions.catalogs');
        Route::post('/preview', [UomConversionController::class, 'preview'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.view')
            ->name('cogs.uom-conversions.preview');
        Route::get('/', [UomConversionController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.view')
            ->name('cogs.uom-conversions.index');
        Route::post('/', [UomConversionController::class, 'store'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.create')
            ->name('cogs.uom-conversions.store');
        Route::put('/{id}', [UomConversionController::class, 'update'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.update')
            ->name('cogs.uom-conversions.update');
        Route::delete('/{id}', [UomConversionController::class, 'destroy'])
            ->middleware('permission_or_snapshot:cogs.uom_conversion.delete')
            ->name('cogs.uom-conversions.destroy');
    });
