<?php

use App\Http\Controllers\Api\V1\HumanResource\HrOvertimeBackofficeI06Controller;
use Illuminate\Support\Facades\Route;

Route::get('/overtimes-i06/options', [HrOvertimeBackofficeI06Controller::class, 'options'])
    ->middleware('permission_or_snapshot:hr.attendance.overtime.view,hr.attendance.overtime.create,hr.attendance.overtime.update')
    ->name('hr.overtime-i06.options');
Route::get('/overtimes-i06', [HrOvertimeBackofficeI06Controller::class, 'index'])
    ->middleware('permission_or_snapshot:hr.attendance.overtime.view')
    ->name('hr.overtime-i06.index');
Route::post('/overtimes-i06/manual', [HrOvertimeBackofficeI06Controller::class, 'manual'])
    ->middleware(['permission_or_snapshot:hr.attendance.overtime.create,hr.attendance.overtime.update', 'throttle:60,1'])
    ->name('hr.overtime-i06.manual');
