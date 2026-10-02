<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairCostingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/costing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/meta', [GeneralAffairCostingController::class, 'meta'])->middleware('permission_or_snapshot:ga.costing.view');
        Route::get('/', [GeneralAffairCostingController::class, 'index'])->middleware('permission_or_snapshot:ga.costing.view');
        Route::post('/', [GeneralAffairCostingController::class, 'store'])->middleware('permission_or_snapshot:ga.costing.create');
        Route::get('/{id}', [GeneralAffairCostingController::class, 'show'])->middleware('permission_or_snapshot:ga.costing.view');
        Route::put('/{id}', [GeneralAffairCostingController::class, 'update'])->middleware('permission_or_snapshot:ga.costing.update');
        Route::delete('/{id}', [GeneralAffairCostingController::class, 'destroy'])->middleware('permission_or_snapshot:ga.costing.delete');
        Route::post('/{id}/attachments', [GeneralAffairCostingController::class, 'uploadAttachment'])->middleware('permission_or_snapshot:ga.costing.update');
        Route::post('/{id}/submit', [GeneralAffairCostingController::class, 'submit'])->middleware('permission_or_snapshot:ga.costing.update');
        Route::post('/{id}/approve', [GeneralAffairCostingController::class, 'approve'])->middleware('permission_or_snapshot:ga.costing.update,ga.costing.approve');
        Route::post('/{id}/reject', [GeneralAffairCostingController::class, 'reject'])->middleware('permission_or_snapshot:ga.costing.update,ga.costing.approve');
    });
