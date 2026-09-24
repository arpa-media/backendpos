<?php

use App\Http\Controllers\Api\V1\Finance\FinanceBonusBudgetController;
use Illuminate\Support\Facades\Route;

Route::prefix('payroll-posting')->group(function(): void {
    Route::get('/{id}/bonus-budget',[FinanceBonusBudgetController::class,'show'])->middleware('permission_or_snapshot:finance.payroll_posting.bonus_budget,finance.payroll_posting.update')->name('finance.payroll.bonus-budget.show');
    Route::put('/{id}/bonus-budget',[FinanceBonusBudgetController::class,'update'])->middleware('permission_or_snapshot:finance.payroll_posting.bonus_budget,finance.payroll_posting.update')->name('finance.payroll.bonus-budget.update');
});
