<?php

use App\Http\Controllers\Api\V1\Warehouse\Ledger\WarehouseLedgerAdjustmentController;
use App\Http\Controllers\Api\V1\Warehouse\Ledger\WarehouseLedgerHistoryController;
use App\Http\Controllers\Api\V1\Warehouse\Ledger\WarehouseLedgerOptionController;
use App\Http\Controllers\Api\V1\Warehouse\Ledger\WarehouseLedgerReconciliationController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/ledger')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', WarehouseLedgerOptionController::class)
            ->middleware('permission_or_snapshot:warehouse.ledger.history.view|warehouse.ledger.adjustment.view|warehouse.ledger.reconciliation.view')
            ->name('warehouse.ledger.options');

        Route::get('/history', [WarehouseLedgerHistoryController::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.ledger.history.view')
            ->name('warehouse.ledger.history.index');

        Route::post('/adjustments', [WarehouseLedgerAdjustmentController::class, 'store'])
            ->middleware('permission_or_snapshot:warehouse.ledger.adjustment.create')
            ->name('warehouse.ledger.adjustments.store');

        Route::post('/postings/{id}/reverse', [WarehouseLedgerAdjustmentController::class, 'reverse'])
            ->middleware('permission_or_snapshot:warehouse.ledger.adjustment.update')
            ->name('warehouse.ledger.postings.reverse');

        Route::get('/reconciliation', [WarehouseLedgerReconciliationController::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.ledger.reconciliation.view')
            ->name('warehouse.ledger.reconciliation.index');

        Route::post('/reconciliation/repair', [WarehouseLedgerReconciliationController::class, 'repair'])
            ->middleware('permission_or_snapshot:warehouse.ledger.reconciliation.update')
            ->name('warehouse.ledger.reconciliation.repair');
    });
