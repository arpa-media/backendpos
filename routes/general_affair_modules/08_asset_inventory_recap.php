<?php

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairAssetController;
use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/general-affair')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::prefix('assets')->group(function (): void {
        Route::get('/meta', [GeneralAffairAssetController::class,'meta'])->middleware('permission_or_snapshot:ga.asset.view');
        Route::get('/template-xlsx', [GeneralAffairAssetController::class,'template'])->middleware('permission_or_snapshot:ga.asset.view,ga.asset.create');
        Route::get('/export-xlsx', [GeneralAffairAssetController::class,'export'])->middleware('permission_or_snapshot:ga.asset.export,ga.asset.view');
        Route::post('/import-xlsx', [GeneralAffairAssetController::class,'import'])->middleware(['permission_or_snapshot:ga.asset.import,ga.asset.create','permission_or_snapshot:ga.asset.import,ga.asset.update']);
        Route::get('/', [GeneralAffairAssetController::class,'index'])->middleware('permission_or_snapshot:ga.asset.view');
        Route::post('/', [GeneralAffairAssetController::class,'store'])->middleware('permission_or_snapshot:ga.asset.create');
        Route::post('/{id}', [GeneralAffairAssetController::class,'update'])->middleware('permission_or_snapshot:ga.asset.update');
        Route::delete('/{id}', [GeneralAffairAssetController::class,'destroy'])->middleware('permission_or_snapshot:ga.asset.delete');
    });

    Route::prefix('inventory')->group(function (): void {
        Route::get('/meta', [GeneralAffairInventoryController::class,'meta'])->middleware('permission_or_snapshot:ga.inventory.view');
        Route::get('/template-xlsx', [GeneralAffairInventoryController::class,'template'])->middleware('permission_or_snapshot:ga.inventory.view,ga.inventory.create');
        Route::get('/export-xlsx', [GeneralAffairInventoryController::class,'export'])->middleware('permission_or_snapshot:ga.inventory.export,ga.inventory.view');
        Route::post('/import-xlsx', [GeneralAffairInventoryController::class,'import'])->middleware(['permission_or_snapshot:ga.inventory.import,ga.inventory.create','permission_or_snapshot:ga.inventory.import,ga.inventory.update']);
        Route::get('/', [GeneralAffairInventoryController::class,'index'])->middleware('permission_or_snapshot:ga.inventory.view');
        Route::post('/', [GeneralAffairInventoryController::class,'store'])->middleware('permission_or_snapshot:ga.inventory.create');
        Route::post('/{id}', [GeneralAffairInventoryController::class,'update'])->middleware('permission_or_snapshot:ga.inventory.update');
        Route::delete('/{id}', [GeneralAffairInventoryController::class,'destroy'])->middleware('permission_or_snapshot:ga.inventory.delete');
    });
});
