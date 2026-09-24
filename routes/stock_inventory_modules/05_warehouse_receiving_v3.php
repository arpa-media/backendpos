<?php

use App\Http\Controllers\Api\V1\StockInventory\WarehouseReceivingV3Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory/warehouse-receiving-v3')
    ->middleware(['api','auth:sanctum','outlet_scope','outlet_timezone'])
    ->group(function (): void {
        Route::get('/', [WarehouseReceivingV3Controller::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.view,stock_inventory.receive_stock.view')
            ->name('stock-inventory.warehouse-receiving-v3.index');
        Route::get('/{deliveryOrderId}', [WarehouseReceivingV3Controller::class, 'show'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.view,stock_inventory.receive_stock.view')
            ->name('stock-inventory.warehouse-receiving-v3.show');
        Route::post('/{deliveryOrderId}/receive', [WarehouseReceivingV3Controller::class, 'receive'])
            ->middleware('permission_or_snapshot:warehouse.receiving.outlet.update,warehouse.receiving.outlet.create,stock_inventory.receive_stock.create')
            ->name('stock-inventory.warehouse-receiving-v3.receive');
    });
