<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceSelfController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/attendance')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/engine/context', [HrAttendanceSelfController::class, 'context'])
            ->name('attendance.engine.context');
        Route::post('/engine/check-in', [HrAttendanceSelfController::class, 'checkIn'])
            ->name('attendance.engine.checkin');
        Route::post('/engine/check-out', [HrAttendanceSelfController::class, 'checkOut'])
            ->name('attendance.engine.checkout');
    });
