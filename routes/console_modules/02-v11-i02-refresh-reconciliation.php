<?php

use App\Http\Controllers\Api\V1\Console\ReportingRefreshReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', 'permission_or_snapshot:console.control_center.view'])
    ->prefix('api/v1/console/control-center/reconciliation')
    ->group(function (): void {
        Route::post('/months/{month}/preview', [ReportingRefreshReconciliationController::class, 'preview'])
            ->where('month', '\d{4}-\d{2}')
            ->name('console.control-center.reconciliation.preview.v11-i02');
        Route::post('/months/{month}/resolve', [ReportingRefreshReconciliationController::class, 'resolve'])
            ->where('month', '\d{4}-\d{2}')
            ->name('console.control-center.reconciliation.resolve.v11-i02');
        Route::get('/runs/{run}', [ReportingRefreshReconciliationController::class, 'show'])
            ->name('console.control-center.reconciliation.show.v11-i02');
    });
