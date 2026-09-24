<?php

use App\Http\Controllers\Api\V1\Finance\FinanceGeneralLedgerController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/general-ledger')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/options',[FinanceGeneralLedgerController::class,'options'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')
        ->name('finance.iter07.general-ledger.options');
    Route::get('/summary',[FinanceGeneralLedgerController::class,'summary'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')
        ->name('finance.iter07.general-ledger.summary');
    Route::get('/transactions',[FinanceGeneralLedgerController::class,'transactions'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')
        ->name('finance.iter07.general-ledger.transactions');
    Route::get('/grouped',[FinanceGeneralLedgerController::class,'grouped'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')
        ->name('finance.iter07.general-ledger.grouped');
    Route::get('/journals/{id}',[FinanceGeneralLedgerController::class,'journal'])
        ->middleware('permission_or_snapshot:finance.general_ledger.view')
        ->name('finance.iter07.general-ledger.journal');
});
