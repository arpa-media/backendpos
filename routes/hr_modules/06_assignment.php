<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAssignmentController;
use Illuminate\Support\Facades\Route;

Route::get('/assignments/options', [HrAssignmentController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.assignment.view')->name('hr.assignments.options');
Route::get('/assignments', [HrAssignmentController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.assignment.view')->name('hr.assignments.index');
Route::get('/assignments/{employeeId}/timeline', [HrAssignmentController::class, 'timeline'])
    ->middleware('permission_or_snapshot:hr.assignment.view')->name('hr.assignments.timeline');
Route::post('/assignments/{employeeId}', [HrAssignmentController::class, 'store'])
    ->middleware('permission_or_snapshot:hr.assignment.create')->name('hr.assignments.store');
Route::put('/assignments/{employeeId}', [HrAssignmentController::class, 'update'])
    ->middleware('permission_or_snapshot:hr.assignment.update')->name('hr.assignments.update');
Route::post('/assignments/{employeeId}/history', [HrAssignmentController::class, 'storeHistory'])
    ->middleware('permission_or_snapshot:hr.assignment.create')->name('hr.assignments.history.store');
