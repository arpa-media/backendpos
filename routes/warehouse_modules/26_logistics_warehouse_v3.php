<?php

use App\Http\Controllers\Api\V1\Warehouse\LogisticsV3\WarehouseLogisticsV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/logistics-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', [WarehouseLogisticsV3Controller::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view,warehouse.delivery_order.view')
            ->name('warehouse.logistics-v3.options');

        Route::get('/checker-prepare', [WarehouseLogisticsV3Controller::class, 'checkerPrepare'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
            ->name('warehouse.logistics-v3.checker-prepare.index');
        Route::get('/checker-prepare/{id}', [WarehouseLogisticsV3Controller::class, 'checkerPrepareDetail'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
            ->name('warehouse.logistics-v3.checker-prepare.show');
        Route::post('/checker-prepare/{id}/delivery-order', [WarehouseLogisticsV3Controller::class, 'generateDeliveryOrder'])
            ->middleware('permission_or_snapshot:warehouse.delivery_order.create,warehouse.fulfillment.task.update')
            ->name('warehouse.logistics-v3.checker-prepare.delivery-order');

        Route::get('/delivery-orders', [WarehouseLogisticsV3Controller::class, 'deliveryOrders'])
            ->middleware('permission_or_snapshot:warehouse.delivery_order.view')
            ->name('warehouse.logistics-v3.delivery-orders.index');
        Route::get('/delivery-orders/{id}', [WarehouseLogisticsV3Controller::class, 'deliveryOrder'])
            ->middleware('permission_or_snapshot:warehouse.delivery_order.view')
            ->name('warehouse.logistics-v3.delivery-orders.show');

        Route::get('/goods-receipts', [WarehouseLogisticsV3Controller::class, 'goodsReceipts'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')
            ->name('warehouse.logistics-v3.goods-receipts.index');
        Route::get('/goods-receipts/{id}', [WarehouseLogisticsV3Controller::class, 'goodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')
            ->name('warehouse.logistics-v3.goods-receipts.show');
        Route::post('/goods-receipts/{id}/complete', [WarehouseLogisticsV3Controller::class, 'completeGoodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate')
            ->name('warehouse.logistics-v3.goods-receipts.complete');
    });
