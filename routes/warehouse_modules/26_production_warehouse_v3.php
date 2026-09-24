<?php

use App\Http\Controllers\Api\V1\Warehouse\ProductionV3\WarehouseProductionV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/production-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options',[WarehouseProductionV3Controller::class,'options'])->middleware('permission_or_snapshot:warehouse.production.view');
        Route::get('/orders',[WarehouseProductionV3Controller::class,'index'])->middleware('permission_or_snapshot:warehouse.production.view');
        Route::post('/orders',[WarehouseProductionV3Controller::class,'store'])->middleware('permission_or_snapshot:warehouse.production.create');
        Route::get('/orders/{id}',[WarehouseProductionV3Controller::class,'show'])->middleware('permission_or_snapshot:warehouse.production.view');
        Route::put('/orders/{id}',[WarehouseProductionV3Controller::class,'update'])->middleware('permission_or_snapshot:warehouse.production.update');
        Route::post('/orders/{id}/approve',[WarehouseProductionV3Controller::class,'approve'])->middleware('permission_or_snapshot:warehouse.production.approve,warehouse.production.update');
        Route::post('/orders/{id}/results',[WarehouseProductionV3Controller::class,'storeResult'])->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update');
        Route::put('/orders/{id}/results/{resultId}',[WarehouseProductionV3Controller::class,'updateResult'])->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update');
        Route::post('/orders/{id}/results/{resultId}/approve',[WarehouseProductionV3Controller::class,'approveResult'])->middleware('permission_or_snapshot:warehouse.production.approve,warehouse.production.update');
        Route::post('/orders/{id}/finish',[WarehouseProductionV3Controller::class,'finish'])->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update');
    });
