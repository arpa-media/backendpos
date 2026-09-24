<?php
use App\Http\Controllers\Api\V1\Warehouse\Hardening\WarehouseHardeningController;use App\Http\Middleware\ResolveWarehouseScope;use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/hardening')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function(){
 Route::get('/dashboard',[WarehouseHardeningController::class,'dashboard'])->middleware('permission_or_snapshot:warehouse.control.health.view');
 Route::get('/go-live-runs',[WarehouseHardeningController::class,'runs'])->middleware('permission_or_snapshot:warehouse.control.go_live.view');
 Route::get('/go-live-runs/{id}',[WarehouseHardeningController::class,'show'])->middleware('permission_or_snapshot:warehouse.control.go_live.view');
 Route::post('/go-live-runs',[WarehouseHardeningController::class,'run'])->middleware('permission_or_snapshot:warehouse.control.go_live.run');
 Route::post('/go-live-checks/{id}/waive',[WarehouseHardeningController::class,'waive'])->middleware('permission_or_snapshot:warehouse.control.go_live.waive');
 Route::get('/reversals',[WarehouseHardeningController::class,'reversals'])->middleware('permission_or_snapshot:warehouse.control.reversal.view');
 Route::post('/reversals',[WarehouseHardeningController::class,'createReversal'])->middleware('permission_or_snapshot:warehouse.control.reversal.create');
 Route::post('/reversals/{id}/decision',[WarehouseHardeningController::class,'approveReversal'])->middleware('permission_or_snapshot:warehouse.control.reversal.approve');
});
