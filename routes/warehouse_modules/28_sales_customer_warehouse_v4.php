<?php

use App\Http\Controllers\Api\V1\Warehouse\SalesCustomerV4\WarehouseSalesCustomerController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/sales-customer-v4')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', [WarehouseSalesCustomerController::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.view')
            ->name('warehouse.sales-customer-v4.options');
        Route::get('/orders', [WarehouseSalesCustomerController::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.view')
            ->name('warehouse.sales-customer-v4.index');
        Route::post('/orders', [WarehouseSalesCustomerController::class, 'store'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.create')
            ->name('warehouse.sales-customer-v4.store');
        Route::get('/orders/{id}', [WarehouseSalesCustomerController::class, 'show'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.view')
            ->name('warehouse.sales-customer-v4.show');
        Route::put('/orders/{id}', [WarehouseSalesCustomerController::class, 'update'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.update');
        Route::post('/orders/{id}/submit', [WarehouseSalesCustomerController::class, 'submit'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.submit');
        Route::post('/orders/{id}/approve', [WarehouseSalesCustomerController::class, 'approve'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.approve,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.approve');
        Route::post('/orders/{id}/override-customer-gr', [WarehouseSalesCustomerController::class, 'overrideCustomerReceipt'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.receive,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.override-gr');
        Route::post('/orders/{id}/complete-gr', [WarehouseSalesCustomerController::class, 'completeGoodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.receive,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.complete-gr');
    });
