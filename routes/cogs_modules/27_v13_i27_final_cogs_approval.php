<?php

use App\Http\Controllers\Api\V1\Cogs\FinalCogsV13I27Controller;
use Illuminate\Support\Facades\Route;

// Uses established COGS Stock Variance menu; no new navigation item or special role grants.
Route::prefix('api/v1/cogs/final-hpp-v13-i27')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/', [FinalCogsV13I27Controller::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view');
        Route::get('/{id}', [FinalCogsV13I27Controller::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view');
        Route::get('/{id}/audit', [FinalCogsV13I27Controller::class, 'audit'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view');
        Route::post('/prepare', [FinalCogsV13I27Controller::class, 'prepare'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.update');
        // Approval requires the dedicated COGS calculation permission AND a different user ID.
        Route::post('/{id}/approve', [FinalCogsV13I27Controller::class, 'approve'])
            ->middleware('permission_or_snapshot:cogs.calculation.update');
        Route::post('/{id}/reject', [FinalCogsV13I27Controller::class, 'reject'])
            ->middleware('permission_or_snapshot:cogs.calculation.update');
    });
