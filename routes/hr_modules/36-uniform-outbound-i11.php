<?php

use App\Http\Controllers\Api\V1\HumanResource\HrUniformI11Controller;
use Illuminate\Support\Facades\Route;

Route::get('/uniform-i11/references',[HrUniformI11Controller::class,'references'])->middleware('permission_or_snapshot:hr.uniform.outbound.view,hr.uniform.attribute_outbound.view');
Route::get('/uniform-i11/outbounds',[HrUniformI11Controller::class,'index'])->middleware('permission_or_snapshot:hr.uniform.outbound.view,hr.uniform.attribute_outbound.view');
Route::get('/uniform-i11/outbounds/{id}',[HrUniformI11Controller::class,'show'])->middleware('permission_or_snapshot:hr.uniform.outbound.view,hr.uniform.attribute_outbound.view');
Route::post('/uniform-i11/outbounds',[HrUniformI11Controller::class,'store'])->middleware('permission_or_snapshot:hr.uniform.outbound.create,hr.uniform.attribute_outbound.create');
Route::post('/uniform-i11/outbounds/{id}/cancel',[HrUniformI11Controller::class,'cancel'])->middleware('permission_or_snapshot:hr.uniform.outbound.cancel');
Route::get('/uniform-i11/recap/preview',[HrUniformI11Controller::class,'recapPreview'])->middleware('permission_or_snapshot:hr.uniform.outbound.view,hr.uniform.attribute_outbound.view');
Route::get('/uniform-i11/recap/export',[HrUniformI11Controller::class,'recapExport'])->middleware('permission_or_snapshot:hr.uniform.outbound.view,hr.uniform.attribute_outbound.view');
