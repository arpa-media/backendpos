<?php

use App\Http\Controllers\Api\V1\Finance\FinancePettyCashExpensePostingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP POS FINAL I03 - Expense Report / Petty Cash Recap
|--------------------------------------------------------------------------
| Additive route. Posting endpoints remain owned by the existing Finance
| module; I03 only adds the chronological recap surface.
*/
Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
    Route::get('/finance/expense-report/petty-cash-recap', [FinancePettyCashExpensePostingController::class, 'recap'])
        ->middleware('permission_or_snapshot:finance.expense_report.view')
        ->name('finance.expense-report.petty-cash-recap.i03');
});
