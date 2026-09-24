<?php

use App\Http\Controllers\Api\V1\Warehouse\SalesV3\WarehouseSalesDemandV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/sales-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/stock-requests', [WarehouseSalesDemandV3Controller::class, 'stockRequests'])
            ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.view')
            ->name('warehouse.sales-v3.stock-requests.index');
        Route::get('/stock-requests/{id}', [WarehouseSalesDemandV3Controller::class, 'stockRequest'])
            ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.view')
            ->name('warehouse.sales-v3.stock-requests.show');
        Route::post('/stock-requests/{id}/approve', [WarehouseSalesDemandV3Controller::class, 'approveStockRequest'])
            ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.update,warehouse.stock_request.accept')
            ->name('warehouse.sales-v3.stock-requests.approve');
        Route::post('/stock-requests/{id}/reject', [WarehouseSalesDemandV3Controller::class, 'rejectStockRequest'])
            ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.update,warehouse.stock_request.accept')
            ->name('warehouse.sales-v3.stock-requests.reject');

        Route::get('/production-requests', [WarehouseSalesDemandV3Controller::class, 'productionRequests'])
            ->middleware('permission_or_snapshot:warehouse.production_request.view')
            ->name('warehouse.sales-v3.production-requests.index');
        Route::get('/production-requests/{id}', [WarehouseSalesDemandV3Controller::class, 'productionRequest'])
            ->middleware('permission_or_snapshot:warehouse.production_request.view')
            ->name('warehouse.sales-v3.production-requests.show');
        Route::post('/production-requests/{id}/approve', [WarehouseSalesDemandV3Controller::class, 'approveProductionRequest'])
            ->middleware('permission_or_snapshot:warehouse.production_request.update')
            ->name('warehouse.sales-v3.production-requests.approve');

        Route::get('/checker-prepare', [WarehouseSalesDemandV3Controller::class, 'logisticsQueue'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
            ->name('warehouse.sales-v3.checker-prepare.index');
        Route::get('/checker-prepare/{id}', [WarehouseSalesDemandV3Controller::class, 'logisticsQueueDetail'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
            ->name('warehouse.sales-v3.checker-prepare.show');
    });
