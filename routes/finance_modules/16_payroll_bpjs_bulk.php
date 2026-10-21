<?php

use App\Http\Controllers\Api\V1\Finance\FinancePayrollBpjsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::prefix('payroll-posting')->group(function (): void {
        Route::get('/{id}/bpjs/export',[FinancePayrollBpjsController::class,'export'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.bpjs.export,finance.payroll_posting.view')->name('finance.payroll.bpjs.export');
        Route::post('/{id}/bpjs/import',[FinancePayrollBpjsController::class,'import'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.bpjs.import,finance.payroll_posting.update')->name('finance.payroll.bpjs.import');
        Route::get('/{id}/bpjs/report',[FinancePayrollBpjsController::class,'report'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.view')->name('finance.payroll.bpjs.report');
    });
});

// V13 I10: Finance records PPh21, not BPJS. Same payroll posting Access Matrix actions.
Route::prefix('api/v1/finance')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::prefix('payroll-posting/{id}/pph21')->group(function (): void {
        Route::get('/recipients',[\App\Http\Controllers\Api\V1\Finance\FinancePayrollPph21V13I10Controller::class,'recipients'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.view')->name('fin.payroll.v13i10.pph21.recipients');
        Route::get('/export',[\App\Http\Controllers\Api\V1\Finance\FinancePayrollPph21V13I10Controller::class,'export'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.bpjs.export,finance.payroll_posting.view')->name('fin.payroll.v13i10.pph21.export');
        Route::post('/import',[\App\Http\Controllers\Api\V1\Finance\FinancePayrollPph21V13I10Controller::class,'import'])
            ->middleware('permission_or_snapshot:finance.payroll_posting.bpjs.import,finance.payroll_posting.update')->name('fin.payroll.v13i10.pph21.import');
    });
});
