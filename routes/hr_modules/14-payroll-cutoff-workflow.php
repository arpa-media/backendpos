<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPayrollCutoffWorkflowController;
use Illuminate\Support\Facades\Route;

Route::post('/payroll/cutoffs/{cutoff}/submit',[HrPayrollCutoffWorkflowController::class,'submit'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.submit,hr.payroll.cutoff.update')->name('hr.payroll.i14.submit');
Route::post('/payroll/cutoffs/{cutoff}/reopen',[HrPayrollCutoffWorkflowController::class,'reopen'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.reopen,hr.payroll.cutoff.update')->name('hr.payroll.i14.reopen');
Route::get('/payroll/cutoffs/{cutoff}/adjustments/export',[HrPayrollCutoffWorkflowController::class,'exportAdjustments'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.export,hr.payroll.cutoff.view')->name('hr.payroll.i14.adjustments.export');
Route::post('/payroll/cutoffs/{cutoff}/adjustments/import',[HrPayrollCutoffWorkflowController::class,'importAdjustments'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.import,hr.payroll.cutoff.update')->name('hr.payroll.i14.adjustments.import');

// V13 I10: BPJS is frozen in HR before finance submission.
Route::get('/payroll/cutoffs/{cutoff}/bpjs/export',[HrPayrollCutoffWorkflowController::class,'exportBpjs'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.export,hr.payroll.cutoff.view')->name('hr.payroll.v13i10.bpjs.export');
Route::post('/payroll/cutoffs/{cutoff}/bpjs/import',[HrPayrollCutoffWorkflowController::class,'importBpjs'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.import,hr.payroll.cutoff.update')->name('hr.payroll.v13i10.bpjs.import');
