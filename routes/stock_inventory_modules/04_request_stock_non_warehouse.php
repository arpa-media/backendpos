<?php

use App\Http\Controllers\Api\V1\StockInventory\NonWarehouseStockRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory/request-stock-non-warehouse')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->name('stock-inventory.request-stock.non-warehouse.')
    ->group(function (): void {
        Route::get('/catalogs', [NonWarehouseStockRequestController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.view,stock_inventory.request_stock.create')
            ->name('catalogs');
        Route::get('/', [NonWarehouseStockRequestController::class, 'index'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.view')
            ->name('index');
        Route::post('/', [NonWarehouseStockRequestController::class, 'store'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.create')
            ->name('store');
        Route::post('/{id}/submit', [NonWarehouseStockRequestController::class, 'submit'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.update')
            ->name('submit');
        // Backward-safe guard for stale frontend/browser bundles. This endpoint must never receive stock.
        Route::post('/{id}/release', [NonWarehouseStockRequestController::class, 'deprecatedRelease'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.update')
            ->name('release');
        Route::get('/{id}', [NonWarehouseStockRequestController::class, 'show'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.view')
            ->name('show');
        Route::put('/{id}', [NonWarehouseStockRequestController::class, 'update'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.update')
            ->name('update');
        Route::delete('/{id}', [NonWarehouseStockRequestController::class, 'destroy'])
            ->middleware('permission_or_snapshot:stock_inventory.request_stock.delete')
            ->name('destroy');
    });
