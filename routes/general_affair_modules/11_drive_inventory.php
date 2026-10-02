<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairDriveInventoryController;
use App\Services\GeneralAffair\DriveInventoryRuntimeBridge;
use Illuminate\Support\Facades\Route;

// I11 runtime bridge is registered from the additive GA route registry so I08/I09 files stay untouched.
app(DriveInventoryRuntimeBridge::class)->boot();

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/general-affair/drive-inventory')->group(function (): void {
    Route::get('/', [GeneralAffairDriveInventoryController::class, 'index'])
        ->middleware('permission_or_snapshot:ga.inventory_drive.view')
        ->name('ga.drive-inventory.index.i11');
    Route::get('/meta', [GeneralAffairDriveInventoryController::class, 'meta'])
        ->middleware('permission_or_snapshot:ga.inventory_drive.view')
        ->name('ga.drive-inventory.meta.i11');
    Route::get('/download', [GeneralAffairDriveInventoryController::class, 'download'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.view', 'throttle:30,1'])
        ->name('ga.drive-inventory.download.i11');

    Route::post('/folders', [GeneralAffairDriveInventoryController::class, 'createFolder'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.create', 'throttle:40,1'])
        ->name('ga.drive-inventory.folder.create.i11');
    Route::post('/upload', [GeneralAffairDriveInventoryController::class, 'upload'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.create', 'throttle:30,1'])
        ->name('ga.drive-inventory.upload.i11');

    Route::post('/rename-folder', [GeneralAffairDriveInventoryController::class, 'renameFolder'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.update', 'throttle:40,1'])
        ->name('ga.drive-inventory.folder.rename.i11');
    Route::post('/move', [GeneralAffairDriveInventoryController::class, 'move'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.update', 'throttle:30,1'])
        ->name('ga.drive-inventory.move.i11');
    Route::put('/mappings/{outletId}', [GeneralAffairDriveInventoryController::class, 'mapOutlet'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.update', 'throttle:30,1'])
        ->name('ga.drive-inventory.mapping.put.i11');
    Route::delete('/mappings/{outletId}', [GeneralAffairDriveInventoryController::class, 'unmapOutlet'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.update', 'throttle:30,1'])
        ->name('ga.drive-inventory.mapping.delete.i11');
    Route::post('/masters/sync', [GeneralAffairDriveInventoryController::class, 'syncMasters'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.update', 'throttle:20,1'])
        ->name('ga.drive-inventory.masters.sync.i11');

    Route::post('/bulk-delete', [GeneralAffairDriveInventoryController::class, 'delete'])
        ->middleware(['permission_or_snapshot:ga.inventory_drive.delete', 'throttle:15,1'])
        ->name('ga.drive-inventory.delete.i11');
});
