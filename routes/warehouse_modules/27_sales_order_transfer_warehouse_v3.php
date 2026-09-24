<?php

use App\Http\Controllers\Api\V1\Warehouse\SalesTransferV3\WarehouseSalesTransferV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/sales-transfer-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function (): void {
    Route::get('/options',[WarehouseSalesTransferV3Controller::class,'options'])->middleware('permission_or_snapshot:warehouse.sales.order.view,warehouse.transfer.view')->name('warehouse.sales-transfer-v3.options');

    Route::get('/sales-orders',[WarehouseSalesTransferV3Controller::class,'salesOrders'])->middleware('permission_or_snapshot:warehouse.sales.order.view')->name('warehouse.sales-transfer-v3.sales-orders.index');
    Route::post('/sales-orders',[WarehouseSalesTransferV3Controller::class,'storeSalesOrder'])->middleware('permission_or_snapshot:warehouse.sales.order.create')->name('warehouse.sales-transfer-v3.sales-orders.store');
    Route::get('/sales-orders/{id}',[WarehouseSalesTransferV3Controller::class,'salesOrder'])->middleware('permission_or_snapshot:warehouse.sales.order.view')->name('warehouse.sales-transfer-v3.sales-orders.show');
    Route::put('/sales-orders/{id}',[WarehouseSalesTransferV3Controller::class,'updateSalesOrder'])->middleware('permission_or_snapshot:warehouse.sales.order.update')->name('warehouse.sales-transfer-v3.sales-orders.update');
    Route::post('/sales-orders/{id}/submit',[WarehouseSalesTransferV3Controller::class,'submitSalesOrder'])->middleware('permission_or_snapshot:warehouse.sales.order.update')->name('warehouse.sales-transfer-v3.sales-orders.submit');
    Route::post('/sales-orders/{id}/approve',[WarehouseSalesTransferV3Controller::class,'approveSalesOrder'])->middleware('permission_or_snapshot:warehouse.sales.order.approve')->name('warehouse.sales-transfer-v3.sales-orders.approve');

    Route::get('/transfers',[WarehouseSalesTransferV3Controller::class,'transfers'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.sales-transfer-v3.transfers.index');
    Route::post('/transfers',[WarehouseSalesTransferV3Controller::class,'storeTransfer'])->middleware('permission_or_snapshot:warehouse.transfer.create')->name('warehouse.sales-transfer-v3.transfers.store');
    Route::get('/transfers/{id}',[WarehouseSalesTransferV3Controller::class,'transfer'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.sales-transfer-v3.transfers.show');
    Route::put('/transfers/{id}',[WarehouseSalesTransferV3Controller::class,'updateTransfer'])->middleware('permission_or_snapshot:warehouse.transfer.update')->name('warehouse.sales-transfer-v3.transfers.update');
    Route::post('/transfers/{id}/submit',[WarehouseSalesTransferV3Controller::class,'submitTransfer'])->middleware('permission_or_snapshot:warehouse.transfer.submit,warehouse.transfer.update')->name('warehouse.sales-transfer-v3.transfers.submit');
    Route::post('/transfers/{id}/approve',[WarehouseSalesTransferV3Controller::class,'approveTransfer'])->middleware('permission_or_snapshot:warehouse.transfer.assign,warehouse.transfer.update')->name('warehouse.sales-transfer-v3.transfers.approve');

    Route::get('/warehouse-receiving',[WarehouseSalesTransferV3Controller::class,'warehouseReceivings'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.view,warehouse.transfer.receive')->name('warehouse.sales-transfer-v3.warehouse-receiving.index');
    Route::get('/warehouse-receiving/{id}',[WarehouseSalesTransferV3Controller::class,'warehouseReceiving'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.view,warehouse.transfer.receive')->name('warehouse.sales-transfer-v3.warehouse-receiving.show');
    Route::post('/warehouse-receiving/{id}/receive',[WarehouseSalesTransferV3Controller::class,'receiveWarehouse'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.update,warehouse.transfer.receive')->name('warehouse.sales-transfer-v3.warehouse-receiving.receive');

    Route::post('/checker-prepare/{id}/delivery-order',[WarehouseSalesTransferV3Controller::class,'generateDeliveryOrder'])->middleware('permission_or_snapshot:warehouse.delivery_order.create,warehouse.fulfillment.task.update')->name('warehouse.sales-transfer-v3.checker-prepare.delivery-order');
    Route::post('/goods-receipts/{id}/customer-receive',[WarehouseSalesTransferV3Controller::class,'receiveCustomerGoodsReceipt'])->middleware('permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate')->name('warehouse.sales-transfer-v3.goods-receipts.customer-receive');
    Route::post('/goods-receipts/{id}/complete',[WarehouseSalesTransferV3Controller::class,'completeGoodsReceipt'])->middleware('permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate')->name('warehouse.sales-transfer-v3.goods-receipts.complete');
});
