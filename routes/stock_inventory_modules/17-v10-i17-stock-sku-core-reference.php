<?php

use App\Http\Controllers\Api\V1\StockInventory\StockSkuCoreImportController;
use Illuminate\Support\Facades\Route;

Route::post('api/v1/stock-inventory/skus/import-core', StockSkuCoreImportController::class)
    ->middleware([
        'api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone',
        'permission_or_snapshot:stock_inventory.sku.create',
        'permission_or_snapshot:stock_inventory.sku.update',
    ])
    ->name('stock-inventory.skus.import-core');
