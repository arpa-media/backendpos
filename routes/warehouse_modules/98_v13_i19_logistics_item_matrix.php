<?php

use App\Http\Controllers\Api\V1\Warehouse\LogisticsV13I19\WarehouseLogisticsItemMatrixV13I19Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

// Read-only matrix tabs. Permissions mirror the corresponding existing warehouse menus.
Route::prefix('api/v1/warehouse/logistics-v3/matrix')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('checker', [WarehouseLogisticsItemMatrixV13I19Controller::class, 'checker'])
            ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
            ->name('warehouse.v13-i19.matrix.checker');
        Route::get('delivery-orders', [WarehouseLogisticsItemMatrixV13I19Controller::class, 'deliveryOrders'])
            ->middleware('permission_or_snapshot:warehouse.delivery_order.view')
            ->name('warehouse.v13-i19.matrix.delivery-orders');
        Route::get('goods-receipts', [WarehouseLogisticsItemMatrixV13I19Controller::class, 'goodsReceipts'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')
            ->name('warehouse.v13-i19.matrix.goods-receipts');
    });
