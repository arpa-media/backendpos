<?php

use App\Http\Controllers\Api\V1\Warehouse\FinalV3\WarehouseV3DocumentController;
use App\Http\Controllers\Api\V1\Warehouse\FinalV3\WarehouseV3FinalController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/v3-final')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/dashboard',[WarehouseV3FinalController::class,'dashboard'])
            ->middleware('permission_or_snapshot:warehouse.dashboard.view')
            ->name('warehouse.v3-final.dashboard');
        Route::get('/reconciliation',[WarehouseV3FinalController::class,'reconciliation'])
            ->middleware('permission_or_snapshot:warehouse.operational_reconciliation.view,warehouse.dashboard.view')
            ->name('warehouse.v3-final.reconciliation');
        Route::get('/readiness',[WarehouseV3FinalController::class,'readiness'])
            ->middleware('permission_or_snapshot:warehouse.control.go_live.view,warehouse.dashboard.view')
            ->name('warehouse.v3-final.readiness');

        Route::prefix('documents')->group(function (): void {
            Route::get('/purchase-request/{id}',[WarehouseV3DocumentController::class,'purchaseRequest'])
                ->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.v3-final.documents.pr');
            Route::get('/purchase-order/{id}',[WarehouseV3DocumentController::class,'purchaseOrder'])
                ->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.v3-final.documents.po');
            Route::get('/production-request/{id}',[WarehouseV3DocumentController::class,'productionRequest'])
                ->middleware('permission_or_snapshot:warehouse.production_request.view')->name('warehouse.v3-final.documents.production-request');
            Route::get('/production-order/{id}',[WarehouseV3DocumentController::class,'productionOrder'])
                ->middleware('permission_or_snapshot:warehouse.production.view')->name('warehouse.v3-final.documents.production-order');
            Route::get('/delivery-order/{id}',[WarehouseV3DocumentController::class,'deliveryOrder'])
                ->middleware('permission_or_snapshot:warehouse.delivery_order.view')->name('warehouse.v3-final.documents.do');
            Route::get('/goods-receipt/{id}',[WarehouseV3DocumentController::class,'goodsReceipt'])
                ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')->name('warehouse.v3-final.documents.gr');
            Route::get('/incoming-invoice/{source}/{id}',[WarehouseV3DocumentController::class,'incomingInvoice'])
                ->where('source','auto_incoming|manual')->middleware('permission_or_snapshot:warehouse.purchasing.invoice.view')->name('warehouse.v3-final.documents.incoming');
            Route::get('/outgoing-invoice/{source}/{id}',[WarehouseV3DocumentController::class,'outgoingInvoice'])
                ->where('source','auto_outgoing|legacy_outgoing|manual')->middleware('permission_or_snapshot:warehouse.finance.invoice.view')->name('warehouse.v3-final.documents.outgoing');
        });
    });
