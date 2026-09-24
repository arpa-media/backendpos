<?php

use App\Http\Controllers\Api\V1\Career\HrCareerRecruitmentPresenceI08Controller;
use App\Http\Middleware\AuthenticateCareerAccount;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/career')->middleware(['api', 'auth:sanctum', AuthenticateCareerAccount::class])->group(function (): void {
    Route::get('/recruitment-status-i08', [HrCareerRecruitmentPresenceI08Controller::class, 'status']);
    Route::post('/applications/{id}/presence/{flow}', [HrCareerRecruitmentPresenceI08Controller::class, 'presence'])
        ->where('flow', 'interview|practical|onboarding_contract')
        ->middleware('throttle:20,1');
});
