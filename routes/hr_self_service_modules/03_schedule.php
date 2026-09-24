<?php

use App\Http\Controllers\Api\V1\HumanResource\HrScheduleSelfController;
use App\Services\HrUserDashboardService;
use App\Services\HumanResource\HrUserDashboardScheduleService;
use Illuminate\Support\Facades\Route;

// Decorate Dashboard User without changing any Iterasi 02 file.
app()->bind(HrUserDashboardService::class, HrUserDashboardScheduleService::class);

Route::prefix('api/v1/human-resource/self')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/schedule', [HrScheduleSelfController::class, 'index'])
            ->middleware('permission_or_snapshot:hr.schedule.self.view')
            ->name('hr.self.schedule.index');
    });
