<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceReportController;
use Illuminate\Support\Facades\Route;

Route::get('/attendance-reports/daily/export', [HrAttendanceReportController::class, 'dailyExport'])
    ->middleware('permission_or_snapshot:hr.attendance.daily-report.export')
    ->name('hr.attendance-reports.daily.export');

Route::get('/attendance-reports/late/export', [HrAttendanceReportController::class, 'lateExport'])
    ->middleware('permission_or_snapshot:hr.attendance.late.export')
    ->name('hr.attendance-reports.late.export');

Route::get('/attendance-reports/recap/export', [HrAttendanceReportController::class, 'recapExport'])
    ->middleware('permission_or_snapshot:hr.attendance.recap.export')
    ->name('hr.attendance-reports.recap.export');
