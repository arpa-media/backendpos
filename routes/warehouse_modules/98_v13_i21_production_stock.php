<?php
use App\Http\Controllers\Api\V1\Warehouse\ProductionV13\ProductionStockI21Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/production-v13')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/stock',[ProductionStockI21Controller::class,'index'])
            ->middleware('permission_or_snapshot:warehouse.production.view');
    });
