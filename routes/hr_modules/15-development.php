<?php

use App\Http\Controllers\Api\V1\HumanResource\HrDevelopmentController;
use Illuminate\Support\Facades\Route;

Route::get('/developments/references',[HrDevelopmentController::class,'references'])->middleware('permission_or_snapshot:hr.development.view');
Route::get('/developments/tracking',[HrDevelopmentController::class,'tracking'])->middleware('permission_or_snapshot:hr.development.view');
Route::get('/developments/export',[HrDevelopmentController::class,'export'])->middleware('permission_or_snapshot:hr.development.export,hr.development.view');
Route::post('/developments/import',[HrDevelopmentController::class,'import'])->middleware('permission_or_snapshot:hr.development.import,hr.development.update');
Route::get('/developments',[HrDevelopmentController::class,'index'])->middleware('permission_or_snapshot:hr.development.view');
Route::post('/developments',[HrDevelopmentController::class,'store'])->middleware('permission_or_snapshot:hr.development.create');
Route::get('/developments/{id}',[HrDevelopmentController::class,'show'])->middleware('permission_or_snapshot:hr.development.view');
Route::put('/developments/{id}',[HrDevelopmentController::class,'update'])->middleware('permission_or_snapshot:hr.development.update');
Route::delete('/developments/{id}',[HrDevelopmentController::class,'destroy'])->middleware('permission_or_snapshot:hr.development.delete');
Route::post('/developments/{id}/publish',[HrDevelopmentController::class,'publish'])->middleware('permission_or_snapshot:hr.development.result.publish,hr.development.update');
Route::post('/developments/{id}/batches',[HrDevelopmentController::class,'storeBatch'])->middleware('permission_or_snapshot:hr.development.update');
Route::put('/developments/{id}/batches/{batchId}',[HrDevelopmentController::class,'updateBatch'])->middleware('permission_or_snapshot:hr.development.update');
Route::delete('/developments/{id}/batches/{batchId}',[HrDevelopmentController::class,'deleteBatch'])->middleware('permission_or_snapshot:hr.development.update');
Route::get('/developments/{id}/participants',[HrDevelopmentController::class,'participants'])->middleware('permission_or_snapshot:hr.development.view');
Route::post('/developments/{id}/participants',[HrDevelopmentController::class,'addParticipants'])->middleware('permission_or_snapshot:hr.development.participant.manage,hr.development.update');
Route::get('/developments/{id}/participants/{participantId}',[HrDevelopmentController::class,'participant'])->middleware('permission_or_snapshot:hr.development.view');
Route::put('/developments/{id}/participants/{participantId}',[HrDevelopmentController::class,'updateParticipant'])->middleware('permission_or_snapshot:hr.development.participant.manage,hr.development.update');
Route::delete('/developments/{id}/participants/{participantId}',[HrDevelopmentController::class,'removeParticipant'])->middleware('permission_or_snapshot:hr.development.participant.manage,hr.development.update');
Route::post('/developments/{id}/participants/{participantId}/score',[HrDevelopmentController::class,'scoreParticipant'])->middleware('permission_or_snapshot:hr.development.test.score,hr.development.update');
Route::post('/developments/{id}/participants/{participantId}/publish-result',[HrDevelopmentController::class,'publishResult'])->middleware('permission_or_snapshot:hr.development.result.publish,hr.development.update');
Route::post('/developments/{id}/certificate-template',[HrDevelopmentController::class,'uploadTemplate'])->middleware('permission_or_snapshot:hr.development.update');
Route::get('/developments/{id}/certificate-template/{templateId}',[HrDevelopmentController::class,'downloadTemplate'])->middleware('permission_or_snapshot:hr.development.view');

// Self-service: still protected by auth:sanctum + permission:auth.me from hr.php.
Route::get('/development-self',[HrDevelopmentController::class,'self']);
Route::get('/development-self/certificate/{participantId}',[HrDevelopmentController::class,'selfCertificate']);
Route::get('/development-self/certificate/{participantId}/template',[HrDevelopmentController::class,'selfCertificateTemplate']);
