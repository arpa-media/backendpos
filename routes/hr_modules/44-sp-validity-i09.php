<?php

use App\Http\Controllers\Api\V1\HumanResource\HrSpValiditySettingI09Controller;
use Illuminate\Support\Facades\Route;

Route::get('/punishments/sp-validity', [HrSpValiditySettingI09Controller::class, 'show'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')
    ->name('hr.punishment.sp-validity-i09.show');

Route::put('/punishments/sp-validity', [HrSpValiditySettingI09Controller::class, 'update'])
    ->middleware('permission_or_snapshot:hr.punishment.update')
    ->name('hr.punishment.sp-validity-i09.update');
