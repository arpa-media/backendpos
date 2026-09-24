<?php

use App\Http\Controllers\Api\V1\HumanResource\HrUserDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get('/human-resource/user-dashboard', [HrUserDashboardController::class, 'show']);
        Route::post('/human-resource/user-dashboard/profile', [HrUserDashboardController::class, 'updateProfile']);
    });
