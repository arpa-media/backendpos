<?php

use App\Http\Controllers\Api\V1\StockInventory\StockSkuController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory/skus')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/template', [StockSkuController::class, 'template'])
            ->middleware('permission_or_snapshot:stock_inventory.sku.view')
            ->name('stock-inventory.skus.template');

        Route::get('/export', [StockSkuController::class, 'export'])
            ->middleware('permission_or_snapshot:stock_inventory.sku.view')
            ->name('stock-inventory.skus.export');

        Route::post('/import', [StockSkuController::class, 'import'])
            ->middleware([
                'permission_or_snapshot:stock_inventory.sku.create',
                'permission_or_snapshot:stock_inventory.sku.update',
            ])
            ->name('stock-inventory.skus.import');
    });
