<?php

use App\Http\Controllers\Api\V1\Cogs\CogsCalculationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/calculation')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/overview', [CogsCalculationController::class, 'overview'])
            ->middleware('permission_or_snapshot:cogs.calculation.view')
            ->name('cogs.calculation.overview');
        Route::post('/calculate', [CogsCalculationController::class, 'calculate'])
            ->middleware('permission_or_snapshot:cogs.calculation.update')
            ->name('cogs.calculation.calculate');
        Route::post('/{id}/reconcile', [CogsCalculationController::class, 'reconcile'])
            ->middleware('permission_or_snapshot:cogs.calculation.update')
            ->name('cogs.calculation.reconcile');
        Route::post('/{id}/close', [CogsCalculationController::class, 'close'])
            ->middleware('permission_or_snapshot:cogs.calculation.update')
            ->name('cogs.calculation.close');
        Route::post('/{id}/cancel', [CogsCalculationController::class, 'cancel'])
            ->middleware('permission_or_snapshot:cogs.calculation.delete')
            ->name('cogs.calculation.cancel');
        Route::get('/export', [CogsCalculationController::class, 'export'])
            ->middleware('permission_or_snapshot:cogs.calculation.view')
            ->name('cogs.calculation.export');
        Route::get('/', [CogsCalculationController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.calculation.view')
            ->name('cogs.calculation.index');
        Route::get('/{id}', [CogsCalculationController::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.calculation.view')
            ->name('cogs.calculation.show');
    });
