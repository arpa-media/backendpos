<?php

use App\Http\Controllers\Api\V1\Cogs\CogsResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/reset')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/preview', [CogsResetController::class, 'preview'])
            ->middleware('permission_or_snapshot:cogs.reset.view')
            ->name('cogs.reset.preview');
        Route::post('/', [CogsResetController::class, 'reset'])
            ->middleware('permission_or_snapshot:cogs.reset.update')
            ->name('cogs.reset.run');
    });
