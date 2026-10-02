<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairCctvRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/cctv-requests')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairCctvRequestController::class, 'managerMeta'])
            ->middleware('permission_or_snapshot:ga.cctv.view');
        Route::get('/', [GeneralAffairCctvRequestController::class, 'managerIndex'])
            ->middleware('permission_or_snapshot:ga.cctv.view');
        Route::post('/', [GeneralAffairCctvRequestController::class, 'managerStore'])
            ->middleware('permission_or_snapshot:ga.cctv.create');
        Route::get('/{id}', [GeneralAffairCctvRequestController::class, 'managerShow'])
            ->middleware('permission_or_snapshot:ga.cctv.view');
        Route::put('/{id}', [GeneralAffairCctvRequestController::class, 'managerUpdate'])
            ->middleware('permission_or_snapshot:ga.cctv.update');
        Route::post('/{id}/attachments', [GeneralAffairCctvRequestController::class, 'managerUploadAttachment'])
            ->middleware('permission_or_snapshot:ga.cctv.update');
    });

Route::prefix('api/v1/report/general-affair/cctv')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairCctvRequestController::class, 'requesterMeta'])
            ->middleware('permission_or_snapshot:report.ga.cctv.view');
        Route::get('/', [GeneralAffairCctvRequestController::class, 'requesterIndex'])
            ->middleware('permission_or_snapshot:report.ga.cctv.view');
        Route::post('/', [GeneralAffairCctvRequestController::class, 'requesterStore'])
            ->middleware('permission_or_snapshot:report.ga.cctv.create');
        Route::get('/{id}', [GeneralAffairCctvRequestController::class, 'requesterShow'])
            ->middleware('permission_or_snapshot:report.ga.cctv.view');
        Route::put('/{id}', [GeneralAffairCctvRequestController::class, 'requesterUpdate'])
            ->middleware('permission_or_snapshot:report.ga.cctv.update');
        Route::post('/{id}/attachments', [GeneralAffairCctvRequestController::class, 'requesterUploadAttachment'])
            ->middleware('permission_or_snapshot:report.ga.cctv.update');
    });
