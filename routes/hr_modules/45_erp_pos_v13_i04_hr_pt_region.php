<?php

use App\Http\Controllers\Api\V1\HumanResource\HrV13I04OutletCompanyController;
use Illuminate\Support\Facades\Route;

// Included inside auth:sanctum + outlet_scope + human-resource/permission:auth.me group.
Route::get('/v13-i04/outlet-pt', [HrV13I04OutletCompanyController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.outlet.view');
Route::get('/v13-i04/outlet-pt/audit', [HrV13I04OutletCompanyController::class, 'audit'])
    ->middleware('permission_or_snapshot:hr.outlet.update');
Route::put('/v13-i04/outlet-pt/outlets/{id}', [HrV13I04OutletCompanyController::class, 'updateOutlet'])
    ->middleware('permission_or_snapshot:hr.outlet.update');
Route::put('/v13-i04/outlet-pt/companies/{code}/outlets', [HrV13I04OutletCompanyController::class, 'updateChecklist'])
    ->middleware('permission_or_snapshot:hr.outlet.update');
