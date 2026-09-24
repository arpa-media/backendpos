<?php

use App\Http\Controllers\Api\V1\Console\StorageFileManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
    Route::get('/console/file-management', [StorageFileManagementController::class, 'index'])
        ->middleware(['permission_or_snapshot:console.file_management.view', 'throttle:60,1'])
        ->name('console.file-management.index.i11');
    Route::get('/console/file-management/download', [StorageFileManagementController::class, 'download'])
        ->middleware(['permission_or_snapshot:console.file_management.view', 'throttle:30,1'])
        ->name('console.file-management.download.i11');
    Route::post('/console/file-management/folders', [StorageFileManagementController::class, 'createFolder'])
        ->middleware(['permission_or_snapshot:console.file_management.create_folder', 'throttle:30,1'])
        ->name('console.file-management.folder.i11');
    Route::post('/console/file-management/move', [StorageFileManagementController::class, 'move'])
        ->middleware(['permission_or_snapshot:console.file_management.move', 'throttle:20,1'])
        ->name('console.file-management.move.i11');
    Route::post('/console/file-management/bulk-delete', [StorageFileManagementController::class, 'delete'])
        ->middleware(['permission_or_snapshot:console.file_management.delete', 'throttle:10,1'])
        ->name('console.file-management.delete.i11');
});
