<?php

use App\Http\Controllers\Api\V1\Warehouse\ProductionV3\WarehouseProductionPricingI06Controller;
use App\Http\Controllers\Api\V1\Warehouse\SalesCustomerV4\WarehouseSalesCustomerController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/sales-customer-v4')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/price-preview', [WarehouseSalesCustomerController::class, 'pricePreview'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.view,warehouse.sales.customer.create,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.price-preview.i06');
    });

Route::prefix('api/v1/warehouse/production-pricing-i06')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/orders/{id}/price-bands', [WarehouseProductionPricingI06Controller::class, 'priceBands'])
            ->middleware('permission_or_snapshot:warehouse.production.view');
        Route::post('/orders/{id}/results', [WarehouseProductionPricingI06Controller::class, 'storeResult'])
            ->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update');
        Route::put('/orders/{id}/results/{resultId}', [WarehouseProductionPricingI06Controller::class, 'updateResult'])
            ->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update');
        Route::post('/orders/{id}/results/{resultId}/approve', [WarehouseProductionPricingI06Controller::class, 'approveResult'])
            ->middleware('permission_or_snapshot:warehouse.production.approve,warehouse.production.update');
        Route::post('/orders/{id}/results/{resultId}/reject', [WarehouseProductionPricingI06Controller::class, 'rejectResult'])
            ->middleware('permission_or_snapshot:warehouse.production.result.reject,warehouse.production.approve,warehouse.production.update');
        Route::delete('/orders/{id}/results/{resultId}', [WarehouseProductionPricingI06Controller::class, 'deleteResult'])
            ->middleware('permission_or_snapshot:warehouse.production.result.delete,warehouse.production.delete');
    });
