<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/dashboard', GeneralAffairDashboardController::class)
            ->middleware('permission_or_snapshot:ga.dashboard.view')
            ->name('general-affair.i12.dashboard');
    });
