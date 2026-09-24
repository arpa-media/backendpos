<?php
use App\Http\Controllers\Api\V1\Warehouse\Production\WarehouseProductionV2Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/production-v2')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function(){
 Route::get('/dashboard',[WarehouseProductionV2Controller::class,'dashboard'])->middleware('permission_or_snapshot:warehouse.production.control.view');
 Route::get('/boms',[WarehouseProductionV2Controller::class,'boms'])->middleware('permission_or_snapshot:warehouse.production.bom.view');
 Route::post('/boms',[WarehouseProductionV2Controller::class,'storeBom'])->middleware('permission_or_snapshot:warehouse.production.bom.create');
 Route::put('/boms/{id}',[WarehouseProductionV2Controller::class,'updateBom'])->middleware('permission_or_snapshot:warehouse.production.bom.update');
 Route::get('/productions/{id}/costing',[WarehouseProductionV2Controller::class,'costing'])->middleware('permission_or_snapshot:warehouse.production.cost.view');
 Route::post('/productions/{id}/cost-components',[WarehouseProductionV2Controller::class,'addCost'])->middleware('permission_or_snapshot:warehouse.production.cost.create');
 Route::post('/productions/{id}/wastes',[WarehouseProductionV2Controller::class,'addWaste'])->middleware('permission_or_snapshot:warehouse.production.waste.create');
 Route::post('/productions/{id}/reconcile',[WarehouseProductionV2Controller::class,'reconcile'])->middleware('permission_or_snapshot:warehouse.production.control.view');
});
