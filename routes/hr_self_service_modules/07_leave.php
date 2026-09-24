<?php

use App\Http\Controllers\Api\V1\HumanResource\HrLeaveSelfController;
use App\Services\HrUserDashboardService;
use App\Services\HumanResource\HrUserDashboardLeaveService;
use Illuminate\Support\Facades\Route;

// Iteration 07 decorates, rather than overwrites, the Iteration 03 dashboard service.
app()->bind(HrUserDashboardService::class, HrUserDashboardLeaveService::class);

Route::prefix('api/v1/human-resource/self')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/leave-requests', [HrLeaveSelfController::class, 'index'])
            ->middleware('permission_or_snapshot:hr.leave.self.view')->name('hr.self.leave.index');
        Route::post('/leave-requests', [HrLeaveSelfController::class, 'store'])
            ->middleware('permission_or_snapshot:hr.leave.self.create')->name('hr.self.leave.store');
        Route::post('/leave-requests/{id}/cancel', [HrLeaveSelfController::class, 'cancel'])
            ->middleware('permission_or_snapshot:hr.leave.self.cancel')->name('hr.self.leave.cancel');
    });
