<?php

use App\Http\Controllers\Api\V1\Warehouse\ProductionV3\WarehouseProductionV3Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/production-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function (): void {
    Route::get('/opname/orders',[WarehouseProductionV3Controller::class,'index'])->middleware('permission_or_snapshot:warehouse.production.opname.view')->name('warehouse.production-v7.opname.orders.i07');
    Route::get('/opname/orders/{id}',[WarehouseProductionV3Controller::class,'show'])->middleware('permission_or_snapshot:warehouse.production.opname.view')->name('warehouse.production-v7.opname.show.i07');
    Route::get('/orders/{id}/material-opname',[WarehouseProductionV3Controller::class,'materialOpname'])->middleware('permission_or_snapshot:warehouse.production.opname.view')->name('warehouse.production-v7.material-opname.show.i07');
    Route::put('/orders/{id}/material-opname',[WarehouseProductionV3Controller::class,'saveMaterialOpname'])->middleware('permission_or_snapshot:warehouse.production.opname.update,warehouse.production.update')->name('warehouse.production-v7.material-opname.save.i07');
    Route::post('/orders/{id}/material-opname/finalize',[WarehouseProductionV3Controller::class,'finalizeMaterialOpname'])->middleware('permission_or_snapshot:warehouse.production.opname.finalize,warehouse.production.opname.update,warehouse.production.update')->name('warehouse.production-v7.material-opname.finalize.i07');

    Route::post('/orders/{id}/material-opname/reopen-requests',[WarehouseProductionV3Controller::class,'requestMaterialOpnameReopen'])
        ->middleware('permission_or_snapshot:warehouse.production.opname.reopen.request,warehouse.production.opname.update')
        ->name('warehouse.production-v7.material-opname.reopen.request.i04');
    Route::post('/orders/{id}/material-opname/reopen-requests/{requestId}/approve',[WarehouseProductionV3Controller::class,'approveMaterialOpnameReopen'])
        ->middleware('permission_or_snapshot:warehouse.production.opname.reopen.approve')
        ->name('warehouse.production-v7.material-opname.reopen.approve.i04');
    Route::post('/orders/{id}/material-opname/reopen-requests/{requestId}/reject',[WarehouseProductionV3Controller::class,'rejectMaterialOpnameReopen'])
        ->middleware('permission_or_snapshot:warehouse.production.opname.reopen.approve')
        ->name('warehouse.production-v7.material-opname.reopen.reject.i04');
});
