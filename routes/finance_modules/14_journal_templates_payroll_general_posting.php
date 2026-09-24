<?php

use App\Http\Controllers\Api\V1\Finance\FinanceGeneralPostingController;
use App\Http\Controllers\Api\V1\Finance\FinancePayrollPostingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::prefix('payroll-posting')->group(function (): void {
        Route::get('/options',[FinancePayrollPostingController::class,'options'])->middleware('permission_or_snapshot:finance.payroll_posting.view')->name('finance.iter05.payroll.options');
        Route::get('/',[FinancePayrollPostingController::class,'index'])->middleware('permission_or_snapshot:finance.payroll_posting.view')->name('finance.iter05.payroll.index');
        Route::post('/',[FinancePayrollPostingController::class,'store'])->middleware('permission_or_snapshot:finance.payroll_posting.create')->name('finance.iter05.payroll.store');
        Route::get('/{id}',[FinancePayrollPostingController::class,'show'])->middleware('permission_or_snapshot:finance.payroll_posting.view')->name('finance.iter05.payroll.show');
        Route::put('/{id}',[FinancePayrollPostingController::class,'update'])->middleware('permission_or_snapshot:finance.payroll_posting.update')->name('finance.iter05.payroll.update');
        Route::delete('/{id}',[FinancePayrollPostingController::class,'destroy'])->middleware('permission_or_snapshot:finance.payroll_posting.delete')->name('finance.iter05.payroll.destroy');
        Route::post('/{id}/submit',[FinancePayrollPostingController::class,'submit'])->middleware('permission_or_snapshot:finance.payroll_posting.submit,finance.payroll_posting.update')->name('finance.iter05.payroll.submit');
        Route::post('/{id}/approve',[FinancePayrollPostingController::class,'approve'])->middleware('permission_or_snapshot:finance.payroll_posting.approve,finance.payroll_posting.update')->name('finance.iter05.payroll.approve');
        Route::post('/{id}/post-accrual',[FinancePayrollPostingController::class,'postAccrual'])->middleware('permission_or_snapshot:finance.payroll_posting.post,finance.payroll_posting.update')->name('finance.iter05.payroll.accrual');
        Route::post('/{id}/payments',[FinancePayrollPostingController::class,'pay'])->middleware('permission_or_snapshot:finance.payroll_posting.pay,finance.payroll_posting.update')->name('finance.iter05.payroll.pay');
    });

    Route::prefix('general-posting')->group(function (): void {
        Route::get('/options',[FinanceGeneralPostingController::class,'options'])->middleware('permission_or_snapshot:finance.general_posting.view')->name('finance.iter05.general.options');
        Route::get('/',[FinanceGeneralPostingController::class,'index'])->middleware('permission_or_snapshot:finance.general_posting.view')->name('finance.iter05.general.index');
        Route::get('/control-summary',[FinanceGeneralPostingController::class,'controlSummary'])->middleware('permission_or_snapshot:finance.general_posting.view')->name('finance.iter05.general.control-summary');
        Route::post('/',[FinanceGeneralPostingController::class,'store'])->middleware('permission_or_snapshot:finance.general_posting.create')->name('finance.iter05.general.store');
        Route::get('/{id}',[FinanceGeneralPostingController::class,'show'])->middleware('permission_or_snapshot:finance.general_posting.view')->name('finance.iter05.general.show');
        Route::put('/{id}',[FinanceGeneralPostingController::class,'update'])->middleware('permission_or_snapshot:finance.general_posting.update')->name('finance.iter05.general.update');
        Route::delete('/{id}',[FinanceGeneralPostingController::class,'destroy'])->middleware('permission_or_snapshot:finance.general_posting.delete')->name('finance.iter05.general.destroy');
        Route::post('/{id}/refresh',[FinanceGeneralPostingController::class,'refresh'])->middleware('permission_or_snapshot:finance.general_posting.update')->name('finance.iter05.general.refresh');
        Route::get('/{id}/preview',[FinanceGeneralPostingController::class,'preview'])->middleware('permission_or_snapshot:finance.general_posting.view,finance.general_posting.update')->name('finance.iter05.general.preview');
        Route::post('/{id}/post',[FinanceGeneralPostingController::class,'post'])->middleware('permission_or_snapshot:finance.general_posting.post,finance.general_posting.update')->name('finance.iter05.general.post');
        Route::post('/{id}/reopen',[FinanceGeneralPostingController::class,'reopen'])->middleware('permission_or_snapshot:finance.general_posting.reopen,finance.general_posting.update')->name('finance.iter05.general.reopen');
        Route::put('/{id}/correction',[FinanceGeneralPostingController::class,'correct'])->middleware('permission_or_snapshot:finance.general_posting.update')->name('finance.iter05.general.correction');
    });
});
