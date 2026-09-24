<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceReportController;
use Illuminate\Support\Facades\Route;

Route::get('/attendance-reports/options', [HrAttendanceReportController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.attendance.daily-report.view,hr.attendance.late.view,hr.attendance.recap.view')
    ->name('hr.attendance-reports.options');
Route::get('/attendance-reports/daily', [HrAttendanceReportController::class, 'daily'])
    ->middleware('permission_or_snapshot:hr.attendance.daily-report.view')
    ->name('hr.attendance-reports.daily');
Route::get('/attendance-reports/late', [HrAttendanceReportController::class, 'late'])
    ->middleware('permission_or_snapshot:hr.attendance.late.view')
    ->name('hr.attendance-reports.late');
Route::get('/attendance-reports/recap', [HrAttendanceReportController::class, 'recap'])
    ->middleware('permission_or_snapshot:hr.attendance.recap.view')
    ->name('hr.attendance-reports.recap');
