<?php

use App\Http\Controllers\Api\V1\Warehouse\Production\WarehouseCheckerProductionController;
use App\Http\Controllers\Api\V1\Warehouse\Production\WarehouseProductionController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/production/options',[WarehouseProductionController::class,'options'])
            ->middleware('permission_or_snapshot:warehouse.production.view,warehouse.production.checker.view')
            ->name('warehouse.production.options');

        Route::prefix('productions')->group(function (): void {
            Route::get('/',[WarehouseProductionController::class,'index'])->middleware('permission_or_snapshot:warehouse.production.view')->name('warehouse.productions.index');
            Route::post('/',[WarehouseProductionController::class,'store'])->middleware('permission_or_snapshot:warehouse.production.create')->name('warehouse.productions.store');
            Route::get('/{id}',[WarehouseProductionController::class,'show'])->middleware('permission_or_snapshot:warehouse.production.view')->name('warehouse.productions.show');
            Route::put('/{id}',[WarehouseProductionController::class,'update'])->middleware('permission_or_snapshot:warehouse.production.update')->name('warehouse.productions.update');
            Route::post('/{id}/submit',[WarehouseProductionController::class,'submit'])->middleware('permission_or_snapshot:warehouse.production.submit,warehouse.production.update')->name('warehouse.productions.submit');
            Route::post('/{id}/assign',[WarehouseProductionController::class,'assign'])->middleware('permission_or_snapshot:warehouse.production.assign,warehouse.production.update')->name('warehouse.productions.assign');
            Route::post('/{id}/release-materials',[WarehouseProductionController::class,'release'])->middleware('permission_or_snapshot:warehouse.production.release,warehouse.production.update')->name('warehouse.productions.release-materials');
            Route::post('/{id}/production-done',[WarehouseProductionController::class,'done'])->middleware('permission_or_snapshot:warehouse.production.done,warehouse.production.update')->name('warehouse.productions.done');
            Route::post('/{id}/approve',[WarehouseProductionController::class,'approve'])->middleware('permission_or_snapshot:warehouse.production.approve,warehouse.production.update')->name('warehouse.productions.approve');
            Route::post('/{id}/print-event',[WarehouseProductionController::class,'markPrinted'])->middleware('permission_or_snapshot:warehouse.production.print,warehouse.production.view')->name('warehouse.productions.print-event');
        });

        Route::prefix('checker-production-tasks')->group(function (): void {
            Route::get('/',[WarehouseCheckerProductionController::class,'index'])->middleware('permission_or_snapshot:warehouse.production.checker.view')->name('warehouse.checker-production-tasks.index');
            Route::get('/{id}',[WarehouseCheckerProductionController::class,'show'])->middleware('permission_or_snapshot:warehouse.production.checker.view')->name('warehouse.checker-production-tasks.show');
            Route::post('/{id}/scans',[WarehouseCheckerProductionController::class,'scan'])->middleware('permission_or_snapshot:warehouse.production.checker.scan,warehouse.production.checker.update')->name('warehouse.checker-production-tasks.scan');
            Route::delete('/{id}/allocations/{allocationId}',[WarehouseCheckerProductionController::class,'cancelAllocation'])->middleware('permission_or_snapshot:warehouse.production.checker.scan,warehouse.production.checker.update')->name('warehouse.checker-production-tasks.allocations.destroy');
            Route::post('/{id}/confirm-shortage',[WarehouseCheckerProductionController::class,'confirmShortage'])->middleware('permission_or_snapshot:warehouse.production.checker.scan,warehouse.production.checker.update')->name('warehouse.checker-production-tasks.confirm-shortage');
        });
    });
