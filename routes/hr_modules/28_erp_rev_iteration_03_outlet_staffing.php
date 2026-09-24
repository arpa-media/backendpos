<?php

use App\Http\Controllers\Api\V1\HumanResource\HrOutletStaffingController;
use Illuminate\Support\Facades\Route;

Route::get('/outlets/{id}/staffing/employees', [HrOutletStaffingController::class, 'employees'])
    ->middleware('permission_or_snapshot:hr.outlet.view')
    ->name('hr.outlets.staffing.employees');

Route::put('/outlets/{id}/staffing-slots', [HrOutletStaffingController::class, 'updateSlots'])
    ->middleware('permission_or_snapshot:hr.outlet.update,hr.outlet.staffing.manage')
    ->name('hr.outlets.staffing-slots.update');
