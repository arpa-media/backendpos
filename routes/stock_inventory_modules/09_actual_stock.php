<?php

use App\Http\Controllers\Api\V1\StockInventory\ActualStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory/actual-stock')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/', [ActualStockController::class, 'index'])
            ->middleware('permission_or_snapshot:stock_inventory.actual_stock.view')
            ->name('stock-inventory.actual-stock.index');

        Route::get('/{skuId}/history', [ActualStockController::class, 'history'])
            ->whereUlid('skuId')
            ->middleware('permission_or_snapshot:stock_inventory.actual_stock.view')
            ->name('stock-inventory.actual-stock.history');
    });
