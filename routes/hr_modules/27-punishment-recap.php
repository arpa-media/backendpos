<?php

use App\Http\Controllers\Api\V1\HumanResource\HrPunishmentRecapController;
use Illuminate\Support\Facades\Route;

Route::get('/punishments/recap-sp/references', [HrPunishmentRecapController::class, 'references'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')
    ->name('hr.v5.i04.punishment-recap.references');

Route::get('/punishments/recap-sp/preview', [HrPunishmentRecapController::class, 'preview'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')
    ->name('hr.v5.i04.punishment-recap.preview');

Route::get('/punishments/recap-sp/export', [HrPunishmentRecapController::class, 'export'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')
    ->name('hr.v5.i04.punishment-recap.export');
