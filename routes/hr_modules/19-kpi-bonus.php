<?php

use App\Http\Controllers\Api\V1\HumanResource\HrKpiBonusController;
use Illuminate\Support\Facades\Route;

Route::get('/kpi-mapping/references',[HrKpiBonusController::class,'references'])->middleware('permission_or_snapshot:hr.kpi.mapping.view')->name('hr.kpi.mapping.references');
Route::get('/kpi-mapping/periods',[HrKpiBonusController::class,'kpiIndex'])->middleware('permission_or_snapshot:hr.kpi.mapping.view')->name('hr.kpi.mapping.periods');
Route::post('/kpi-mapping/recalculate',[HrKpiBonusController::class,'kpiRecalculate'])->middleware('permission_or_snapshot:hr.kpi.mapping.recalculate')->name('hr.kpi.mapping.recalculate');
Route::get('/kpi-mapping/periods/{id}',[HrKpiBonusController::class,'kpiShow'])->middleware('permission_or_snapshot:hr.kpi.mapping.view')->name('hr.kpi.mapping.show');
Route::get('/kpi-mapping/periods/{id}/export',[HrKpiBonusController::class,'kpiExport'])->middleware('permission_or_snapshot:hr.kpi.mapping.export,hr.kpi.mapping.view')->name('hr.kpi.mapping.export');

Route::get('/bonus-projections',[HrKpiBonusController::class,'bonusIndex'])->middleware('permission_or_snapshot:hr.bonus.projection.view')->name('hr.bonus.projection.index');
Route::post('/bonus-projections',[HrKpiBonusController::class,'bonusCreate'])->middleware('permission_or_snapshot:hr.bonus.projection.create')->name('hr.bonus.projection.create');
Route::get('/bonus-projections/{id}',[HrKpiBonusController::class,'bonusShow'])->middleware('permission_or_snapshot:hr.bonus.projection.view')->name('hr.bonus.projection.show');
Route::post('/bonus-projections/{id}/recalculate',[HrKpiBonusController::class,'bonusRecalculate'])->middleware('permission_or_snapshot:hr.bonus.projection.recalculate')->name('hr.bonus.projection.recalculate');
// Access Matrix exposes Proyeksi Bonus Edit as hr.bonus.projection.recalculate.
// The UI intentionally allows users with that Edit access to submit the draft.
// Accept both permissions so Access Matrix and endpoint authorization stay aligned.
Route::post('/bonus-projections/{id}/submit',[HrKpiBonusController::class,'bonusSubmit'])
    ->middleware('permission_or_snapshot:hr.bonus.projection.submit,hr.bonus.projection.recalculate')
    ->name('hr.bonus.projection.submit');
Route::get('/bonus-projections/{id}/export',[HrKpiBonusController::class,'bonusExport'])->middleware('permission_or_snapshot:hr.bonus.projection.export,hr.bonus.projection.view')->name('hr.bonus.projection.export');
