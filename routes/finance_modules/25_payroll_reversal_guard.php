<?php

use App\Http\Controllers\Api\V1\Finance\FinancePayrollPostingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/payroll-posting')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::post('/{id}/cancel-approval', [FinancePayrollPostingController::class, 'cancelApproval'])
        ->middleware('permission_or_snapshot:finance.payroll_posting.unapprove,finance.payroll_posting.update')
        ->name('finance.hr25.payroll.cancel-approval');
    Route::post('/{id}/reverse-accrual', [FinancePayrollPostingController::class, 'reverseAccrual'])
        ->middleware('permission_or_snapshot:finance.payroll_posting.reverse_accrual,finance.payroll_posting.update')
        ->name('finance.hr25.payroll.reverse-accrual');
    Route::post('/{id}/payments/{paymentId}/reverse', [FinancePayrollPostingController::class, 'reversePayment'])
        ->middleware('permission_or_snapshot:finance.payroll_posting.reverse_payment,finance.payroll_posting.update')
        ->name('finance.hr25.payroll.reverse-payment');
});
