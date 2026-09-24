<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAnnouncementController;
use Illuminate\Support\Facades\Route;

Route::get('/announcements/references', [HrAnnouncementController::class, 'references'])
    ->middleware('permission_or_snapshot:hr.announcement.view')->name('hr.announcements.references');
Route::get('/announcements', [HrAnnouncementController::class, 'index'])
    ->middleware('permission_or_snapshot:hr.announcement.view')->name('hr.announcements.index');
Route::post('/announcements', [HrAnnouncementController::class, 'store'])
    ->middleware('permission_or_snapshot:hr.announcement.create')->name('hr.announcements.store');
Route::get('/announcements/{id}', [HrAnnouncementController::class, 'show'])
    ->middleware('permission_or_snapshot:hr.announcement.view')->name('hr.announcements.show');
Route::post('/announcements/{id}', [HrAnnouncementController::class, 'update'])
    ->middleware('permission_or_snapshot:hr.announcement.update')->name('hr.announcements.update');
Route::post('/announcements/{id}/publish', [HrAnnouncementController::class, 'publish'])
    ->middleware('permission_or_snapshot:hr.announcement.publish,hr.announcement.update')->name('hr.announcements.publish');
Route::post('/announcements/{id}/unpublish', [HrAnnouncementController::class, 'unpublish'])
    ->middleware('permission_or_snapshot:hr.announcement.publish,hr.announcement.update')->name('hr.announcements.unpublish');
Route::delete('/announcements/{id}', [HrAnnouncementController::class, 'destroy'])
    ->middleware('permission_or_snapshot:hr.announcement.delete')->name('hr.announcements.destroy');
Route::delete('/announcements/{announcementId}/attachments/{attachmentId}', [HrAnnouncementController::class, 'destroyAttachment'])
    ->middleware('permission_or_snapshot:hr.announcement.update')->name('hr.announcements.attachments.destroy');
Route::get('/announcements/{announcementId}/attachments/{attachmentId}/download', [HrAnnouncementController::class, 'downloadAttachment'])
    ->middleware('permission_or_snapshot:hr.announcement.view')->name('hr.announcements.attachments.download');
Route::get('/announcements/{id}/poll/results', [HrAnnouncementController::class, 'results'])
    ->middleware('permission_or_snapshot:hr.announcement.results,hr.announcement.view')->name('hr.announcements.poll.results');
Route::get('/announcements/{id}/poll/results.xlsx', [HrAnnouncementController::class, 'resultsXlsx'])
    ->middleware('permission_or_snapshot:hr.announcement.export,hr.announcement.results,hr.announcement.view')->name('hr.announcements.poll.results.xlsx');
Route::get('/announcements/{id}/poll/results.csv', [HrAnnouncementController::class, 'resultsCsv'])
    ->middleware('permission_or_snapshot:hr.announcement.export,hr.announcement.results,hr.announcement.view')->name('hr.announcements.poll.results.csv');
