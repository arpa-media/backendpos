<?php

use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseAllItemController;
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseBarcodeController;
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseBatchController;
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseInventoryOptionController;
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseOutletPriceController;
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseSkuUomController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/inventory')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/options', WarehouseInventoryOptionController::class)
            ->middleware('permission_or_snapshot:warehouse.inventory.item.view|warehouse.inventory.batch.view|warehouse.inventory.barcode.view|warehouse.inventory.price.view')
            ->name('warehouse.inventory.options');

        Route::prefix('items')->group(function (): void {
            Route::get('/', [WarehouseAllItemController::class, 'index'])->middleware('permission_or_snapshot:warehouse.inventory.item.view')->name('warehouse.inventory.items.index');
            Route::post('/', [WarehouseAllItemController::class, 'store'])->middleware('permission_or_snapshot:warehouse.inventory.item.create')->name('warehouse.inventory.items.store');
            Route::get('/{id}', [WarehouseAllItemController::class, 'show'])->middleware('permission_or_snapshot:warehouse.inventory.item.view')->name('warehouse.inventory.items.show');
            Route::put('/{id}', [WarehouseAllItemController::class, 'update'])->middleware('permission_or_snapshot:warehouse.inventory.item.update')->name('warehouse.inventory.items.update');
            Route::delete('/{id}', [WarehouseAllItemController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.inventory.item.delete')->name('warehouse.inventory.items.destroy');
            Route::get('/{skuId}/uoms/list', [WarehouseSkuUomController::class, 'index'])->middleware('permission_or_snapshot:warehouse.inventory.item.view')->name('warehouse.inventory.item-uoms.index');
            Route::post('/{skuId}/uoms', [WarehouseSkuUomController::class, 'store'])->middleware('permission_or_snapshot:warehouse.inventory.item.update')->name('warehouse.inventory.item-uoms.store');
        });

        Route::put('/item-uoms/{id}', [WarehouseSkuUomController::class, 'update'])->middleware('permission_or_snapshot:warehouse.inventory.item.update')->name('warehouse.inventory.item-uoms.update');
        Route::delete('/item-uoms/{id}', [WarehouseSkuUomController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.inventory.item.update')->name('warehouse.inventory.item-uoms.destroy');

        Route::prefix('batches')->group(function (): void {
            Route::get('/', [WarehouseBatchController::class, 'index'])->middleware('permission_or_snapshot:warehouse.inventory.batch.view')->name('warehouse.inventory.batches.index');
            Route::post('/', [WarehouseBatchController::class, 'store'])->middleware('permission_or_snapshot:warehouse.inventory.batch.create')->name('warehouse.inventory.batches.store');
            Route::put('/{id}', [WarehouseBatchController::class, 'update'])->middleware('permission_or_snapshot:warehouse.inventory.batch.update')->name('warehouse.inventory.batches.update');
            Route::delete('/{id}', [WarehouseBatchController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.inventory.batch.delete')->name('warehouse.inventory.batches.destroy');
        });

        Route::prefix('barcodes')->group(function (): void {
            Route::get('/', [WarehouseBarcodeController::class, 'index'])->middleware('permission_or_snapshot:warehouse.inventory.barcode.view')->name('warehouse.inventory.barcodes.index');
            Route::post('/generate', [WarehouseBarcodeController::class, 'generate'])->middleware('permission_or_snapshot:warehouse.inventory.barcode.create')->name('warehouse.inventory.barcodes.generate');
            Route::post('/print-data', [WarehouseBarcodeController::class, 'printData'])->middleware('permission_or_snapshot:warehouse.inventory.barcode.create')->name('warehouse.inventory.barcodes.print-data');
            Route::post('/validate-scan', [WarehouseBarcodeController::class, 'validateScan'])->middleware('permission_or_snapshot:warehouse.inventory.barcode.update')->name('warehouse.inventory.barcodes.validate-scan');
        });

        Route::prefix('outlet-prices')->group(function (): void {
            Route::get('/', [WarehouseOutletPriceController::class, 'index'])->middleware('permission_or_snapshot:warehouse.inventory.price.view')->name('warehouse.inventory.outlet-prices.index');
            Route::post('/', [WarehouseOutletPriceController::class, 'store'])->middleware('permission_or_snapshot:warehouse.inventory.price.create')->name('warehouse.inventory.outlet-prices.store');
            Route::put('/{id}', [WarehouseOutletPriceController::class, 'update'])->middleware('permission_or_snapshot:warehouse.inventory.price.update')->name('warehouse.inventory.outlet-prices.update');
            Route::delete('/{id}', [WarehouseOutletPriceController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.inventory.price.delete')->name('warehouse.inventory.outlet-prices.destroy');
        });
    });
