<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceApprovalController;
use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceDataController;
use App\Http\Controllers\Api\V1\HumanResource\HrManualAttendanceController;
use Illuminate\Support\Facades\Route;

Route::get('/attendance-data/options', [HrAttendanceDataController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.attendance.data.view')->name('hr.attendance-data.options');
Route::get('/attendance-data', [HrAttendanceDataController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.attendance.data.view')->name('hr.attendance-data.index');


Route::get('/attendance-approvals/manual/options', [HrManualAttendanceController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.manual.options');
Route::get('/attendance-approvals/manual/context', [HrManualAttendanceController::class, 'context'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.manual.context');
Route::get('/attendance-approvals/manual/open', [HrManualAttendanceController::class, 'open'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.manual.open');
Route::post('/attendance-approvals/manual', [HrManualAttendanceController::class, 'store'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.manual.store');
Route::patch('/attendance-approvals/manual/{attendanceId}', [HrManualAttendanceController::class, 'update'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.manual.update');

Route::get('/attendance-approvals/absence/options', [HrAttendanceApprovalController::class, 'absenceOptions'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.absence.options');
Route::get('/attendance-approvals/absence', [HrAttendanceApprovalController::class, 'absenceIndex'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.view')->name('hr.attendance-approvals.absence.index');
Route::post('/attendance-approvals/absence/{attendanceId}/spv', [HrAttendanceApprovalController::class, 'absenceSpv'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.spv')->name('hr.attendance-approvals.absence.spv');
Route::post('/attendance-approvals/absence/{attendanceId}/hrd', [HrAttendanceApprovalController::class, 'absenceHrd'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.hrd')->name('hr.attendance-approvals.absence.hrd');
Route::post('/attendance-approvals/absence/{attendanceId}/override-spv', [HrAttendanceApprovalController::class, 'absenceOverrideSpv'])
    ->middleware('permission_or_snapshot:hr.attendance.approval.override_spv')->name('hr.attendance-approvals.absence.override-spv');

Route::get('/attendance-approvals/duty/options', [HrAttendanceApprovalController::class, 'dutyOptions'])
    ->middleware('permission_or_snapshot:hr.attendance.duty.view')->name('hr.attendance-approvals.duty.options');
Route::get('/attendance-approvals/duty', [HrAttendanceApprovalController::class, 'dutyIndex'])
    ->middleware('permission_or_snapshot:hr.attendance.duty.view')->name('hr.attendance-approvals.duty.index');
Route::post('/attendance-approvals/duty/{attendanceId}/spv', [HrAttendanceApprovalController::class, 'dutySpv'])
    ->middleware('permission_or_snapshot:hr.attendance.duty.spv')->name('hr.attendance-approvals.duty.spv');
Route::post('/attendance-approvals/duty/{attendanceId}/hrd', [HrAttendanceApprovalController::class, 'dutyHrd'])
    ->middleware('permission_or_snapshot:hr.attendance.duty.hrd')->name('hr.attendance-approvals.duty.hrd');
Route::post('/attendance-approvals/duty/{attendanceId}/override-spv', [HrAttendanceApprovalController::class, 'dutyOverrideSpv'])
    ->middleware('permission_or_snapshot:hr.attendance.duty.override_spv')->name('hr.attendance-approvals.duty.override-spv');
