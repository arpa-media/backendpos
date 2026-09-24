<?php

use App\Http\Controllers\Api\V1\HumanResource\HrContractController;
use Illuminate\Support\Facades\Route;

Route::get('/contracts/references', [HrContractController::class, 'references'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contracts.references');
Route::get('/contracts/templates', [HrContractController::class, 'templates'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contracts.templates');
Route::post('/contracts/templates', [HrContractController::class, 'createTemplate'])
    ->middleware('permission_or_snapshot:hr.contract.document.generate,hr.contract.update')->name('hr.contracts.templates.store');
Route::post('/contracts/templates/{id}/activate', [HrContractController::class, 'activateTemplate'])
    ->middleware('permission_or_snapshot:hr.contract.document.generate,hr.contract.update')->name('hr.contracts.templates.activate');
Route::get('/contracts', [HrContractController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contracts.index');
Route::post('/contracts', [HrContractController::class, 'store'])
    ->middleware('permission_or_snapshot:hr.contract.create')->name('hr.contracts.store');
Route::get('/contracts/{id}', [HrContractController::class, 'show'])
    ->middleware('permission_or_snapshot:hr.contract.view')->name('hr.contracts.show');
Route::put('/contracts/{id}', [HrContractController::class, 'update'])
    ->middleware('permission_or_snapshot:hr.contract.update')->name('hr.contracts.update');
Route::delete('/contracts/{id}', [HrContractController::class, 'destroy'])
    ->middleware('permission_or_snapshot:hr.contract.delete')->name('hr.contracts.destroy');
Route::post('/contracts/{id}/documents', [HrContractController::class, 'generateDocument'])
    ->middleware('permission_or_snapshot:hr.contract.document.generate,hr.contract.update')->name('hr.contracts.documents.generate');
Route::post('/contracts/documents/{id}/submit', [HrContractController::class, 'submitDocument'])
    ->middleware('permission_or_snapshot:hr.contract.submit,hr.contract.update')->name('hr.contracts.documents.submit');
Route::post('/contracts/documents/{id}/approve', [HrContractController::class, 'approveDocument'])
    ->middleware('permission_or_snapshot:hr.contract.approve,hr.contract.update')->name('hr.contracts.documents.approve');
Route::post('/contracts/documents/{id}/reject', [HrContractController::class, 'rejectDocument'])
    ->middleware('permission_or_snapshot:hr.contract.approve,hr.contract.update')->name('hr.contracts.documents.reject');
Route::post('/contracts/{id}/reminders', [HrContractController::class, 'addReminder'])
    ->middleware('permission_or_snapshot:hr.contract.reminder.manage,hr.contract.update')->name('hr.contracts.reminders.store');
Route::post('/contracts/reminders/{id}/acknowledge', [HrContractController::class, 'acknowledgeReminder'])
    ->middleware('permission_or_snapshot:hr.contract.reminder.manage,hr.contract.update')->name('hr.contracts.reminders.ack');
Route::delete('/contracts/reminders/{id}', [HrContractController::class, 'deleteReminder'])
    ->middleware('permission_or_snapshot:hr.contract.reminder.manage,hr.contract.update')->name('hr.contracts.reminders.destroy');
