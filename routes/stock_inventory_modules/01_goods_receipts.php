<?php

use App\Http\Controllers\Api\V1\StockInventory\ManualStockController;
use App\Http\Controllers\Api\V1\StockInventory\ReceiveStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function () {
        Route::prefix('receive-stock')
            ->name('stock-inventory.receive-stock.')
            ->group(function () {
                Route::get('/catalogs', [ReceiveStockController::class, 'catalogs'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.view,stock_inventory.receive_stock.create')
                    ->name('catalogs');
                Route::get('/', [ReceiveStockController::class, 'index'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.view')
                    ->name('index');
                Route::get('/{id}', [ReceiveStockController::class, 'show'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.view')
                    ->name('show');
                Route::post('/lookup', [ReceiveStockController::class, 'lookup'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.create')
                    ->name('lookup');
                Route::put('/{id}', [ReceiveStockController::class, 'update'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.update')
                    ->name('update');
                Route::post('/{id}/release', [ReceiveStockController::class, 'release'])
                    ->middleware('permission_or_snapshot:stock_inventory.receive_stock.update')
                    ->name('release');
            });

        Route::prefix('manual-stock')
            ->name('stock-inventory.manual-stock.')
            ->group(function () {
                Route::get('/catalogs', [ManualStockController::class, 'catalogs'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.view,stock_inventory.manual_stock.create')
                    ->name('catalogs');
                Route::get('/', [ManualStockController::class, 'index'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.view')
                    ->name('index');
                Route::get('/{id}', [ManualStockController::class, 'show'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.view')
                    ->name('show');
                Route::post('/', [ManualStockController::class, 'store'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.create')
                    ->name('post');
                Route::put('/{id}', [ManualStockController::class, 'update'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.update')
                    ->name('update');
                Route::post('/{id}/release', [ManualStockController::class, 'release'])
                    ->middleware('permission_or_snapshot:stock_inventory.manual_stock.update')
                    ->name('release');
            });
    });
