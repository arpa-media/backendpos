<?php

use App\Http\Controllers\Api\V1\Cogs\StockVarianceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/stock-variance')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/overview', [StockVarianceController::class, 'overview'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.overview');
        Route::get('/candidates', [StockVarianceController::class, 'candidates'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.candidates');
        Route::post('/calculate', [StockVarianceController::class, 'calculate'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.update')
            ->name('cogs.stock-variance.calculate');
        Route::post('/{id}/submit', [StockVarianceController::class, 'submit'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.update')
            ->name('cogs.stock-variance.submit');
        Route::get('/export', [StockVarianceController::class, 'export'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.export');
        Route::get('/', [StockVarianceController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.index');
        Route::get('/{id}', [StockVarianceController::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.show');
    });
