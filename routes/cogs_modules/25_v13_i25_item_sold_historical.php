<?php

use App\Http\Controllers\Api\V1\Cogs\ItemSoldHistoricalV13I25Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/item-sold-v13-i25')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone', 'permission_or_snapshot:cogs.item_sold.view'])
    ->group(function (): void {
        Route::get('/report', [ItemSoldHistoricalV13I25Controller::class, 'report'])
            ->name('cogs.v13-i25.item-sold.report');
        Route::get('/snapshot/{id}', [ItemSoldHistoricalV13I25Controller::class, 'detail'])
            ->name('cogs.v13-i25.item-sold.snapshot');
    });
