<?php

use App\Http\Controllers\Api\V1\Cogs\StockValuationV13I24Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/stock-valuation-v13-i24')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone', 'permission_or_snapshot:cogs.history_stock.view'])
    ->group(function (): void {
        Route::get('/catalogs', [StockValuationV13I24Controller::class, 'catalogs'])->name('cogs.v13-i24.catalogs');
        Route::get('/movements', [StockValuationV13I24Controller::class, 'movements'])->name('cogs.v13-i24.movements');
        Route::get('/', [StockValuationV13I24Controller::class, 'index'])->name('cogs.v13-i24.overview');
    });
