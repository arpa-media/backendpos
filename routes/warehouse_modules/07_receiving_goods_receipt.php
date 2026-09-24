<?php

use App\Http\Controllers\Api\V1\Warehouse\Receiving\WarehouseOutletReceivingController;
use App\Http\Controllers\Api\V1\Warehouse\Receiving\WarehouseReceivingMonitorController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/outlet-receivings')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/', [WarehouseOutletReceivingController::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.view,stock_inventory.receive_stock.view')
            ->name('warehouse.outlet-receivings.index');
        Route::get('/{deliveryOrderId}', [WarehouseOutletReceivingController::class, 'show'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.view,stock_inventory.receive_stock.view')
            ->name('warehouse.outlet-receivings.show');
        Route::post('/{deliveryOrderId}/start', [WarehouseOutletReceivingController::class, 'start'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.create,warehouse.receiving.outlet.update')
            ->name('warehouse.outlet-receivings.start');
        Route::post('/{deliveryOrderId}/scans', [WarehouseOutletReceivingController::class, 'scan'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.scan,warehouse.receiving.outlet.update')
            ->name('warehouse.outlet-receivings.scan');
        Route::post('/{deliveryOrderId}/units/{unitId}/resolve', [WarehouseOutletReceivingController::class, 'resolveUnit'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.resolve,warehouse.receiving.outlet.update')
            ->name('warehouse.outlet-receivings.units.resolve');
        Route::post('/{deliveryOrderId}/goods-receipt', [WarehouseOutletReceivingController::class, 'goodsReceipt'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.generate,warehouse.receiving.outlet.update')
            ->name('warehouse.outlet-receivings.goods-receipt');
        Route::post('/goods-receipts/{receivingId}/print-event', [WarehouseOutletReceivingController::class, 'markPrinted'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.outlet.view')
            ->name('warehouse.outlet-receivings.print-event');
    });

Route::prefix('api/v1/warehouse/receivings')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/', [WarehouseReceivingMonitorController::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')
            ->name('warehouse.receivings.index');
        Route::get('/{receivingId}', [WarehouseReceivingMonitorController::class, 'show'])
            ->middleware('permission_or_snapshot:warehouse.receiving.monitor.view')
            ->name('warehouse.receivings.show');
        Route::post('/{receivingId}/units/{unitId}/confirm-return', [WarehouseReceivingMonitorController::class, 'confirmReturn'])
            ->middleware('permission_or_snapshot:warehouse.receiving.discrepancy.return,warehouse.receiving.monitor.update')
            ->name('warehouse.receivings.units.confirm-return');
        Route::post('/{receivingId}/units/{unitId}/close-missing', [WarehouseReceivingMonitorController::class, 'closeMissing'])
            ->middleware('permission_or_snapshot:warehouse.receiving.discrepancy.close,warehouse.receiving.monitor.update')
            ->name('warehouse.receivings.units.close-missing');
        Route::post('/{receivingId}/print-event', [WarehouseReceivingMonitorController::class, 'markPrinted'])
            ->middleware('permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.monitor.view')
            ->name('warehouse.receivings.print-event');
    });
