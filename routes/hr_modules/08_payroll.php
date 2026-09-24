<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPayrollController;
use Illuminate\Support\Facades\Route;

Route::get('/payroll/options',[HrPayrollController::class,'options'])->middleware('permission_or_snapshot:hr.payroll.projection.view,hr.payroll.cutoff.view')->name('hr.payroll.options');
Route::get('/payroll/projection',[HrPayrollController::class,'projection'])->middleware('permission_or_snapshot:hr.payroll.projection.view')->name('hr.payroll.projection');
Route::get('/payroll/cutoffs',[HrPayrollController::class,'index'])->middleware('permission_or_snapshot:hr.payroll.cutoff.view')->name('hr.payroll.cutoffs.index');
Route::post('/payroll/cutoffs',[HrPayrollController::class,'store'])->middleware('permission_or_snapshot:hr.payroll.cutoff.create')->name('hr.payroll.cutoffs.store');
Route::get('/payroll/cutoffs/{cutoff}',[HrPayrollController::class,'show'])->middleware('permission_or_snapshot:hr.payroll.cutoff.view')->name('hr.payroll.cutoffs.show');
Route::put('/payroll/cutoffs/{cutoff}/slips/{slip}',[HrPayrollController::class,'updateSlip'])->middleware('permission_or_snapshot:hr.payroll.cutoff.update')->name('hr.payroll.slips.update');
Route::post('/payroll/cutoffs/{cutoff}/finalize',[HrPayrollController::class,'finalize'])->middleware('permission_or_snapshot:hr.payroll.cutoff.finalize,hr.payroll.cutoff.update')->name('hr.payroll.cutoffs.finalize');
Route::delete('/payroll/cutoffs/{cutoff}',[HrPayrollController::class,'destroy'])->middleware('permission_or_snapshot:hr.payroll.cutoff.delete')->name('hr.payroll.cutoffs.destroy');
