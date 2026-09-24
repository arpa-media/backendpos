<?php

use App\Http\Controllers\Api\V1\HumanResource\HrDocumentWorkflowI14Controller;
use Illuminate\Support\Facades\Route;

Route::get('/document-workflow/signers', [HrDocumentWorkflowI14Controller::class, 'signerReferences'])
    ->middleware('permission_or_snapshot:hr.contract.document.generate,hr.contract.update')->name('hr.i14.document-signers.index');
Route::post('/document-workflow/signers', [HrDocumentWorkflowI14Controller::class, 'saveSigner'])
    ->middleware('permission_or_snapshot:hr.contract.document.generate,hr.contract.update')->name('hr.i14.document-signers.store');

Route::get('/punishments/document-templates', [HrDocumentWorkflowI14Controller::class, 'punishmentTemplates'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')->name('hr.i14.punishment-templates.index');
Route::get('/punishments/verbal-warnings', [HrDocumentWorkflowI14Controller::class, 'verbalIndex'])
    ->middleware('permission_or_snapshot:hr.punishment.view,hr.sp.view')->name('hr.i14.verbal-warnings.index');
Route::post('/punishments/verbal-warnings', [HrDocumentWorkflowI14Controller::class, 'verbalStore'])
    ->middleware('permission_or_snapshot:hr.punishment.create,hr.sp.create')->name('hr.i14.verbal-warnings.store');
