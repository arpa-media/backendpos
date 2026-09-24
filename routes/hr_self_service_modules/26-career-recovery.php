<?php

use App\Http\Controllers\Api\V1\Career\HrCareerAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/career')->middleware(['api'])->group(function (): void {
    Route::post('/password-reset-request', [HrCareerAuthController::class, 'requestPasswordReset'])->middleware('throttle:6,1');
});
