<?php

use App\Http\Controllers\Api\V1\Cogs\ItemSoldController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/cogs/item-sold')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function (): void {
        Route::get('/catalogs', [ItemSoldController::class, 'catalogs'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.catalogs');
        Route::get('/overview', [ItemSoldController::class, 'overview'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.overview');
        Route::get('/ingredients', [ItemSoldController::class, 'ingredients'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.ingredients');
        Route::get('/exceptions', [ItemSoldController::class, 'exceptions'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.exceptions');
        Route::post('/exceptions/{id}/retry', [ItemSoldController::class, 'retryException'])
            ->middleware('permission_or_snapshot:cogs.item_sold.update')
            ->name('cogs.item-sold.exceptions.retry');
        Route::post('/process', [ItemSoldController::class, 'process'])
            ->middleware('permission_or_snapshot:cogs.item_sold.update')
            ->name('cogs.item-sold.process');
        Route::get('/export', [ItemSoldController::class, 'export'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.export');
        Route::get('/', [ItemSoldController::class, 'index'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.index');
        Route::get('/{id}', [ItemSoldController::class, 'show'])
            ->middleware('permission_or_snapshot:cogs.item_sold.view')
            ->name('cogs.item-sold.show');
    });
