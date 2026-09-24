<?php

use App\Http\Controllers\Api\V1\HumanResource\HrDailyReportManualAttendanceI16Controller;
use Illuminate\Support\Facades\Route;

$manualAttendanceI16Guard = 'permission_or_snapshot:hr.attendance.daily-report.manual.create,hr.attendance.recalculate';

Route::get('/attendance-reports/manual-i16/options', [HrDailyReportManualAttendanceI16Controller::class, 'options'])
    ->middleware($manualAttendanceI16Guard)
    ->name('hr.attendance-reports.manual-i16.options');

Route::get('/attendance-reports/manual-i16/context', [HrDailyReportManualAttendanceI16Controller::class, 'context'])
    ->middleware($manualAttendanceI16Guard)
    ->name('hr.attendance-reports.manual-i16.context');

Route::post('/attendance-reports/manual-i16', [HrDailyReportManualAttendanceI16Controller::class, 'store'])
    ->middleware([$manualAttendanceI16Guard, 'throttle:30,1'])
    ->name('hr.attendance-reports.manual-i16.store');
