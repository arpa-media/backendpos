<?php

use App\Http\Controllers\Api\V1\Console\MaintenanceController;
use App\Http\Controllers\Api\V1\Console\MaterializationDemandRecoveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/console')->middleware('auth:sanctum')->group(function (): void {
    Route::get('maintenance/status', [MaintenanceController::class, 'status']);
    Route::get('maintenance', [MaintenanceController::class, 'show']);
    Route::put('maintenance', [MaintenanceController::class, 'update']);
    Route::post('materialization/demand-recovery', MaterializationDemandRecoveryController::class);
});
