<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/reset')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairResetController::class, 'meta'])
            ->middleware('permission_or_snapshot:ga.reset.view')
            ->name('api.v1.general-affair.reset.meta');
        Route::post('/preview', [GeneralAffairResetController::class, 'preview'])
            ->middleware('permission_or_snapshot:ga.reset.view')
            ->name('api.v1.general-affair.reset.preview');
        Route::post('/execute', [GeneralAffairResetController::class, 'execute'])
            ->middleware('permission_or_snapshot:ga.reset.execute')
            ->name('api.v1.general-affair.reset.execute');
    });
