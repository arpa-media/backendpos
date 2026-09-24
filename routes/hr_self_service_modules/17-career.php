<?php

use App\Http\Controllers\Api\V1\Career\HrCareerAuthController;
use App\Http\Controllers\Api\V1\Career\HrCareerPortalController;
use App\Http\Middleware\AuthenticateCareerAccount;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/career')->middleware(['api'])->group(function (): void {
    Route::post('/register',[HrCareerAuthController::class,'register'])->middleware('throttle:10,1');
    Route::post('/login',[HrCareerAuthController::class,'login'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum',AuthenticateCareerAccount::class])->group(function (): void {
        Route::get('/me',[HrCareerAuthController::class,'me']);
        Route::post('/logout',[HrCareerAuthController::class,'logout']);
        Route::post('/change-password',[HrCareerAuthController::class,'changePassword']);
        Route::get('/dashboard',[HrCareerPortalController::class,'dashboard']);
        Route::get('/profile',[HrCareerPortalController::class,'profile']);
        Route::put('/profile',[HrCareerPortalController::class,'updateProfile']);
        Route::post('/cv',[HrCareerPortalController::class,'uploadCv']);
        Route::get('/cv',[HrCareerPortalController::class,'downloadCv']);
        Route::get('/recruitments',[HrCareerPortalController::class,'recruitments']);
        Route::post('/recruitments/{id}/apply',[HrCareerPortalController::class,'apply']);
        Route::get('/applications',[HrCareerPortalController::class,'applications']);
        Route::get('/applications/{id}',[HrCareerPortalController::class,'application']);
    });
});
