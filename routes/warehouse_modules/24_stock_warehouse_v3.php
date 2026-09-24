<?php

use App\Http\Controllers\Api\V1\Warehouse\StockV3\WarehouseStockPriceV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/stock-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::prefix('prices')->group(function (): void {
            Route::get('/', [WarehouseStockPriceV3Controller::class,'index'])->middleware('permission_or_snapshot:warehouse.inventory.price.view');
            Route::get('/options', [WarehouseStockPriceV3Controller::class,'options'])->middleware('permission_or_snapshot:warehouse.inventory.price.view');
            Route::post('/upsert', [WarehouseStockPriceV3Controller::class,'upsert'])->middleware('permission_or_snapshot:warehouse.inventory.price.update|warehouse.inventory.price.create');
            Route::get('/template', [WarehouseStockPriceV3Controller::class,'template'])->middleware('permission_or_snapshot:warehouse.inventory.price.view');
            Route::get('/export', [WarehouseStockPriceV3Controller::class,'export'])->middleware('permission_or_snapshot:warehouse.inventory.price.view');
            Route::post('/import', [WarehouseStockPriceV3Controller::class,'import'])->middleware('permission_or_snapshot:warehouse.inventory.price.update|warehouse.inventory.price.create');
        });
    });
