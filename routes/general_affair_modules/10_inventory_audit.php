<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryAuditController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/inventory-audit')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/meta', [GeneralAffairInventoryAuditController::class, 'meta'])->middleware('permission_or_snapshot:ga.inventory_audit.view');
    Route::get('/', [GeneralAffairInventoryAuditController::class, 'index'])->middleware('permission_or_snapshot:ga.inventory_audit.view');
    Route::post('/start', [GeneralAffairInventoryAuditController::class, 'start'])->middleware('permission_or_snapshot:ga.inventory_audit.create');
    Route::get('/{id}', [GeneralAffairInventoryAuditController::class, 'show'])->middleware('permission_or_snapshot:ga.inventory_audit.view');
    Route::get('/{id}/lines', [GeneralAffairInventoryAuditController::class, 'lines'])->middleware('permission_or_snapshot:ga.inventory_audit.view');
    Route::post('/{id}/lines/{lineId}', [GeneralAffairInventoryAuditController::class, 'updateLine'])->middleware('permission_or_snapshot:ga.inventory_audit.update');
    Route::post('/{id}/complete', [GeneralAffairInventoryAuditController::class, 'complete'])->middleware('permission_or_snapshot:ga.inventory_audit.update');
});
