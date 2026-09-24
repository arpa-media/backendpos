<?php

use App\Http\Controllers\Api\V1\HumanResource\HrCareerRecoveryAdminController;
use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentController;
use Illuminate\Support\Facades\Route;

// Dedicated Applicant Register endpoints keep the new Access Matrix menu isolated
// from Vacancy/Recruitment update permissions. Legacy endpoints remain untouched.
Route::get('/recruitment-applicant-register/registration-requests', [HrRecruitmentController::class, 'registrationRequests'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.view')
    ->name('hr.recruitment.applicant-register.registration.index');
Route::post('/recruitment-applicant-register/registration-requests/{id}/review', [HrRecruitmentController::class, 'reviewRegistration'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.manage')
    ->name('hr.recruitment.applicant-register.registration.review');
Route::get('/recruitment-applicant-register/password-reset-requests', [HrCareerRecoveryAdminController::class, 'passwordResetRequests'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.view')
    ->name('hr.recruitment.applicant-register.password-reset.index');
Route::post('/recruitment-applicant-register/password-reset-requests/{id}/review', [HrCareerRecoveryAdminController::class, 'reviewPasswordReset'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.manage')
    ->name('hr.recruitment.applicant-register.password-reset.review');
Route::post('/recruitment-applicant-register/bulk-approve', [HrCareerRecoveryAdminController::class, 'bulkApprove'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.manage')
    ->name('hr.recruitment.applicant-register.bulk-approve');
