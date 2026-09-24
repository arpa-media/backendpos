<?php
use App\Http\Controllers\Api\V1\Purchasing\RealizationOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/realization-orders')
    ->middleware(['api','auth:sanctum','permission_or_snapshot:purchasing.realization_order.view,purchasing.goods_receipt.view,purchasing.service_acceptance.view,purchasing.reimburse_payment.view'])
    ->group(function():void{
        Route::get('/',[RealizationOrderController::class,'index'])->name('purchasing.realization-orders.index');
        Route::post('/sync-drafts',[RealizationOrderController::class,'sync'])->middleware('permission_or_snapshot:purchasing.realization_order.create,purchasing.realization_order.update')->name('purchasing.realization-orders.sync');
        Route::get('/{kind}/{id}',[RealizationOrderController::class,'show'])->where('kind','service-entry-sheet|goods-receipt|service-acceptance|reimburse-payment')->name('purchasing.realization-orders.show');
        Route::put('/{kind}/{id}',[RealizationOrderController::class,'update'])->middleware('permission_or_snapshot:purchasing.realization_order.update')->name('purchasing.realization-orders.update');
        Route::post('/{kind}/{id}/review',[RealizationOrderController::class,'review'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.submit')->name('purchasing.realization-orders.review');
        Route::post('/{kind}/{id}/evidence-submit',[RealizationOrderController::class,'submitEvidence'])->middleware('permission_or_snapshot:purchasing.realization_order.upload,purchasing.realization_order.update,purchasing.realization_order.submit')->name('purchasing.realization-orders.evidence-submit');
        Route::post('/{kind}/{id}/submit',[RealizationOrderController::class,'submit'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.create,purchasing.realization_order.submit')->name('purchasing.realization-orders.submit');
        Route::post('/{kind}/{id}/approve',[RealizationOrderController::class,'approve'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.approve')->name('purchasing.realization-orders.approve');
        Route::post('/{kind}/{id}/reject',[RealizationOrderController::class,'reject'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.approve')->name('purchasing.realization-orders.reject');
        Route::post('/{kind}/{id}/attachments',[RealizationOrderController::class,'upload'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.upload')->name('purchasing.realization-orders.attachments.store');
        Route::delete('/{kind}/{id}/attachments/{attachmentId}',[RealizationOrderController::class,'deleteAttachment'])->middleware('permission_or_snapshot:purchasing.realization_order.update,purchasing.realization_order.upload')->name('purchasing.realization-orders.attachments.destroy');
    });
