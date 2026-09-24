<?php

use App\Http\Controllers\Api\V1\HumanResource\HrOvertimeDailyReportManualI08Controller;
use Illuminate\Support\Facades\Route;

$dailyOvertimeI08Guard = 'permission_or_snapshot:hr.attendance.daily-report.manual.create,hr.attendance.recalculate,hr.attendance.overtime.create,hr.attendance.overtime.update';

Route::post('/attendance-reports/overtime-manual-i08', [HrOvertimeDailyReportManualI08Controller::class, 'store'])
    ->middleware([$dailyOvertimeI08Guard, 'throttle:60,1'])
    ->name('hr.attendance-reports.overtime-manual-i08.store');
