<?php

use App\Http\Controllers\Api\V1\Warehouse\StockRequest\WarehouseOutletStockRequestController;
use App\Http\Controllers\Api\V1\Warehouse\StockRequest\WarehouseStockRequestHandoffController;
use App\Http\Controllers\Api\V1\Warehouse\StockRequest\WarehouseStockRequestInboxController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/stock-requests')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::prefix('outlet')->group(function (): void {
            Route::get('/options', [WarehouseOutletStockRequestController::class, 'options'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.outlet.view,stock_inventory.request_stock.view,stock_inventory.request_stock.create')
                ->name('warehouse.stock-requests.outlet.options');
            Route::get('/', [WarehouseOutletStockRequestController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.outlet.view,stock_inventory.request_stock.view')
                ->name('warehouse.stock-requests.outlet.index');
            Route::post('/', [WarehouseOutletStockRequestController::class, 'store'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.outlet.create,stock_inventory.request_stock.create')
                ->name('warehouse.stock-requests.outlet.store');
            Route::get('/{id}', [WarehouseOutletStockRequestController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.outlet.view,stock_inventory.request_stock.view')
                ->name('warehouse.stock-requests.outlet.show');
            Route::put('/{id}', [WarehouseOutletStockRequestController::class, 'update'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.outlet.update,stock_inventory.request_stock.update')
                ->name('warehouse.stock-requests.outlet.update');
            Route::post('/{id}/submit', [WarehouseOutletStockRequestController::class, 'submit'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.submit,warehouse.stock_request.outlet.update,stock_inventory.request_stock.update')
                ->name('warehouse.stock-requests.outlet.submit');
            Route::post('/{id}/approve', [WarehouseOutletStockRequestController::class, 'approve'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.approve,warehouse.stock_request.outlet.update,stock_inventory.request_stock.update')
                ->name('warehouse.stock-requests.outlet.approve');
        });
    });

Route::prefix('api/v1/warehouse/stock-requests')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::prefix('inbox')->group(function (): void {
            Route::get('/', [WarehouseStockRequestInboxController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.view')
                ->name('warehouse.stock-requests.inbox.index');
            Route::get('/{id}', [WarehouseStockRequestInboxController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.view')
                ->name('warehouse.stock-requests.inbox.show');
            Route::post('/{id}/accept', [WarehouseStockRequestInboxController::class, 'accept'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.accept,warehouse.stock_request.inbox.update')
                ->name('warehouse.stock-requests.inbox.accept');
        });

        Route::prefix('handoffs')->group(function (): void {
            Route::get('/', [WarehouseStockRequestHandoffController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.handoff.view')
                ->name('warehouse.stock-requests.handoffs.index');
            Route::post('/{id}/generate', [WarehouseStockRequestHandoffController::class, 'generate'])
                ->middleware('permission_or_snapshot:warehouse.stock_request.handoff.generate,warehouse.stock_request.handoff.update')
                ->name('warehouse.stock-requests.handoffs.generate');
        });
    });
