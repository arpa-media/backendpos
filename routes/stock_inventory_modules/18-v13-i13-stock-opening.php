<?php

use App\Http\Controllers\Api\V1\StockInventory\OpeningStockV13I13Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory/opening-initialization')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/', [OpeningStockV13I13Controller::class, 'index'])
            ->middleware('permission_or_snapshot:stock_inventory.dashboard.view');
        Route::post('/', [OpeningStockV13I13Controller::class, 'store'])
            ->middleware([
                'permission_or_snapshot:stock_inventory.dashboard.view',
                'permission_or_snapshot:stock_inventory.par_stock.create,stock_inventory.par_stock.update',
            ]);
    });
