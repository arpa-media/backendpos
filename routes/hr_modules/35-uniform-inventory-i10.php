<?php

use App\Http\Controllers\Api\V1\HumanResource\HrUniformI10Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('/uniform-i10')->group(function (): void {
    Route::get('/references', [HrUniformI10Controller::class, 'references'])
        ->middleware('permission_or_snapshot:hr.uniform.master.view,hr.uniform.stock.view,hr.uniform.inbound.view');

    Route::get('/master', [HrUniformI10Controller::class, 'masterIndex'])
        ->middleware('permission_or_snapshot:hr.uniform.master.view');
    Route::post('/master', [HrUniformI10Controller::class, 'masterStore'])
        ->middleware('permission_or_snapshot:hr.uniform.master.create');
    Route::put('/master/{id}', [HrUniformI10Controller::class, 'masterUpdate'])
        ->middleware('permission_or_snapshot:hr.uniform.master.update');
    Route::delete('/master/{id}', [HrUniformI10Controller::class, 'masterDestroy'])
        ->middleware('permission_or_snapshot:hr.uniform.master.delete');

    Route::get('/stock', [HrUniformI10Controller::class, 'stockIndex'])
        ->middleware('permission_or_snapshot:hr.uniform.stock.view');

    Route::get('/inbounds', [HrUniformI10Controller::class, 'inboundIndex'])
        ->middleware('permission_or_snapshot:hr.uniform.inbound.view');
    Route::get('/inbounds/{id}', [HrUniformI10Controller::class, 'inboundShow'])
        ->middleware('permission_or_snapshot:hr.uniform.inbound.view');
    Route::post('/inbounds', [HrUniformI10Controller::class, 'inboundStore'])
        ->middleware('permission_or_snapshot:hr.uniform.inbound.create');

    Route::get('/recap/preview', [HrUniformI10Controller::class, 'recapPreview'])
        ->middleware('permission_or_snapshot:hr.uniform.stock.view,hr.uniform.inbound.view');
    Route::get('/recap/export', [HrUniformI10Controller::class, 'recapExport'])
        ->middleware('permission_or_snapshot:hr.uniform.stock.view,hr.uniform.inbound.view');
});
