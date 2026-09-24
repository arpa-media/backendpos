<?php

use App\Http\Controllers\Api\V1\Warehouse\TransferStockV4\WarehouseTransferStockController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/transfer-stock-v4')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', [WarehouseTransferStockController::class, 'options'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.transfer-stock-v4.options');
        Route::get('/orders', [WarehouseTransferStockController::class, 'index'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.transfer-stock-v4.index');
        Route::post('/orders', [WarehouseTransferStockController::class, 'store'])->middleware('permission_or_snapshot:warehouse.transfer.create')->name('warehouse.transfer-stock-v4.store');
        Route::get('/orders/{id}', [WarehouseTransferStockController::class, 'show'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.transfer-stock-v4.show');
        Route::put('/orders/{id}', [WarehouseTransferStockController::class, 'update'])->middleware('permission_or_snapshot:warehouse.transfer.update')->name('warehouse.transfer-stock-v4.update');
        Route::post('/orders/{id}/submit', [WarehouseTransferStockController::class, 'submit'])->middleware('permission_or_snapshot:warehouse.transfer.submit,warehouse.transfer.update')->name('warehouse.transfer-stock-v4.submit');
        Route::post('/orders/{id}/approve', [WarehouseTransferStockController::class, 'approve'])->middleware('permission_or_snapshot:warehouse.transfer.assign,warehouse.transfer.update')->name('warehouse.transfer-stock-v4.approve');

        Route::get('/receiving', [WarehouseTransferStockController::class, 'receivingIndex'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.view,warehouse.transfer.receive')->name('warehouse.transfer-stock-v4.receiving.index');
        Route::get('/receiving/{id}', [WarehouseTransferStockController::class, 'receivingShow'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.view,warehouse.transfer.receive')->name('warehouse.transfer-stock-v4.receiving.show');
        Route::post('/receiving/{id}/receive', [WarehouseTransferStockController::class, 'receive'])->middleware('permission_or_snapshot:warehouse.logistics.receiving.update,warehouse.transfer.receive')->name('warehouse.transfer-stock-v4.receiving.receive');
    });
