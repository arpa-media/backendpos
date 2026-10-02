<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/ticketing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairTicketController::class, 'managerMeta'])
            ->middleware('permission_or_snapshot:ga.ticketing.view');
        Route::get('/', [GeneralAffairTicketController::class, 'managerIndex'])
            ->middleware('permission_or_snapshot:ga.ticketing.view');
        Route::post('/', [GeneralAffairTicketController::class, 'managerStore'])
            ->middleware('permission_or_snapshot:ga.ticketing.create');
        Route::get('/{id}', [GeneralAffairTicketController::class, 'managerShow'])
            ->middleware('permission_or_snapshot:ga.ticketing.view');
        Route::put('/{id}', [GeneralAffairTicketController::class, 'managerUpdate'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
        Route::post('/{id}/attachments', [GeneralAffairTicketController::class, 'managerUploadAttachment'])
            ->middleware('permission_or_snapshot:ga.ticketing.update');
    });

Route::prefix('api/v1/report/general-affair/ticketing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairTicketController::class, 'requesterMeta'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.view');
        Route::get('/', [GeneralAffairTicketController::class, 'requesterIndex'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.view');
        Route::post('/', [GeneralAffairTicketController::class, 'requesterStore'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.create');
        Route::get('/{id}', [GeneralAffairTicketController::class, 'requesterShow'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.view');
        Route::put('/{id}', [GeneralAffairTicketController::class, 'requesterUpdate'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.update');
        Route::post('/{id}/attachments', [GeneralAffairTicketController::class, 'requesterUploadAttachment'])
            ->middleware('permission_or_snapshot:report.ga.ticketing.update');
    });
