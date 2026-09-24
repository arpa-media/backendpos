<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAnnouncementController;
use App\Http\Controllers\Api\V1\HumanResource\HrDevelopmentController;
use Illuminate\Support\Facades\Route;

// HR ITERATION 24: scalable target/participant lookup + Development V2.1 lifecycle/output actions.
Route::get('/announcements/options/users', [HrAnnouncementController::class, 'userOptions'])
    ->middleware('permission_or_snapshot:hr.announcement.view');

Route::get('/developments/options/employees', [HrDevelopmentController::class, 'employeeOptions'])
    ->middleware('permission_or_snapshot:hr.development.participant.manage,hr.development.update');
Route::post('/developments/{id}/draft', [HrDevelopmentController::class, 'draft'])
    ->middleware('permission_or_snapshot:hr.development.unpublish,hr.development.update');
Route::post('/developments/{id}/badge-logo', [HrDevelopmentController::class, 'uploadBadgeLogo'])
    ->middleware('permission_or_snapshot:hr.development.badge.manage,hr.development.update');
Route::delete('/developments/{id}/badge-logo', [HrDevelopmentController::class, 'deleteBadgeLogo'])
    ->middleware('permission_or_snapshot:hr.development.badge.manage,hr.development.update');
Route::get('/development-self/certificate/{participantId}/download', [HrDevelopmentController::class, 'selfCertificateDownload']);
