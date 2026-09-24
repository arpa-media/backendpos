<?php

use App\Http\Controllers\Api\V1\Warehouse\PettyCash\I09\WarehouseExpenseReportI09Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/expense-report-i09')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/', [WarehouseExpenseReportI09Controller::class, 'index'])
            ->middleware('permission_or_snapshot:warehouse.finance.expense_report.view');
        Route::get('/export', [WarehouseExpenseReportI09Controller::class, 'export'])
            ->middleware('permission_or_snapshot:warehouse.finance.expense_report.export');
    });
