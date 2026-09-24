<?php

use App\Http\Controllers\Api\V1\HumanResource\HrRecruitmentController;
use Illuminate\Support\Facades\Route;

Route::get('/recruitments/references',[HrRecruitmentController::class,'references'])->middleware('permission_or_snapshot:hr.recruitment.view');
Route::get('/recruitments/registration-requests',[HrRecruitmentController::class,'registrationRequests'])->middleware('permission_or_snapshot:hr.recruitment.applicant.view,hr.recruitment.update');
Route::post('/recruitments/registration-requests/{id}/review',[HrRecruitmentController::class,'reviewRegistration'])->middleware('permission_or_snapshot:hr.recruitment.registration.approve,hr.recruitment.update');
Route::get('/recruitments',[HrRecruitmentController::class,'index'])->middleware('permission_or_snapshot:hr.recruitment.view');
Route::post('/recruitments',[HrRecruitmentController::class,'store'])->middleware('permission_or_snapshot:hr.recruitment.create');
Route::get('/recruitments/{id}',[HrRecruitmentController::class,'show'])->middleware('permission_or_snapshot:hr.recruitment.view');
Route::put('/recruitments/{id}',[HrRecruitmentController::class,'update'])->middleware('permission_or_snapshot:hr.recruitment.update');
Route::delete('/recruitments/{id}',[HrRecruitmentController::class,'destroy'])->middleware('permission_or_snapshot:hr.recruitment.delete');
Route::post('/recruitments/{id}/publish',[HrRecruitmentController::class,'publish'])->middleware('permission_or_snapshot:hr.recruitment.publish,hr.recruitment.update');
Route::post('/recruitments/{id}/close',[HrRecruitmentController::class,'close'])->middleware('permission_or_snapshot:hr.recruitment.publish,hr.recruitment.update');
Route::get('/recruitments/{id}/applicants',[HrRecruitmentController::class,'applicants'])->middleware('permission_or_snapshot:hr.recruitment.applicant.view,hr.recruitment.update');
