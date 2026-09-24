<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPayrollSlipEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/payroll/cutoffs/{cutoff}/slip-email-statuses', [HrPayrollSlipEmailController::class, 'payrollStatuses'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.view')->name('hr.payroll.i03.mail.status');
Route::get('/payroll/cutoffs/{cutoff}/slips/{slip}/pdf', [HrPayrollSlipEmailController::class, 'payrollPdf'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.view')->name('hr.payroll.i03.slip.pdf');
Route::post('/payroll/cutoffs/{cutoff}/slips/{slip}/email', [HrPayrollSlipEmailController::class, 'sendPayroll'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.email,hr.payroll.cutoff.update')->name('hr.payroll.i03.slip.email');

Route::get('/bonus-projections/{projectionId}/slip-email-statuses', [HrPayrollSlipEmailController::class, 'bonusStatuses'])
    ->middleware('permission_or_snapshot:hr.bonus.projection.view')->name('hr.bonus.i03.mail.status');
Route::get('/bonus-projections/{projectionId}/lines/{lineId}/slip.pdf', [HrPayrollSlipEmailController::class, 'bonusPdf'])
    ->middleware('permission_or_snapshot:hr.bonus.projection.view')->name('hr.bonus.i03.slip.pdf');
Route::post('/bonus-projections/{projectionId}/lines/{lineId}/email', [HrPayrollSlipEmailController::class, 'sendBonus'])
    ->middleware('permission_or_snapshot:hr.bonus.projection.email,hr.bonus.projection.recalculate,hr.bonus.projection.submit')->name('hr.bonus.i03.slip.email');

// I13: active connectivity/authentication check without exposing the SMTP secret.
Route::post('/payroll/slip-email-diagnostics', [HrPayrollSlipEmailController::class, 'diagnostics'])
    ->middleware('permission_or_snapshot:hr.payroll.cutoff.email,hr.payroll.cutoff.update,hr.bonus.projection.email,hr.bonus.projection.recalculate,hr.bonus.projection.submit')
    ->name('hr.payroll.i13.mail.diagnostics');
