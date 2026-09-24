<?php

use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseCheckerKeeperController;
use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehousePurchaseRequestController;
use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseStockInController;
use App\Http\Controllers\Api\V1\Warehouse\Procurement\WarehouseSupplierPurchaseOrderController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/procurement/options',[WarehousePurchaseRequestController::class,'options'])
            ->middleware('permission_or_snapshot:warehouse.procurement.request.view,warehouse.procurement.order.view,warehouse.stock_in.view,warehouse.stock_in.keeper.view')
            ->name('warehouse.procurement.options');

        Route::prefix('purchase-requests')->group(function (): void {
            Route::get('/',[WarehousePurchaseRequestController::class,'index'])->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.purchase-requests.index');
            Route::post('/',[WarehousePurchaseRequestController::class,'store'])->middleware('permission_or_snapshot:warehouse.procurement.request.create')->name('warehouse.purchase-requests.store');
            Route::get('/{id}',[WarehousePurchaseRequestController::class,'show'])->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.purchase-requests.show');
            Route::put('/{id}',[WarehousePurchaseRequestController::class,'update'])->middleware('permission_or_snapshot:warehouse.procurement.request.update')->name('warehouse.purchase-requests.update');
            Route::post('/{id}/submit',[WarehousePurchaseRequestController::class,'submit'])->middleware('permission_or_snapshot:warehouse.procurement.request.update')->name('warehouse.purchase-requests.submit');
            Route::post('/{id}/decision',[WarehousePurchaseRequestController::class,'decide'])->middleware('permission_or_snapshot:warehouse.procurement.request.approve,warehouse.procurement.request.update')->name('warehouse.purchase-requests.decision');
        });

        Route::prefix('supplier-purchase-orders')->group(function (): void {
            Route::get('/',[WarehouseSupplierPurchaseOrderController::class,'index'])->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.supplier-purchase-orders.index');
            Route::get('/{id}',[WarehouseSupplierPurchaseOrderController::class,'show'])->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.supplier-purchase-orders.show');
            Route::post('/{id}/assign-buyer',[WarehouseSupplierPurchaseOrderController::class,'assignBuyer'])->middleware('permission_or_snapshot:warehouse.procurement.order.assign,warehouse.procurement.order.update')->name('warehouse.supplier-purchase-orders.assign-buyer');
            Route::put('/{id}/purchase',[WarehouseSupplierPurchaseOrderController::class,'savePurchase'])->middleware('permission_or_snapshot:warehouse.procurement.purchase.input,warehouse.procurement.order.update')->name('warehouse.supplier-purchase-orders.purchase');
            Route::post('/{id}/approve-purchase',[WarehouseSupplierPurchaseOrderController::class,'approvePurchase'])->middleware('permission_or_snapshot:warehouse.procurement.purchase.approve,warehouse.procurement.order.update')->name('warehouse.supplier-purchase-orders.approve-purchase');
            Route::post('/{id}/invoices',[WarehouseSupplierPurchaseOrderController::class,'uploadInvoice'])->middleware('permission_or_snapshot:warehouse.procurement.invoice.upload,warehouse.procurement.purchase.input')->name('warehouse.supplier-purchase-orders.invoices.store');
            Route::get('/{id}/invoices/{invoiceId}/download',[WarehouseSupplierPurchaseOrderController::class,'downloadInvoice'])->middleware('permission_or_snapshot:warehouse.procurement.invoice.download,warehouse.procurement.order.view')->name('warehouse.supplier-purchase-orders.invoices.download');
        });

        Route::prefix('stock-ins')->group(function (): void {
            Route::get('/',[WarehouseStockInController::class,'index'])->middleware('permission_or_snapshot:warehouse.stock_in.view')->name('warehouse.stock-ins.index');
            Route::get('/{id}',[WarehouseStockInController::class,'show'])->middleware('permission_or_snapshot:warehouse.stock_in.view')->name('warehouse.stock-ins.show');
            Route::post('/{id}/plan',[WarehouseStockInController::class,'plan'])->middleware('permission_or_snapshot:warehouse.stock_in.plan,warehouse.stock_in.update')->name('warehouse.stock-ins.plan');
            Route::post('/{id}/print-event',[WarehouseStockInController::class,'markPrinted'])->middleware('permission_or_snapshot:warehouse.stock_in.print,warehouse.stock_in.view')->name('warehouse.stock-ins.print-event');
            Route::post('/{id}/approve',[WarehouseStockInController::class,'approve'])->middleware('permission_or_snapshot:warehouse.stock_in.approve,warehouse.stock_in.update')->name('warehouse.stock-ins.approve');
        });

        Route::prefix('checker-keeper-tasks')->group(function (): void {
            Route::get('/',[WarehouseCheckerKeeperController::class,'index'])->middleware('permission_or_snapshot:warehouse.stock_in.keeper.view')->name('warehouse.checker-keeper-tasks.index');
            Route::get('/{id}',[WarehouseCheckerKeeperController::class,'show'])->middleware('permission_or_snapshot:warehouse.stock_in.keeper.view')->name('warehouse.checker-keeper-tasks.show');
            Route::post('/{id}/scans',[WarehouseCheckerKeeperController::class,'scan'])->middleware('permission_or_snapshot:warehouse.stock_in.keeper.scan,warehouse.stock_in.keeper.update')->name('warehouse.checker-keeper-tasks.scan');
        });
    });
