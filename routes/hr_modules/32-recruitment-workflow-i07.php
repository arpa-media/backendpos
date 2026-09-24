<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentWorkflowI07Controller;
use Illuminate\Support\Facades\Route;

// HR V5 Refinement I07.
// All actions stay under the existing Interview Access Matrix menu.
Route::prefix('/recruitment-workflow')->group(function (): void {
    Route::get('/', [HrRecruitmentWorkflowI07Controller::class, 'index'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.view');
    Route::get('/applications/{id}', [HrRecruitmentWorkflowI07Controller::class, 'show'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.view');
    Route::get('/applications/{id}/cv', [HrRecruitmentWorkflowI07Controller::class, 'cv'])
        ->middleware('permission_or_snapshot:hr.recruitment.career_document.view,hr.recruitment.interview.update');

    Route::post('/applications/{id}/interview/call', [HrRecruitmentWorkflowI07Controller::class, 'callInterview'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.create,hr.recruitment.interview.update');
    Route::post('/applications/{id}/interview/result', [HrRecruitmentWorkflowI07Controller::class, 'recordInterview'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');

    Route::post('/applications/{id}/practical/call', [HrRecruitmentWorkflowI07Controller::class, 'callPractical'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
    Route::post('/applications/{id}/practical/result', [HrRecruitmentWorkflowI07Controller::class, 'recordPractical'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');

    Route::post('/applications/{id}/onboarding-contract/call', [HrRecruitmentWorkflowI07Controller::class, 'callOnboardingOrContract'])
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
    Route::post('/applications/{id}/complete', [HrRecruitmentWorkflowI07Controller::class, 'complete'])
        ->middleware('permission_or_snapshot:hr.recruitment.hire,hr.recruitment.interview.update');

    Route::post('/applications/{id}/schedules/{flow}/reschedule', [HrRecruitmentWorkflowI07Controller::class, 'reschedule'])
        ->where('flow', 'interview|practical|onboarding_contract')
        ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
});
