<?php

use App\Http\Controllers\Api\V1\HumanResource\HrAnnouncementSelfController;
use App\Services\HrUserDashboardService;
use App\Services\HumanResource\HrUserDashboardAnnouncementService;
use Illuminate\Support\Facades\Route;

app()->bind(HrUserDashboardService::class, HrUserDashboardAnnouncementService::class);

Route::prefix('api/v1/human-resource/self')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/announcements', [HrAnnouncementSelfController::class, 'index'])->name('hr.self.announcements.index');
    Route::get('/announcements/{id}', [HrAnnouncementSelfController::class, 'show'])->name('hr.self.announcements.show');
    Route::post('/announcements/{id}/vote', [HrAnnouncementSelfController::class, 'vote'])->name('hr.self.announcements.vote');
    Route::get('/announcements/{announcementId}/attachments/{attachmentId}/download', [HrAnnouncementSelfController::class, 'downloadAttachment'])->name('hr.self.announcements.attachments.download');
});
