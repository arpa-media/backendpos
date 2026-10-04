<?php

use App\Http\Controllers\Api\V1\Console\ReportingSmartRepairController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', 'permission_or_snapshot:console.control_center.view'])
    ->prefix('api/v1/console/control-center/smart-repair')
    ->group(function (): void {
        Route::post('/months/{month}/plan', [ReportingSmartRepairController::class, 'plan'])
            ->where('month', '\d{4}-\d{2}')
            ->name('console.control-center.smart-repair.plan.v11-i03');
        Route::get('/runs/{run}', [ReportingSmartRepairController::class, 'show'])
            ->name('console.control-center.smart-repair.show.v11-i03');
    });
