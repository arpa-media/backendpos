<?php

use App\Http\Controllers\Api\V1\HumanResource\HrShiftScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/shift-schedules/options', [HrShiftScheduleController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.schedule.view')
    ->name('hr.shift-schedules.options');
Route::get('/shift-schedules', [HrShiftScheduleController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.schedule.view')
    ->name('hr.shift-schedules.index');
Route::post('/shift-schedules/bulk', [HrShiftScheduleController::class, 'storeBulk'])
    ->middleware('permission_or_snapshot:hr.schedule.create,hr.schedule.update,hr.schedule.delete')
    ->name('hr.shift-schedules.bulk-store');
Route::delete('/shift-schedules/{id}', [HrShiftScheduleController::class, 'destroy'])
    ->middleware('permission_or_snapshot:hr.schedule.delete')
    ->name('hr.shift-schedules.destroy');
