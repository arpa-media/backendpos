<?php

use App\Http\Controllers\Api\V1\Finance\FinancePettyCashExpensePostingController;
use App\Http\Controllers\Api\V1\Operational\OperationalOutletPinController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::prefix('finance/expense-report')->group(function (): void {
            Route::get('/', [FinancePettyCashExpensePostingController::class, 'index'])
                ->middleware('permission_or_snapshot:finance.expense_report.view')
                ->name('finance.i09.expense-report.index');
            Route::post('/items/{item}/post', [FinancePettyCashExpensePostingController::class, 'post'])
                ->middleware('permission_or_snapshot:finance.expense_report.post')
                ->name('finance.i09.expense-report.post');
            Route::post('/bulk-post', [FinancePettyCashExpensePostingController::class, 'bulkPost'])
                ->middleware('permission_or_snapshot:finance.expense_report.post')
                ->name('finance.i09.expense-report.bulk-post');
        });

        Route::prefix('operational/outlet-pins')->group(function (): void {
            Route::get('/', [OperationalOutletPinController::class, 'index'])
                ->middleware('permission_or_snapshot:operational.outlet_pin.view')
                ->name('operational.i09.outlet-pins.index');
            Route::put('/{outlet}', [OperationalOutletPinController::class, 'update'])
                ->middleware('permission_or_snapshot:operational.outlet_pin.update')
                ->name('operational.i09.outlet-pins.update');
        });
    });
