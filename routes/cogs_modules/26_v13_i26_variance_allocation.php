<?php

use App\Http\Controllers\Api\V1\Cogs\VarianceAllocationV13I26Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/stock-variance-v13-i26')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/allocation-preview', [VarianceAllocationV13I26Controller::class, 'preview'])
            ->middleware('permission_or_snapshot:cogs.stock_variance.view')
            ->name('cogs.stock-variance.v13-i26.allocation-preview');
    });
