<?php

use App\Http\Controllers\Api\V1\HumanResource\HrContractLifecycleController;
use Illuminate\Support\Facades\Route;

// ERP HR v5 Refinement I05 — additive endpoints.
// Kept outside /contracts/{id} so the legacy wildcard route cannot shadow them.
Route::get('/contract-lifecycle/references', [HrContractLifecycleController::class, 'references'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contract.lifecycle.references');
Route::get('/contract-lifecycle', [HrContractLifecycleController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contract.lifecycle.index');
Route::get('/contract-lifecycle/recap', [HrContractLifecycleController::class, 'recap'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contract.lifecycle.recap');
Route::get('/contract-lifecycle/export', [HrContractLifecycleController::class, 'export'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contract.lifecycle.export');
Route::post('/contract-lifecycle/{id}/resolve', [HrContractLifecycleController::class, 'resolveStage'])
    ->middleware('permission_or_snapshot:hr.contract.update')->name('hr.contract.lifecycle.resolve');
Route::post('/contract-lifecycle/{id}/transition', [HrContractLifecycleController::class, 'transition'])
    ->middleware('permission_or_snapshot:hr.contract.create,hr.contract.update')->name('hr.contract.lifecycle.transition');
