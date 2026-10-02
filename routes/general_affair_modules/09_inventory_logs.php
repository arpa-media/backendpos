<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair/inventory-logs')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/meta', [GeneralAffairInventoryLogController::class, 'meta'])->middleware('permission_or_snapshot:ga.inventory_log.view');
    Route::get('/items', [GeneralAffairInventoryLogController::class, 'items'])->middleware('permission_or_snapshot:ga.inventory_log.view');
    Route::get('/', [GeneralAffairInventoryLogController::class, 'index'])->middleware('permission_or_snapshot:ga.inventory_log.view');
    Route::get('/{id}', [GeneralAffairInventoryLogController::class, 'show'])->middleware('permission_or_snapshot:ga.inventory_log.view');
    Route::post('/', [GeneralAffairInventoryLogController::class, 'store'])->middleware('permission_or_snapshot:ga.inventory_log.create');
});
