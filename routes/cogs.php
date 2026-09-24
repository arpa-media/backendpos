<?php

use App\Http\Controllers\Api\V1\Cogs\CogsDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/dashboard', [CogsDashboardController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.dashboard.view')
            ->name('cogs.dashboard');
    });
