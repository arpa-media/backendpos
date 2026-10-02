<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairContextController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/context', GeneralAffairContextController::class)
            ->middleware('permission_or_snapshot:ga.dashboard.view')
            ->name('general-affair.i01.context');
    });
