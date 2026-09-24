<?php

use App\Http\Controllers\Api\V1\Warehouse\Iteration06\WarehouseTransferSalesIteration06Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])->group(function (): void {
    Route::prefix('api/v1/warehouse/transfer-stock-v4')->group(function (): void {
        Route::get('/import/template', [WarehouseTransferSalesIteration06Controller::class, 'transferTemplate'])
            ->middleware('permission_or_snapshot:warehouse.transfer.import,warehouse.transfer.create')
            ->name('warehouse.transfer-stock-v4.import.template.i06');
        Route::post('/import/preview', [WarehouseTransferSalesIteration06Controller::class, 'transferImportPreview'])
            ->middleware('permission_or_snapshot:warehouse.transfer.import,warehouse.transfer.create')
            ->name('warehouse.transfer-stock-v4.import.preview.i06');
        Route::post('/orders/{id}/submit-ready', [WarehouseTransferSalesIteration06Controller::class, 'transferSubmitReady'])
            ->middleware('permission_or_snapshot:warehouse.transfer.submit,warehouse.transfer.update')
            ->name('warehouse.transfer-stock-v4.submit-ready.i06');
        Route::post('/orders/{id}/approve-ready', [WarehouseTransferSalesIteration06Controller::class, 'transferApproveReady'])
            ->middleware('permission_or_snapshot:warehouse.transfer.assign,warehouse.transfer.update')
            ->name('warehouse.transfer-stock-v4.approve-ready.i06');
    });

    Route::prefix('api/v1/warehouse/sales-customer-v4')->group(function (): void {
        Route::post('/orders/{id}/submit-ready', [WarehouseTransferSalesIteration06Controller::class, 'salesSubmitReady'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.submit,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.submit-ready.i06');
        Route::post('/orders/{id}/approve-ready', [WarehouseTransferSalesIteration06Controller::class, 'salesApproveReady'])
            ->middleware('permission_or_snapshot:warehouse.sales.customer.ready,warehouse.sales.customer.approve,warehouse.sales.customer.update')
            ->name('warehouse.sales-customer-v4.approve-ready.i06');
    });
});
