<?php

use App\Http\Controllers\Api\V1\Finance\FinanceFinancialStatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/statements')->middleware(['api','auth:sanctum'])->group(function():void{
    Route::get('/options',[FinanceFinancialStatementController::class,'options'])
        ->middleware('permission_or_snapshot:finance.balance_sheet.view,finance.profit_loss.view,finance.cash_flow.view')
        ->name('finance.iter08.statements.options');
    Route::get('/balance-sheet',[FinanceFinancialStatementController::class,'balanceSheet'])
        ->middleware('permission_or_snapshot:finance.balance_sheet.view')
        ->name('finance.iter08.balance-sheet');
    Route::get('/profit-loss',[FinanceFinancialStatementController::class,'profitLoss'])
        ->middleware('permission_or_snapshot:finance.profit_loss.view')
        ->name('finance.iter08.profit-loss');
    Route::get('/cash-flow',[FinanceFinancialStatementController::class,'cashFlow'])
        ->middleware('permission_or_snapshot:finance.cash_flow.view')
        ->name('finance.iter08.cash-flow');
    Route::get('/cash-flow-rules',[FinanceFinancialStatementController::class,'cashFlowRules'])
        ->middleware('permission_or_snapshot:finance.cash_flow.view')
        ->name('finance.iter08.cash-flow-rules');
    Route::put('/cash-flow-rules/{accountId}',[FinanceFinancialStatementController::class,'updateCashFlowRule'])
        ->middleware('permission_or_snapshot:finance.cash_flow.manage_mapping')
        ->name('finance.iter08.cash-flow-rules.update');
});
