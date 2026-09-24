<?php

use App\Http\Controllers\Api\V1\Finance\FinanceExpenseReportController;
use App\Http\Controllers\Api\V1\Finance\FinanceOverhandleReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::prefix('reports/overhandle')->group(function (): void {
            Route::get('/', [FinanceOverhandleReportController::class, 'index'])
                ->middleware('permission_or_snapshot:report.overhandle.view')
                ->name('finance.iter04.overhandle.index');
            Route::get('/snapshot', [FinanceOverhandleReportController::class, 'snapshot'])
                ->middleware('permission_or_snapshot:report.overhandle.view,report.overhandle.create,report.overhandle.update')
                ->name('finance.iter04.overhandle.snapshot');
            Route::post('/', [FinanceOverhandleReportController::class, 'store'])
                ->middleware('permission_or_snapshot:report.overhandle.create,report.overhandle.update')
                ->name('finance.iter04.overhandle.store');
            Route::get('/reconciliation-source', [FinanceOverhandleReportController::class, 'reconciliationSource'])
                ->middleware('permission_or_snapshot:report.overhandle.view')
                ->name('finance.iter04.overhandle.reconciliation-source');
        });

        Route::prefix('reports/expense')->group(function (): void {
            Route::get('/', [FinanceExpenseReportController::class, 'index'])
                ->middleware('permission_or_snapshot:report.expense_request.view')
                ->name('finance.iter04.expense.index');
            Route::get('/preview', [FinanceExpenseReportController::class, 'preview'])
                ->middleware('permission_or_snapshot:report.expense_request.view')
                ->name('finance.iter04.expense.preview');
            Route::get('/coa-options', [FinanceExpenseReportController::class, 'coaOptions'])
                ->middleware('permission_or_snapshot:report.expense_request.view,report.expense_request.create,report.expense_request.update')
                ->name('finance.iter04.expense.coa-options');
            Route::put('/header', [FinanceExpenseReportController::class, 'storeHeader'])
                ->middleware('permission_or_snapshot:report.expense_request.update')
                ->name('finance.iter04.expense.header');
            Route::post('/items', [FinanceExpenseReportController::class, 'storeItem'])
                ->middleware('permission_or_snapshot:report.expense_request.create')
                ->name('finance.iter04.expense.items.store');
            Route::put('/items/{item}', [FinanceExpenseReportController::class, 'updateItem'])
                ->middleware('permission_or_snapshot:report.expense_request.update')
                ->name('finance.iter04.expense.items.update');
            Route::delete('/items/{item}', [FinanceExpenseReportController::class, 'destroyItem'])
                ->middleware('permission_or_snapshot:report.expense_request.delete')
                ->name('finance.iter04.expense.items.destroy');
        });
    });
