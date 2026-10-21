<?php

use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehouseStockInV13I18Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/purchasing-v3/purchase-orders')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/{id}/stock-in-progress', [WarehouseStockInV13I18Controller::class, 'progress'])
            ->middleware('permission_or_snapshot:warehouse.procurement.order.view');
        Route::post('/{id}/stock-in-partials', [WarehouseStockInV13I18Controller::class, 'partial'])
            ->middleware('permission_or_snapshot:warehouse.stock_in.approve');
        Route::post('/{id}/stock-in-finish', [WarehouseStockInV13I18Controller::class, 'finish'])
            ->middleware('permission_or_snapshot:warehouse.stock_in.approve');
        Route::post('/{id}/complete-po', [WarehouseStockInV13I18Controller::class, 'completePo'])
            ->middleware('permission_or_snapshot:warehouse.procurement.order.update');
    });
