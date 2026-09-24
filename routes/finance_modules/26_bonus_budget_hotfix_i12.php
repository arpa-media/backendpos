<?php

use App\Http\Controllers\Api\V1\Finance\FinanceBonusBudgetController;
use Illuminate\Support\Facades\Route;

/*
 | HR v5 Post-I11 I12
 | The original Iteration 17 bonus-budget module was registered without the
 | /api/v1/finance prefix. The backoffice API client correctly calls the
 | canonical Finance API path, therefore register the missing canonical routes
 | additively without deleting the legacy URI.
 */
Route::prefix('api/v1/finance')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::prefix('payroll-posting')->group(function (): void {
            Route::get('/{id}/bonus-budget', [FinanceBonusBudgetController::class, 'show'])
                ->middleware('permission_or_snapshot:finance.payroll_posting.bonus_budget,finance.payroll_posting.update')
                ->name('finance.payroll.bonus-budget.i12.show');

            Route::put('/{id}/bonus-budget', [FinanceBonusBudgetController::class, 'update'])
                ->middleware('permission_or_snapshot:finance.payroll_posting.bonus_budget,finance.payroll_posting.update')
                ->name('finance.payroll.bonus-budget.i12.update');
        });
    });
