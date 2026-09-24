<?php

use App\Http\Controllers\Api\V1\Warehouse\Transfer\WarehouseCheckerTransferController;
use App\Http\Controllers\Api\V1\Warehouse\Transfer\WarehouseStockTransferController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/transfers/options', [WarehouseStockTransferController::class, 'options'])
            ->middleware('permission_or_snapshot:warehouse.transfer.view,warehouse.transfer.checker.view')
            ->name('warehouse.transfers.options');

        Route::prefix('transfers')->group(function (): void {
            Route::get('/', [WarehouseStockTransferController::class, 'index'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.transfers.index');
            Route::post('/', [WarehouseStockTransferController::class, 'store'])->middleware('permission_or_snapshot:warehouse.transfer.create')->name('warehouse.transfers.store');
            Route::get('/{id}', [WarehouseStockTransferController::class, 'show'])->middleware('permission_or_snapshot:warehouse.transfer.view')->name('warehouse.transfers.show');
            Route::put('/{id}', [WarehouseStockTransferController::class, 'update'])->middleware('permission_or_snapshot:warehouse.transfer.update')->name('warehouse.transfers.update');
            Route::post('/{id}/submit', [WarehouseStockTransferController::class, 'submit'])->middleware('permission_or_snapshot:warehouse.transfer.submit,warehouse.transfer.update')->name('warehouse.transfers.submit');
            Route::post('/{id}/assign', [WarehouseStockTransferController::class, 'assign'])->middleware('permission_or_snapshot:warehouse.transfer.assign,warehouse.transfer.update')->name('warehouse.transfers.assign');
            Route::post('/{id}/dispatch', [WarehouseStockTransferController::class, 'dispatch'])->middleware('permission_or_snapshot:warehouse.transfer.dispatch,warehouse.transfer.update')->name('warehouse.transfers.dispatch');
            Route::post('/{id}/start-receiving', [WarehouseStockTransferController::class, 'startReceiving'])->middleware('permission_or_snapshot:warehouse.transfer.receive,warehouse.transfer.update')->name('warehouse.transfers.start-receiving');
            Route::post('/{id}/receiving-scans', [WarehouseStockTransferController::class, 'scanReceiving'])->middleware('permission_or_snapshot:warehouse.transfer.receive,warehouse.transfer.update')->name('warehouse.transfers.receiving-scans');
            Route::post('/{id}/units/{unitId}/resolve', [WarehouseStockTransferController::class, 'resolveUnit'])->middleware('permission_or_snapshot:warehouse.transfer.receive,warehouse.transfer.update')->name('warehouse.transfers.units.resolve');
            Route::post('/{id}/complete-receiving', [WarehouseStockTransferController::class, 'completeReceiving'])->middleware('permission_or_snapshot:warehouse.transfer.receive,warehouse.transfer.update')->name('warehouse.transfers.complete-receiving');
            Route::post('/{id}/units/{unitId}/confirm-return', [WarehouseStockTransferController::class, 'confirmReturn'])->middleware('permission_or_snapshot:warehouse.transfer.discrepancy.resolve,warehouse.transfer.update')->name('warehouse.transfers.units.confirm-return');
            Route::post('/{id}/units/{unitId}/close-missing', [WarehouseStockTransferController::class, 'closeMissing'])->middleware('permission_or_snapshot:warehouse.transfer.discrepancy.resolve,warehouse.transfer.update')->name('warehouse.transfers.units.close-missing');
            Route::post('/{id}/print-event', [WarehouseStockTransferController::class, 'markPrinted'])->middleware('permission_or_snapshot:warehouse.transfer.print,warehouse.transfer.view')->name('warehouse.transfers.print-event');
        });

        Route::prefix('checker-transfer-tasks')->group(function (): void {
            Route::get('/', [WarehouseCheckerTransferController::class, 'index'])->middleware('permission_or_snapshot:warehouse.transfer.checker.view')->name('warehouse.checker-transfer-tasks.index');
            Route::get('/{id}', [WarehouseCheckerTransferController::class, 'show'])->middleware('permission_or_snapshot:warehouse.transfer.checker.view')->name('warehouse.checker-transfer-tasks.show');
            Route::post('/{id}/scans', [WarehouseCheckerTransferController::class, 'scan'])->middleware('permission_or_snapshot:warehouse.transfer.checker.scan,warehouse.transfer.checker.update')->name('warehouse.checker-transfer-tasks.scan');
            Route::delete('/{id}/allocations/{allocationId}', [WarehouseCheckerTransferController::class, 'cancelAllocation'])->middleware('permission_or_snapshot:warehouse.transfer.checker.scan,warehouse.transfer.checker.update')->name('warehouse.checker-transfer-tasks.allocations.destroy');
            Route::post('/{id}/confirm-shortage', [WarehouseCheckerTransferController::class, 'confirmShortage'])->middleware('permission_or_snapshot:warehouse.transfer.checker.scan,warehouse.transfer.checker.update')->name('warehouse.checker-transfer-tasks.confirm-shortage');
        });
    });
