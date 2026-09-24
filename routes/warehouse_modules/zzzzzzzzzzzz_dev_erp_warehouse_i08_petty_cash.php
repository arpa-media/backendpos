<?php

use App\Http\Controllers\Api\V1\Warehouse\PettyCash\I08\WarehousePettyCashI08Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/petty-cash-i08')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::get('/expense-report-source',[WarehousePettyCashI08Controller::class,'expenseSource'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.view');
    Route::get('/catalogs',[WarehousePettyCashI08Controller::class,'catalogs'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.view,warehouse.purchasing.petty_cash.create');
    Route::get('/',[WarehousePettyCashI08Controller::class,'index'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.view');
    Route::post('/',[WarehousePettyCashI08Controller::class,'store'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.create');
    Route::get('/{id}',[WarehousePettyCashI08Controller::class,'show'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.view');
    Route::put('/{id}',[WarehousePettyCashI08Controller::class,'update'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.update');
    Route::delete('/{id}',[WarehousePettyCashI08Controller::class,'destroy'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.delete,warehouse.purchasing.petty_cash.update');
    Route::post('/{id}/submit',[WarehousePettyCashI08Controller::class,'submit'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.update,warehouse.purchasing.petty_cash.create');
    Route::post('/{id}/approve',[WarehousePettyCashI08Controller::class,'approve'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.approve');
    Route::post('/{id}/reject',[WarehousePettyCashI08Controller::class,'reject'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.approve');
    Route::post('/{id}/receive',[WarehousePettyCashI08Controller::class,'receive'])->middleware('permission_or_snapshot:warehouse.purchasing.petty_cash.receive,warehouse.purchasing.petty_cash.update');
});
