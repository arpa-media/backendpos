<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentInterviewController;
use Illuminate\Support\Facades\Route;

// Iterasi 17: Interview uses its own Access Matrix menu. Do not fall back to
// hr.recruitment.update, otherwise revoking the Interview menu would not
// actually revoke access while the user still has Recruitment edit rights.
Route::get('/recruitment-interviews',[HrRecruitmentInterviewController::class,'index'])
    ->middleware('permission_or_snapshot:hr.recruitment.interview.view');
Route::get('/recruitments/applications/{id}',[HrRecruitmentInterviewController::class,'show'])
    ->middleware('permission_or_snapshot:hr.recruitment.interview.view');
Route::post('/recruitments/applications/{id}/call-interview',[HrRecruitmentInterviewController::class,'callInterview'])
    ->middleware('permission_or_snapshot:hr.recruitment.interview.create');
Route::post('/recruitments/applications/{id}/interviews',[HrRecruitmentInterviewController::class,'record'])
    ->middleware('permission_or_snapshot:hr.recruitment.interview.update');
Route::post('/recruitments/applications/{id}/hire',[HrRecruitmentInterviewController::class,'hire'])
    ->middleware('permission_or_snapshot:hr.recruitment.hire,hr.recruitment.interview.update');
Route::get('/recruitments/applications/{id}/cv',[HrRecruitmentInterviewController::class,'cv'])
    ->middleware('permission_or_snapshot:hr.recruitment.career_document.view,hr.recruitment.interview.update');
