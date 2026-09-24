<?php

use App\Http\Controllers\Api\V1\Warehouse\Fulfillment\WarehouseCheckerPrepareTaskController;
use App\Http\Controllers\Api\V1\Warehouse\Fulfillment\WarehouseDeliveryOrderController;
use App\Http\Controllers\Api\V1\Warehouse\Fulfillment\WarehouseFulfillmentController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::prefix('fulfillments')->group(function (): void {
            Route::get('/options', [WarehouseFulfillmentController::class, 'options'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.review.view,warehouse.fulfillment.assign')
                ->name('warehouse.fulfillments.options');
            Route::get('/', [WarehouseFulfillmentController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.review.view')
                ->name('warehouse.fulfillments.index');
            Route::get('/{requestId}', [WarehouseFulfillmentController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.review.view')
                ->name('warehouse.fulfillments.show');
            Route::post('/{requestId}/assign', [WarehouseFulfillmentController::class, 'assign'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.assign,warehouse.fulfillment.review.update')
                ->name('warehouse.fulfillments.assign');
            Route::post('/{requestId}/delivery-order', [WarehouseFulfillmentController::class, 'dispatch'])
                ->middleware('permission_or_snapshot:warehouse.delivery_order.dispatch,warehouse.delivery_order.create')
                ->name('warehouse.fulfillments.delivery-order');
        });

        Route::prefix('checker-prepare-tasks')->group(function (): void {
            Route::get('/', [WarehouseCheckerPrepareTaskController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
                ->name('warehouse.checker-prepare-tasks.index');
            Route::get('/{taskId}', [WarehouseCheckerPrepareTaskController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.task.view')
                ->name('warehouse.checker-prepare-tasks.show');
            Route::post('/{taskId}/scans', [WarehouseCheckerPrepareTaskController::class, 'scan'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.task.scan,warehouse.fulfillment.task.update')
                ->name('warehouse.checker-prepare-tasks.scan');
            Route::delete('/{taskId}/allocations/{allocationId}', [WarehouseCheckerPrepareTaskController::class, 'removeAllocation'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.task.scan,warehouse.fulfillment.task.update')
                ->name('warehouse.checker-prepare-tasks.allocations.destroy');
            Route::post('/{taskId}/confirm-shortage', [WarehouseCheckerPrepareTaskController::class, 'confirmShortage'])
                ->middleware('permission_or_snapshot:warehouse.fulfillment.task.shortage,warehouse.fulfillment.task.update')
                ->name('warehouse.checker-prepare-tasks.shortage');
        });

        Route::prefix('delivery-orders')->group(function (): void {
            Route::get('/', [WarehouseDeliveryOrderController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.delivery_order.view')
                ->name('warehouse.delivery-orders.index');
            Route::get('/{id}', [WarehouseDeliveryOrderController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.delivery_order.view')
                ->name('warehouse.delivery-orders.show');
            Route::post('/{id}/print-event', [WarehouseDeliveryOrderController::class, 'markPrinted'])
                ->middleware('permission_or_snapshot:warehouse.delivery_order.print,warehouse.delivery_order.update')
                ->name('warehouse.delivery-orders.print-event');
        });
    });
