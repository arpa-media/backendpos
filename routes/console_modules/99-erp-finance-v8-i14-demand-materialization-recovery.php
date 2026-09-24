<?php

use App\Http\Controllers\Api\V1\Console\ReportingControlCenterController;
use App\Http\Controllers\Api\V1\Console\ReportingMaterializationRecoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
    Route::get('/console/control-center/status', [ReportingControlCenterController::class, 'status'])
        ->middleware('throttle:30,1')
        ->name('console.control-center.status.i14');

    Route::post('/reporting/materialization/recovery', [ReportingMaterializationRecoveryController::class, 'recover'])
        ->middleware('throttle:6,1')
        ->name('reporting.materialization.recovery.i14');

    Route::post('/reporting/materialization/recovery/status', [ReportingMaterializationRecoveryController::class, 'status'])
        ->middleware('throttle:30,1')
        ->name('reporting.materialization.recovery-status.i14');
});
