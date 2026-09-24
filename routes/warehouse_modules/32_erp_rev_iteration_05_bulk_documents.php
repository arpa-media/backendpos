<?php

use App\Http\Controllers\Api\V1\Warehouse\FinalV3\WarehouseBulkDocumentController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/v3-final/documents/bulk-pdf')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::post('/purchase-request',[WarehouseBulkDocumentController::class,'purchaseRequest'])
            ->middleware('permission_or_snapshot:warehouse.procurement.request.view')->name('warehouse.v3-final.bulk.pr');
        Route::post('/purchase-order',[WarehouseBulkDocumentController::class,'purchaseOrder'])
            ->middleware('permission_or_snapshot:warehouse.procurement.order.view')->name('warehouse.v3-final.bulk.po');
        Route::post('/stock-request',[WarehouseBulkDocumentController::class,'stockRequest'])
            ->middleware('permission_or_snapshot:warehouse.stock_request.inbox.view')->name('warehouse.v3-final.bulk.stock-request');
        Route::post('/delivery-order',[WarehouseBulkDocumentController::class,'deliveryOrder'])
            ->middleware('permission_or_snapshot:warehouse.delivery_order.print,warehouse.delivery_order.view')->name('warehouse.v3-final.bulk.do');
        Route::post('/goods-receipt',[WarehouseBulkDocumentController::class,'goodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.monitor.view')->name('warehouse.v3-final.bulk.gr');
    });
