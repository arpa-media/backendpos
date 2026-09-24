<?php

use App\Http\Controllers\Api\V1\Warehouse\ParStock\WarehouseParStockController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])->prefix('api/v1/warehouse/par-stocks')->group(function (): void {
    Route::get('/', [WarehouseParStockController::class, 'index'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.view')
        ->name('warehouse.par-stocks.index.i05');
    Route::get('/template', [WarehouseParStockController::class, 'template'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.view')
        ->name('warehouse.par-stocks.template.i05');
    Route::get('/export', [WarehouseParStockController::class, 'export'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.view')
        ->name('warehouse.par-stocks.export.i05');
    Route::post('/import', [WarehouseParStockController::class, 'import'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.update')
        ->name('warehouse.par-stocks.import.i05');
    Route::post('/', [WarehouseParStockController::class, 'store'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.create')
        ->name('warehouse.par-stocks.store.i05');
    Route::put('/{skuId}', [WarehouseParStockController::class, 'update'])
        ->middleware('permission_or_snapshot:warehouse.inventory.par_stock.update')
        ->name('warehouse.par-stocks.update.i05');
});
