<?php

use App\Http\Controllers\Api\V1\Cogs\HistoryStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/history-stock')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/catalogs', [HistoryStockController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.catalogs');
        Route::get('/traceability', [HistoryStockController::class, 'traceability'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.traceability');
        Route::get('/movements', [HistoryStockController::class, 'movements'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.movements');
        Route::get('/average-cost-timeline', [HistoryStockController::class, 'timeline'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.average-cost-timeline');
        Route::get('/export', [HistoryStockController::class, 'export'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.export');
        Route::get('/', [HistoryStockController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.index');
        Route::get('/{receiptId}', [HistoryStockController::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.history_stock.view')
            ->name('cogs.history-stock.show');
    });
