<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentPresenceI08Controller;
use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentWorkflowI07Controller;
use App\Http\Middleware\EnsureRecruitmentPresenceI08;
use Illuminate\Support\Facades\Route;

// I08 remains under the existing Interview Access Matrix item.
Route::prefix('/recruitment-presence-i08')->group(function (): void {
    Route::get('/', [HrRecruitmentPresenceI08Controller::class, 'index'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.view');
    Route::post('/schedules/{scheduleId}/open', [HrRecruitmentPresenceI08Controller::class, 'open'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
    Route::post('/schedules/{scheduleId}/close', [HrRecruitmentPresenceI08Controller::class, 'close'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
});


// Route hardening loaded after I07: the same mutation URIs are re-registered with
// presence enforcement. No I07 file is overwritten.
Route::post('/recruitment-workflow/applications/{id}/interview/result', [HrRecruitmentWorkflowI07Controller::class, 'recordInterview'])
    ->middleware(['permission_or_snapshot:hr.recruitment.interview.update', EnsureRecruitmentPresenceI08::class.':interview']);
Route::post('/recruitment-workflow/applications/{id}/practical/result', [HrRecruitmentWorkflowI07Controller::class, 'recordPractical'])
    ->middleware(['permission_or_snapshot:hr.recruitment.interview.update', EnsureRecruitmentPresenceI08::class.':practical']);
Route::post('/recruitment-workflow/applications/{id}/complete', [HrRecruitmentWorkflowI07Controller::class, 'complete'])
    ->middleware(['permission_or_snapshot:hr.recruitment.hire,hr.recruitment.interview.update', EnsureRecruitmentPresenceI08::class.':onboarding_contract']);
