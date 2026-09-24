<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceDataController;
use App\Http\Controllers\Api\V1\HumanResource\HrAttendanceReportController;
use Illuminate\Support\Facades\Route;

Route::get('/attendance-data/export', [HrAttendanceDataController::class, 'export'])
    ->middleware('permission_or_snapshot:hr.attendance.data.export')
    ->name('hr.attendance-data.export');

Route::post('/attendance-reports/recalculate', [HrAttendanceReportController::class, 'recalculate'])
    ->middleware('permission_or_snapshot:hr.attendance.recalculate')
    ->name('hr.attendance-reports.recalculate');
