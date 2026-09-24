<?php

use App\Http\Controllers\Api\V1\HumanResource\HrCareerRecoveryAdminController;
use Illuminate\Support\Facades\Route;

// Route memakai prefix career-access agar tidak tertangkap route legacy /recruitments/{id}.
Route::get('/career-access/password-reset-requests', [HrCareerRecoveryAdminController::class, 'passwordResetRequests'])
    ->middleware('permission_or_snapshot:hr.recruitment.applicant.view,hr.recruitment.update');
Route::post('/career-access/password-reset-requests/{id}/review', [HrCareerRecoveryAdminController::class, 'reviewPasswordReset'])
    ->middleware('permission_or_snapshot:hr.recruitment.password_reset.approve,hr.recruitment.update');
Route::post('/career-access/bulk-approve', [HrCareerRecoveryAdminController::class, 'bulkApprove'])
    ->middleware('permission_or_snapshot:hr.recruitment.registration.bulk_approve,hr.recruitment.update');
