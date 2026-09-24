<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentMasterResetI15Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('/recruitment-master-i09')->group(function (): void {
    Route::get('/reset-preview', [HrRecruitmentMasterResetI15Controller::class, 'preview'])
        ->middleware('permission_or_snapshot:hr.recruitment.reset,hr.recruitment.update')
        ->name('hr.i15.recruitment-reset.preview');

    Route::post('/reset', [HrRecruitmentMasterResetI15Controller::class, 'reset'])
        ->middleware(['permission_or_snapshot:hr.recruitment.reset,hr.recruitment.update', 'throttle:5,1'])
        ->name('hr.i15.recruitment-reset.run');
});
