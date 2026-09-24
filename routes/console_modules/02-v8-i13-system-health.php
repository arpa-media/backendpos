<?php

use App\Http\Controllers\Api\V1\Console\SystemHealthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/console/system-health')
    ->group(function (): void {
        Route::get('/', [SystemHealthController::class, 'show'])->name('console.system-health.show');
    });
