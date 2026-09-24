<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentMasterI09Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('/recruitment-master-i09')->group(function (): void {
    Route::get('/references', [HrRecruitmentMasterI09Controller::class, 'references'])
        ->middleware('permission_or_snapshot:hr.recruitment.view');
    Route::get('/preview', [HrRecruitmentMasterI09Controller::class, 'preview'])
        ->middleware('permission_or_snapshot:hr.recruitment.view');
    Route::get('/export', [HrRecruitmentMasterI09Controller::class, 'export'])
        ->middleware('permission_or_snapshot:hr.recruitment.view');
});
