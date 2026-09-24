<?php

use App\Http\Controllers\Api\V1\Console\ReportingControlCenterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api','auth:sanctum','permission_or_snapshot:console.control_center.view'])
    ->prefix('api/v1/console/control-center')->group(function(): void {
        Route::get('/', [ReportingControlCenterController::class,'dashboard'])->name('console.control-center.dashboard');
        Route::get('/months/{month}', [ReportingControlCenterController::class,'month'])->where('month','\d{4}-\d{2}')->name('console.control-center.month');
        Route::post('/months/{month}/prepare', [ReportingControlCenterController::class,'prepareMonth'])->where('month','\d{4}-\d{2}')->name('console.control-center.month.prepare');
        Route::post('/engine/run-now', [ReportingControlCenterController::class,'runEngineNow'])->name('console.control-center.engine.run-now');
        Route::post('/engine/test-worker', [ReportingControlCenterController::class,'testWorker'])->name('console.control-center.engine.test-worker');
        Route::post('/preview', [ReportingControlCenterController::class,'preview'])->name('console.control-center.preview');
        Route::post('/runs', [ReportingControlCenterController::class,'start'])->name('console.control-center.start');
        Route::post('/runs/{run}/pause', [ReportingControlCenterController::class,'pause'])->name('console.control-center.pause');
        Route::post('/runs/{run}/resume', [ReportingControlCenterController::class,'resume'])->name('console.control-center.resume');
        Route::post('/runs/{run}/cancel', [ReportingControlCenterController::class,'cancel'])->name('console.control-center.cancel');
        Route::post('/runs/{run}/retry-failed', [ReportingControlCenterController::class,'retryFailed'])->name('console.control-center.retry-failed');
        Route::put('/settings', [ReportingControlCenterController::class,'settings'])->name('console.control-center.settings');
    });
