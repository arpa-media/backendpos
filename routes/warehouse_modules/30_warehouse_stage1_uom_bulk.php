<?php

use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseSkuUomBulkController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/inventory/uom-bulk')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/export', [WarehouseSkuUomBulkController::class, 'export'])
            ->middleware('permission_or_snapshot:warehouse.inventory.item.view')
            ->name('warehouse.v3.stage1.uom-bulk.export');
        Route::post('/import', [WarehouseSkuUomBulkController::class, 'import'])
            ->middleware('permission_or_snapshot:warehouse.inventory.item.update')
            ->name('warehouse.v3.stage1.uom-bulk.import');
        Route::get('/items/{skuId}/candidates', [WarehouseSkuUomBulkController::class, 'candidates'])
            ->middleware('permission_or_snapshot:warehouse.inventory.item.view')
            ->name('warehouse.v3.stage1.uom-bulk.candidates');
        Route::post('/items/{skuId}/adopt', [WarehouseSkuUomBulkController::class, 'adopt'])
            ->middleware('permission_or_snapshot:warehouse.inventory.item.update')
            ->name('warehouse.v3.stage1.uom-bulk.adopt');
    });
