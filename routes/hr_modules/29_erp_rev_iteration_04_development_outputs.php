<?php

use App\Http\Controllers\Api\V1\HumanResource\HrDevelopmentController;
use Illuminate\Support\Facades\Route;

// ERP REV ITERATION 04
// Dedicated additive endpoints. They intentionally do not replace the Iteration 24
// routes, so this overlay can coexist with the previous Development hardening.
Route::post('/developments/{id}/badge-logo-file', [HrDevelopmentController::class, 'uploadBadgeLogo'])
    ->middleware('permission_or_snapshot:hr.development.badge.manage,hr.development.update')
    ->name('hr.development.badge-logo-file.store');
Route::delete('/developments/{id}/badge-logo-file', [HrDevelopmentController::class, 'deleteBadgeLogo'])
    ->middleware('permission_or_snapshot:hr.development.badge.manage,hr.development.update')
    ->name('hr.development.badge-logo-file.delete');

// Self-service endpoint remains restricted by the outer auth:sanctum + auth.me group
// and certificatePayload() additionally verifies participant ownership by user_id.
Route::get('/development-self/certificate/{participantId}/download-file', [HrDevelopmentController::class, 'selfCertificateDownload'])
    ->name('hr.development-self.certificate.download-file');
