<?php

use App\Http\Controllers\Api\V1\Purchasing\FundRequestController;
use App\Http\Controllers\Api\V1\Purchasing\PurchasingDocumentAttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/fund-requests')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/catalogs', [FundRequestController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view,purchasing.fund_request.create')
            ->name('purchasing.fund-requests.catalogs');

        Route::get('/', [FundRequestController::class, 'index'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.index');

        Route::post('/', [FundRequestController::class, 'store'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.create')
            ->name('purchasing.fund-requests.store');

        Route::post('/{id}/submit', [FundRequestController::class, 'submit'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.create,purchasing.fund_request.update')
            ->name('purchasing.fund-requests.submit');

        Route::post('/{id}/approve', [FundRequestController::class, 'approve'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.approve');

        Route::post('/{id}/reject', [FundRequestController::class, 'reject'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.reject');

        Route::post('/{id}/generate-order', [FundRequestController::class, 'generateOrder'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.update,purchasing.purchase_order.create,purchasing.service_order.create,purchasing.reimburse_order.create')
            ->name('purchasing.fund-requests.generate-order');

        Route::post('/{id}/attachments', [FundRequestController::class, 'uploadAttachment'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.create,purchasing.fund_request.update')
            ->name('purchasing.fund-requests.attachments.store');

        Route::delete('/{id}/attachments/{attachmentId}', [FundRequestController::class, 'deleteAttachment'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.create,purchasing.fund_request.update')
            ->name('purchasing.fund-requests.attachments.destroy');

        Route::get('/{id}/timeline', [FundRequestController::class, 'timeline'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.timeline');

        Route::get('/{id}', [FundRequestController::class, 'show'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.show');

        Route::put('/{id}', [FundRequestController::class, 'update'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.create,purchasing.fund_request.update')
            ->name('purchasing.fund-requests.update');

        Route::delete('/{id}', [FundRequestController::class, 'destroy'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.delete')
            ->name('purchasing.fund-requests.destroy');
    });


Route::get('api/v1/purchasing/document-attachments/{attachmentId}/content', [PurchasingDocumentAttachmentController::class, 'content'])
    ->middleware(['api', 'auth:sanctum', 'permission_or_snapshot:purchasing.fund_request.view,purchasing.order_management.view,purchasing.realization_order.view'])
    ->name('purchasing.document-attachments.content');
