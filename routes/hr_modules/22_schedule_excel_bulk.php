<?php

use App\Http\Controllers\Api\V1\HumanResource\HrShiftScheduleSpreadsheetController;
use Illuminate\Support\Facades\Route;

Route::get('/shift-schedules/export-xlsx', [HrShiftScheduleSpreadsheetController::class, 'export'])
    ->middleware('permission_or_snapshot:hr.schedule.view,hr.schedule.export')
    ->name('hr.iter22.shift-schedules.export-xlsx');

Route::post('/shift-schedules/import-xlsx', [HrShiftScheduleSpreadsheetController::class, 'import'])
    ->middleware('permission_or_snapshot:hr.schedule.update,hr.schedule.import')
    ->name('hr.iter22.shift-schedules.import-xlsx');
