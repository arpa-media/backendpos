<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairTicketWorkflowController;
use Illuminate\Support\Facades\Route;

// I04 loads after 03_ticketing.php. The duplicate POST/PUT signatures below are
// deliberate: Laravel's route collection keeps the later route for the same
// method+URI, allowing I04 to harden workflow without overwriting the I03 file.
Route::prefix('api/v1/general-affair/ticketing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::post('/', [GeneralAffairTicketWorkflowController::class, 'managerStore'])
            ->middleware('permission_or_snapshot:ga.ticketing.create');
        Route::put('/{id}', [GeneralAffairTicketWorkflowController::class, 'managerUpdate'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');

        Route::get('/{id}/workflow', [GeneralAffairTicketWorkflowController::class, 'managerShow'])
            ->middleware('permission_or_snapshot:ga.ticketing.view');
        Route::put('/{id}/workflow/requirements', [GeneralAffairTicketWorkflowController::class, 'updateRequirements'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
        Route::post('/{id}/workflow/transition', [GeneralAffairTicketWorkflowController::class, 'transition'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
        Route::post('/{id}/workflow/discussions', [GeneralAffairTicketWorkflowController::class, 'managerDiscussion'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
        Route::post('/{id}/workflow/approvals/{audience}', [GeneralAffairTicketWorkflowController::class, 'decideApproval'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
    });

Route::prefix('api/v1/report/general-affair/ticketing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::put('/{id}', [GeneralAffairTicketWorkflowController::class, 'requesterUpdate'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.update');
        Route::post('/{id}/attachments', [GeneralAffairTicketWorkflowController::class, 'requesterUploadAttachment'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.update');
        Route::get('/{id}/workflow', [GeneralAffairTicketWorkflowController::class, 'requesterShow'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.view');
        Route::post('/{id}/workflow/discussions', [GeneralAffairTicketWorkflowController::class, 'requesterDiscussion'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.update');
    });
