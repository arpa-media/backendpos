<?php

use App\Http\Controllers\Api\V1\Console\ReportingBackgroundRunnerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api','auth:sanctum','permission_or_snapshot:console.control_center.view'])
    ->prefix('api/v1/console/control-center')
    ->group(function (): void {
        Route::get('/runs/{run}', [ReportingBackgroundRunnerController::class, 'show'])
            ->name('console.control-center.runs.show.v11-i04');
        Route::post('/runs/{run}/kick', [ReportingBackgroundRunnerController::class, 'kick'])
            ->name('console.control-center.runs.kick.v11-i04');
    });
