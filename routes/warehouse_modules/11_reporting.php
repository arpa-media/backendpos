<?php

use App\Http\Controllers\Api\V1\Warehouse\Reporting\WarehouseFinalDashboardController;
use App\Http\Controllers\Api\V1\Warehouse\Reporting\WarehouseOperationalReconciliationController;
use App\Http\Controllers\Api\V1\Warehouse\Reporting\WarehouseReportController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/analytics/dashboard', WarehouseFinalDashboardController::class)
            ->middleware('permission_or_snapshot:warehouse.dashboard.view')
            ->name('warehouse.analytics.dashboard');

        Route::prefix('reports')->group(function (): void {
            Route::get('/options', [WarehouseReportController::class, 'options'])
                ->middleware('permission_or_snapshot:warehouse.reporting.view')
                ->name('warehouse.reports.options');
            Route::post('/print-event', [WarehouseReportController::class, 'markPrinted'])
                ->middleware('permission_or_snapshot:warehouse.reporting.print,warehouse.reporting.view')
                ->name('warehouse.reports.print-event');
            Route::get('/reconciliation', [WarehouseOperationalReconciliationController::class, 'index'])
                ->middleware('permission_or_snapshot:warehouse.operational_reconciliation.view')
                ->name('warehouse.reports.reconciliation.index');
            Route::post('/reconciliation/run', [WarehouseOperationalReconciliationController::class, 'run'])
                ->middleware('permission_or_snapshot:warehouse.operational_reconciliation.run,warehouse.operational_reconciliation.view')
                ->name('warehouse.reports.reconciliation.run');
            Route::get('/{reportKey}/export', [WarehouseReportController::class, 'export'])
                ->middleware('permission_or_snapshot:warehouse.reporting.export,warehouse.reporting.view')
                ->name('warehouse.reports.export');
            Route::get('/{reportKey}', [WarehouseReportController::class, 'show'])
                ->middleware('permission_or_snapshot:warehouse.reporting.view')
                ->name('warehouse.reports.show');
        });
    });
