<?php

use App\Http\Controllers\Api\V1\Warehouse\ProductionCogs\I07\WarehouseProductionCogsI07Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/production-cogs-i07')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::get('/',[WarehouseProductionCogsI07Controller::class,'index'])->middleware('permission_or_snapshot:warehouse.production.cost.view');
    Route::get('/productions/{id}',[WarehouseProductionCogsI07Controller::class,'show'])->middleware('permission_or_snapshot:warehouse.production.cost.view');
    Route::post('/productions/{id}/reconcile',[WarehouseProductionCogsI07Controller::class,'reconcile'])->middleware('permission_or_snapshot:warehouse.production.cogs.reconcile,warehouse.production.cost.create');
    Route::post('/productions/{id}/post',[WarehouseProductionCogsI07Controller::class,'post'])->middleware('permission_or_snapshot:warehouse.production.cogs.post,warehouse.production.cost.update');
    Route::post('/productions/{id}/reconcile-post',[WarehouseProductionCogsI07Controller::class,'reconcilePost'])->middleware('permission_or_snapshot:warehouse.production.cogs.post,warehouse.production.cost.update');
    Route::post('/backfill',[WarehouseProductionCogsI07Controller::class,'backfill'])->middleware('permission_or_snapshot:warehouse.production.cogs.post,warehouse.production.cost.update');
});

// Loaded after Finance auto-post route: this becomes the final Production Finished contract.
Route::prefix('api/v1/warehouse/production-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/orders/{id}/finish',[WarehouseProductionCogsI07Controller::class,'finishAndReconcile'])
        ->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update')
        ->name('warehouse.production-v3.finish.i07-cogs');
});
