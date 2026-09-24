<?php

use App\Http\Controllers\Api\V1\Finance\FinanceReconciliationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/reconciliations')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/options', [FinanceReconciliationController::class, 'options'])
            ->middleware('permission_or_snapshot:finance.reconciliation.view')
            ->name('finance.iter05.reconciliation.options');
        Route::get('/source', [FinanceReconciliationController::class, 'source'])
            ->middleware('permission_or_snapshot:finance.reconciliation.view')
            ->name('finance.iter05.reconciliation.source');
        Route::get('/', [FinanceReconciliationController::class, 'index'])
            ->middleware('permission_or_snapshot:finance.reconciliation.view')
            ->name('finance.iter05.reconciliation.index');
        Route::post('/draft', [FinanceReconciliationController::class, 'createDraft'])
            ->middleware('permission_or_snapshot:finance.reconciliation.create')
            ->name('finance.iter05.reconciliation.draft');
        Route::get('/{id}', [FinanceReconciliationController::class, 'show'])
            ->middleware('permission_or_snapshot:finance.reconciliation.view')
            ->name('finance.iter05.reconciliation.show');
        Route::put('/{id}', [FinanceReconciliationController::class, 'update'])
            ->middleware('permission_or_snapshot:finance.reconciliation.update')
            ->name('finance.iter05.reconciliation.update');
        Route::post('/{id}/refresh-source', [FinanceReconciliationController::class, 'refreshSource'])
            ->middleware('permission_or_snapshot:finance.reconciliation.update')
            ->name('finance.iter05.reconciliation.refresh');
        Route::post('/{id}/preview', [FinanceReconciliationController::class, 'preview'])
            ->middleware('permission_or_snapshot:finance.reconciliation.view,finance.reconciliation.update')
            ->name('finance.iter05.reconciliation.preview');
        Route::post('/{id}/post', [FinanceReconciliationController::class, 'post'])
            ->middleware('permission_or_snapshot:finance.reconciliation.post,finance.reconciliation.update')
            ->name('finance.iter05.reconciliation.post');
        Route::post('/{id}/reopen', [FinanceReconciliationController::class, 'reopen'])
            ->middleware('permission_or_snapshot:finance.reconciliation.reopen,finance.reconciliation.update')
            ->name('finance.iter05.reconciliation.reopen');
        Route::delete('/{id}', [FinanceReconciliationController::class, 'destroy'])
            ->middleware('permission_or_snapshot:finance.reconciliation.delete')
            ->name('finance.iter05.reconciliation.destroy');
    });
