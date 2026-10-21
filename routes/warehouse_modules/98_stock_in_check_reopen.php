<?php

use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehouseStockInV13I18Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/purchasing-v3/purchase-orders')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::post('/{id}/stock-in-reopen', [WarehouseStockInV13I18Controller::class, 'reopen'])
            ->middleware('permission_or_snapshot:warehouse.stock_in.approve');
    });
