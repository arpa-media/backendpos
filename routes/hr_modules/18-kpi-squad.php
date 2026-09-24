<?php

use App\Http\Controllers\Api\V1\HumanResource\HrKpiSquadController;
use Illuminate\Support\Facades\Route;

Route::get('/kpi-squad/references',[HrKpiSquadController::class,'references'])->middleware('permission_or_snapshot:hr.kpi.squad.view')->name('hr.kpi.squad.references');
Route::get('/kpi-squad/criteria',[HrKpiSquadController::class,'criteria'])->middleware('permission_or_snapshot:hr.kpi.squad.view')->name('hr.kpi.squad.criteria');
Route::post('/kpi-squad/criteria',[HrKpiSquadController::class,'storeCriterion'])->middleware('permission_or_snapshot:hr.kpi.squad.update')->name('hr.kpi.squad.criteria.store');
Route::put('/kpi-squad/criteria/{id}',[HrKpiSquadController::class,'updateCriterion'])->middleware('permission_or_snapshot:hr.kpi.squad.update')->name('hr.kpi.squad.criteria.update');
Route::get('/kpi-squad/daily',[HrKpiSquadController::class,'daily'])->middleware('permission_or_snapshot:hr.kpi.squad.view')->name('hr.kpi.squad.daily');
Route::put('/kpi-squad/daily',[HrKpiSquadController::class,'saveDaily'])->middleware('permission_or_snapshot:hr.kpi.squad.input,hr.kpi.squad.update')->name('hr.kpi.squad.daily.save');
Route::post('/kpi-squad/daily/{id}/lock',[HrKpiSquadController::class,'lock'])->middleware('permission_or_snapshot:hr.kpi.squad.lock,hr.kpi.squad.update')->name('hr.kpi.squad.daily.lock');
Route::post('/kpi-squad/daily/{id}/reopen',[HrKpiSquadController::class,'reopen'])->middleware('permission_or_snapshot:hr.kpi.squad.reopen')->name('hr.kpi.squad.daily.reopen');
Route::get('/kpi-squad/period',[HrKpiSquadController::class,'period'])->middleware('permission_or_snapshot:hr.kpi.squad.view')->name('hr.kpi.squad.period');
Route::post('/kpi-squad/violation',[HrKpiSquadController::class,'reportViolation'])->middleware('permission_or_snapshot:hr.kpi.squad.violation.create,hr.kpi.squad.update')->name('hr.kpi.squad.violation');
Route::get('/kpi-squad/export/daily',[HrKpiSquadController::class,'exportDaily'])->middleware('permission_or_snapshot:hr.kpi.squad.export,hr.kpi.squad.view')->name('hr.kpi.squad.export.daily');
Route::get('/kpi-squad/export/period',[HrKpiSquadController::class,'exportPeriod'])->middleware('permission_or_snapshot:hr.kpi.squad.export,hr.kpi.squad.view')->name('hr.kpi.squad.export.period');
Route::post('/kpi-squad/import',[HrKpiSquadController::class,'import'])->middleware('permission_or_snapshot:hr.kpi.squad.import,hr.kpi.squad.update')->name('hr.kpi.squad.import');
