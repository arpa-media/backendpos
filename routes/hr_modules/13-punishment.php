<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPunishmentController;
use Illuminate\Support\Facades\Route;

Route::get('/punishments/references',[HrPunishmentController::class,'references'])->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view');
Route::get('/punishments/summary',[HrPunishmentController::class,'summary'])->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view');
Route::get('/punishments/violations',[HrPunishmentController::class,'violations'])->middleware('permission_or_snapshot:hr.punishment.view');
Route::post('/punishments/violations/report',[HrPunishmentController::class,'reportViolation'])->middleware('permission_or_snapshot:hr.punishment.create');
Route::put('/punishments/violations/{id}',[HrPunishmentController::class,'updateViolation'])->middleware('permission_or_snapshot:hr.punishment.update');
Route::post('/punishments/violations/{id}/submit',[HrPunishmentController::class,'submitViolation'])->middleware('permission_or_snapshot:hr.punishment.submit,hr.punishment.update');
Route::post('/punishments/violations/{id}/approve',[HrPunishmentController::class,'approveViolation'])->middleware('permission_or_snapshot:hr.punishment.approve,hr.punishment.update');
Route::post('/punishments/violations/{id}/reject',[HrPunishmentController::class,'rejectViolation'])->middleware('permission_or_snapshot:hr.punishment.approve,hr.punishment.update');
Route::delete('/punishments/violations/{id}',[HrPunishmentController::class,'deleteViolation'])->middleware('permission_or_snapshot:hr.punishment.delete');

Route::get('/punishments/recommendations',[HrPunishmentController::class,'recommendations'])->middleware('permission_or_snapshot:hr.punishment.view');
Route::post('/punishments/recommendations/{id}/convert',[HrPunishmentController::class,'convertRecommendation'])->middleware('permission_or_snapshot:hr.sp.create,hr.punishment.update');
Route::post('/punishments/recommendations/{id}/dismiss',[HrPunishmentController::class,'dismissRecommendation'])->middleware('permission_or_snapshot:hr.punishment.update');

Route::get('/punishments/warning-letters',[HrPunishmentController::class,'warningLetters'])->middleware('permission_or_snapshot:hr.sp.view,hr.punishment.view');
Route::post('/punishments/warning-letters',[HrPunishmentController::class,'createWarningLetter'])->middleware('permission_or_snapshot:hr.sp.create,hr.punishment.create,hr.punishment.update');
Route::post('/punishments/warning-letters/{id}/submit',[HrPunishmentController::class,'submitWarningLetter'])->middleware('permission_or_snapshot:hr.sp.submit,hr.sp.create,hr.punishment.update');
Route::post('/punishments/warning-letters/{id}/approve',[HrPunishmentController::class,'approveWarningLetter'])->middleware('permission_or_snapshot:hr.sp.approve');
Route::post('/punishments/warning-letters/{id}/reject',[HrPunishmentController::class,'rejectWarningLetter'])->middleware('permission_or_snapshot:hr.sp.approve');

Route::get('/punishments/rules',[HrPunishmentController::class,'rules'])->middleware('permission_or_snapshot:hr.punishment.view');
Route::post('/punishments/rules',[HrPunishmentController::class,'storeRule'])->middleware('permission_or_snapshot:hr.punishment.update');
Route::put('/punishments/rules/{id}',[HrPunishmentController::class,'updateRule'])->middleware('permission_or_snapshot:hr.punishment.update');
Route::post('/punishments/automation/sweep',[HrPunishmentController::class,'sweep'])->middleware('permission_or_snapshot:hr.punishment.update');
