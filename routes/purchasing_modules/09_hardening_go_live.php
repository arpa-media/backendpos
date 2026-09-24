<?php

use App\Http\Controllers\Api\V1\Purchasing\PurchasingGoLiveController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/go-live')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/catalogs', [PurchasingGoLiveController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:purchasing.go_live.view')
            ->name('purchasing.go-live.catalogs');

        Route::get('/runs', [PurchasingGoLiveController::class, 'index'])
            ->middleware('permission_or_snapshot:purchasing.go_live.view')
            ->name('purchasing.go-live.runs.index');

        Route::post('/run', [PurchasingGoLiveController::class, 'run'])
            ->middleware('permission_or_snapshot:purchasing.go_live.create,purchasing.go_live.run')
            ->name('purchasing.go-live.run');

        Route::get('/runs/{id}', [PurchasingGoLiveController::class, 'show'])
            ->middleware('permission_or_snapshot:purchasing.go_live.view')
            ->name('purchasing.go-live.runs.show');
    });
