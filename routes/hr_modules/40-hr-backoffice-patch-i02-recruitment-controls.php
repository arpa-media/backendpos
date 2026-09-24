<?php

use App\Http\Controllers\Api\V1\HumanResource\HrBackofficePatchIteration02Controller;
use Illuminate\Support\Facades\Route;

// Iteration 02 is additive. It intentionally does not overwrite I06/I08 route files.
Route::prefix('/recruitment-applicant-register')->group(function (): void {
    Route::post('/accounts/bulk-delete', [HrBackofficePatchIteration02Controller::class, 'bulkDeleteCareerAccounts'])
        ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.delete')
        ->name('hr.recruitment.applicant-register.account.bulk-delete');

    Route::post('/accounts/{accountId}/delete', [HrBackofficePatchIteration02Controller::class, 'deleteCareerAccount'])
        ->middleware('permission_or_snapshot:hr.recruitment.applicant_register.delete')
        ->name('hr.recruitment.applicant-register.account.delete');
});

Route::post('/recruitment-presence-i08/bulk-open', [HrBackofficePatchIteration02Controller::class, 'bulkOpenRecruitmentPresence'])
    ->middleware('permission_or_snapshot:hr.recruitment.interview.update')
    ->name('hr.recruitment.presence.bulk-open');
