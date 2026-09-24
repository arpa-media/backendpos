<?php

use App\Http\Controllers\Api\V1\Finance\FinanceFinancialStatementExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance/statement-exports')->middleware(['api','auth:sanctum'])->group(function():void{
    Route::get('/options',[FinanceFinancialStatementExportController::class,'options'])
        ->middleware('permission_or_snapshot:finance.financial_statement_export.view')
        ->name('finance.statement-export.options');

    Route::get('/balance-sheet.xlsx',[FinanceFinancialStatementExportController::class,'balanceSheet'])
        ->middleware(['permission_or_snapshot:finance.financial_statement_export.view','permission_or_snapshot:finance.balance_sheet.view'])
        ->name('finance.statement-export.balance-sheet');
    Route::get('/profit-loss.xlsx',[FinanceFinancialStatementExportController::class,'profitLoss'])
        ->middleware(['permission_or_snapshot:finance.financial_statement_export.view','permission_or_snapshot:finance.profit_loss.view'])
        ->name('finance.statement-export.profit-loss');
    Route::get('/cash-flow.xlsx',[FinanceFinancialStatementExportController::class,'cashFlow'])
        ->middleware(['permission_or_snapshot:finance.financial_statement_export.view','permission_or_snapshot:finance.cash_flow.view'])
        ->name('finance.statement-export.cash-flow');
});
