<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV8\WarehouseFinanceReportingV8Controller as FinanceV8;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/finance-v8')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/status',[FinanceV8::class,'status'])->middleware('permission_or_snapshot:warehouse.finance.coa.view,warehouse.finance.general_ledger.view')->name('warehouse.finance-v8.status.i08');
        Route::get('/coa',[FinanceV8::class,'coa'])->middleware('permission_or_snapshot:warehouse.finance.coa.view')->name('warehouse.finance-v8.coa.i08');
        Route::get('/general-ledger',[FinanceV8::class,'generalLedger'])->middleware('permission_or_snapshot:warehouse.finance.general_ledger.view')->name('warehouse.finance-v8.gl.i08');
        Route::get('/general-ledger/{accountId}',[FinanceV8::class,'generalLedgerTransactions'])->middleware('permission_or_snapshot:warehouse.finance.general_ledger.view')->name('warehouse.finance-v8.gl.detail.i08');
        Route::get('/balance-sheet',[FinanceV8::class,'balanceSheet'])->middleware('permission_or_snapshot:warehouse.finance.balance_sheet.view')->name('warehouse.finance-v8.bs.i08');
        Route::get('/cash-flow',[FinanceV8::class,'cashFlow'])->middleware('permission_or_snapshot:warehouse.finance.cash_flow.view')->name('warehouse.finance-v8.cf.i08');
        Route::get('/profit-loss',[FinanceV8::class,'profitLoss'])->middleware('permission_or_snapshot:warehouse.finance.profit_loss.view')->name('warehouse.finance-v8.pl.i08');

        Route::get('/export/coa',[FinanceV8::class,'exportCoa'])->middleware('permission_or_snapshot:warehouse.finance.coa.export')->name('warehouse.finance-v8.coa.export.i08');
        Route::get('/export/general-ledger',[FinanceV8::class,'exportGeneralLedger'])->middleware('permission_or_snapshot:warehouse.finance.general_ledger.export')->name('warehouse.finance-v8.gl.export.i08');
        Route::get('/export/balance-sheet',[FinanceV8::class,'exportBalanceSheet'])->middleware('permission_or_snapshot:warehouse.finance.balance_sheet.export')->name('warehouse.finance-v8.bs.export.i08');
        Route::get('/export/cash-flow',[FinanceV8::class,'exportCashFlow'])->middleware('permission_or_snapshot:warehouse.finance.cash_flow.export')->name('warehouse.finance-v8.cf.export.i08');
        Route::get('/export/profit-loss',[FinanceV8::class,'exportProfitLoss'])->middleware('permission_or_snapshot:warehouse.finance.profit_loss.export')->name('warehouse.finance-v8.pl.export.i08');
    });
