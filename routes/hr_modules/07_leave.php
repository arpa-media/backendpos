<?php

use App\Http\Controllers\Api\V1\HumanResource\HrLeaveApprovalController;
use App\Http\Controllers\Api\V1\HumanResource\HrManualLeaveController;
use Illuminate\Support\Facades\Route;


Route::get('/leave-approvals/manual/options', [HrManualLeaveController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.leave.approval.view')->name('hr.leave-approvals.manual.options');
Route::post('/leave-approvals/manual', [HrManualLeaveController::class, 'store'])
    ->middleware('permission_or_snapshot:hr.leave.approval.view')->name('hr.leave-approvals.manual.store');

Route::get('/leave-approvals/options', [HrLeaveApprovalController::class, 'options'])
    ->middleware('permission_or_snapshot:hr.leave.approval.view')->name('hr.leave-approvals.options');
Route::get('/leave-approvals', [HrLeaveApprovalController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.leave.approval.view')->name('hr.leave-approvals.index');
Route::post('/leave-approvals/{id}/spv', [HrLeaveApprovalController::class, 'spv'])
    ->middleware('permission_or_snapshot:hr.leave.approval.spv')->name('hr.leave-approvals.spv');
Route::post('/leave-approvals/{id}/hrd', [HrLeaveApprovalController::class, 'hrd'])
    ->middleware('permission_or_snapshot:hr.leave.approval.hrd')->name('hr.leave-approvals.hrd');
Route::post('/leave-approvals/{id}/override-spv', [HrLeaveApprovalController::class, 'overrideSpv'])
    ->middleware('permission_or_snapshot:hr.leave.approval.override_spv')->name('hr.leave-approvals.override-spv');
