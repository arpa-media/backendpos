<?php

use App\Http\Controllers\Api\V1\HumanResource\HrShiftController;
use Illuminate\Support\Facades\Route;

Route::get('/shifts', [HrShiftController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.shift.view')
    ->name('hr.shifts.index');
Route::post('/shifts/bulk', [HrShiftController::class, 'storeBulk'])
    ->middleware('permission_or_snapshot:hr.shift.create')
    ->name('hr.shifts.bulk-store');
Route::get('/shifts/{id}', [HrShiftController::class, 'show'])
    ->middleware('permission_or_snapshot:hr.shift.view')
    ->name('hr.shifts.show');
Route::put('/shifts/{id}', [HrShiftController::class, 'update'])
    ->middleware('permission_or_snapshot:hr.shift.update')
    ->name('hr.shifts.update');
Route::delete('/shifts/{id}', [HrShiftController::class, 'destroy'])
    ->middleware('permission_or_snapshot:hr.shift.delete')
    ->name('hr.shifts.destroy');
