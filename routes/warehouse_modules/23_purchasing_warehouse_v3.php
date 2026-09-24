<?php

use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehousePurchaseOrderV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehousePurchaseRequestV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/purchasing-v3')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', [WarehousePurchaseRequestV3Controller::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.procurement.request.view,warehouse.procurement.order.view')
            ->name('warehouse.purchasing-v3.options');

        Route::prefix('purchase-requests')->group(function (): void {
            Route::get('/', [WarehousePurchaseRequestV3Controller::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.view')
                ->name('warehouse.purchasing-v3.purchase-requests.index');
            Route::post('/', [WarehousePurchaseRequestV3Controller::class, 'store'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.create')
                ->name('warehouse.purchasing-v3.purchase-requests.store');
            Route::get('/{id}', [WarehousePurchaseRequestV3Controller::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.view')
                ->name('warehouse.purchasing-v3.purchase-requests.show');
            Route::put('/{id}', [WarehousePurchaseRequestV3Controller::class, 'update'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.update')
                ->name('warehouse.purchasing-v3.purchase-requests.update');
            Route::post('/{id}/submit', [WarehousePurchaseRequestV3Controller::class, 'submit'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.update')
                ->name('warehouse.purchasing-v3.purchase-requests.submit');
            Route::post('/{id}/approve', [WarehousePurchaseRequestV3Controller::class, 'approve'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.approve,warehouse.procurement.request.update')
                ->name('warehouse.purchasing-v3.purchase-requests.approve');
        });

        Route::prefix('purchase-orders')->group(function (): void {
            Route::get('/', [WarehousePurchaseOrderV3Controller::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.procurement.order.view')
                ->name('warehouse.purchasing-v3.purchase-orders.index');
            Route::get('/{id}', [WarehousePurchaseOrderV3Controller::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.procurement.order.view')
                ->name('warehouse.purchasing-v3.purchase-orders.show');
            Route::post('/{id}/invoices', [WarehousePurchaseOrderV3Controller::class, 'uploadInvoice'])
                ->middleware('permission_or_snapshot:warehouse.procurement.invoice.upload,warehouse.procurement.purchase.input,warehouse.procurement.order.update')
                ->name('warehouse.purchasing-v3.purchase-orders.invoices.store');
            Route::get('/{id}/invoices/{invoiceId}/download', [WarehousePurchaseOrderV3Controller::class, 'downloadInvoice'])
                ->middleware('permission_or_snapshot:warehouse.procurement.invoice.download,warehouse.procurement.order.view')
                ->name('warehouse.purchasing-v3.purchase-orders.invoices.download');
            Route::post('/{id}/prepare-stock-in', [WarehousePurchaseOrderV3Controller::class, 'prepareStockIn'])
                ->middleware('permission_or_snapshot:warehouse.procurement.purchase.input,warehouse.procurement.order.update')
                ->name('warehouse.purchasing-v3.purchase-orders.prepare-stock-in');
            Route::post('/{id}/complete-stock-in', [WarehousePurchaseOrderV3Controller::class, 'completeStockIn'])
                ->middleware('permission_or_snapshot:warehouse.stock_in.approve,warehouse.procurement.order.update')
                ->name('warehouse.purchasing-v3.purchase-orders.complete-stock-in');
        });
    });
