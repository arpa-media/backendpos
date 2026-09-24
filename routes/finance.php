<?php

use App\Http\Controllers\Api\V1\Finance\FinanceFoundationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/foundation/context', [FinanceFoundationController::class, 'context'])
            ->name('finance.foundation.context');
    });
