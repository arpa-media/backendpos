<?php

use App\Http\Controllers\Api\V1\Console\MaintenanceController;
use App\Http\Controllers\Api\V1\Console\MaterializationDemandRecoveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/console')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('maintenance/status', [MaintenanceController::class, 'status'])
        ->middleware('throttle:120,1')
        ->name('console.maintenance.status.hotfix-i01');
    Route::get('maintenance', [MaintenanceController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('console.maintenance.show.hotfix-i01');
    Route::put('maintenance', [MaintenanceController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('console.maintenance.update.hotfix-i01');
    Route::post('materialization/demand-recovery', MaterializationDemandRecoveryController::class)
        ->middleware('throttle:6,1')
        ->name('console.materialization.demand-recovery.hotfix-i01');
});
