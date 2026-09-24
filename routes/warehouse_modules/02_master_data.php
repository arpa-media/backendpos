<?php

use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseBrandController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseCategoryController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseChainSupplyController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseLocationController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseMasterOptionController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseStorageController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseSupplierController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseUomController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/master')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function () {
        Route::get('/options', WarehouseMasterOptionController::class)
            ->middleware('permission_or_snapshot:warehouse.master.chain_supply.view,warehouse.master.storage.view,warehouse.master.warehouse.view,warehouse.master.supplier.view')
            ->name('warehouse.master.options');

        Route::prefix('warehouses')->group(function () {
            Route::get('/', [WarehouseLocationController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.warehouse.view')->name('warehouse.master.warehouses.index');
            Route::post('/', [WarehouseLocationController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.warehouse.create')->name('warehouse.master.warehouses.store');
            Route::put('/{id}', [WarehouseLocationController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.warehouse.update')->name('warehouse.master.warehouses.update');
            Route::delete('/{id}', [WarehouseLocationController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.warehouse.delete')->name('warehouse.master.warehouses.destroy');
        });

        Route::prefix('suppliers')->group(function () {
            Route::get('/', [WarehouseSupplierController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.supplier.view')->name('warehouse.master.suppliers.index');
            Route::post('/', [WarehouseSupplierController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.supplier.create')->name('warehouse.master.suppliers.store');
            Route::put('/{id}', [WarehouseSupplierController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.supplier.update')->name('warehouse.master.suppliers.update');
            Route::delete('/{id}', [WarehouseSupplierController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.supplier.delete')->name('warehouse.master.suppliers.destroy');
        });

        Route::prefix('brands')->group(function () {
            Route::get('/', [WarehouseBrandController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.brand.view')->name('warehouse.master.brands.index');
            Route::post('/', [WarehouseBrandController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.brand.create')->name('warehouse.master.brands.store');
            Route::post('/resolve-other', [WarehouseBrandController::class, 'resolveOther'])->middleware('permission_or_snapshot:warehouse.master.brand.create')->name('warehouse.master.brands.resolve-other');
            Route::put('/{id}', [WarehouseBrandController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.brand.update')->name('warehouse.master.brands.update');
            Route::delete('/{id}', [WarehouseBrandController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.brand.delete')->name('warehouse.master.brands.destroy');
        });

        Route::prefix('categories')->group(function () {
            Route::get('/', [WarehouseCategoryController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.category.view')->name('warehouse.master.categories.index');
            Route::post('/', [WarehouseCategoryController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.category.create')->name('warehouse.master.categories.store');
            Route::put('/{id}', [WarehouseCategoryController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.category.update')->name('warehouse.master.categories.update');
            Route::delete('/{id}', [WarehouseCategoryController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.category.delete')->name('warehouse.master.categories.destroy');
        });

        Route::prefix('uoms')->group(function () {
            Route::get('/', [WarehouseUomController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.uom.view')->name('warehouse.master.uoms.index');
            Route::post('/', [WarehouseUomController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.uom.create')->name('warehouse.master.uoms.store');
            Route::put('/{id}', [WarehouseUomController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.uom.update')->name('warehouse.master.uoms.update');
            Route::delete('/{id}', [WarehouseUomController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.uom.delete')->name('warehouse.master.uoms.destroy');
        });

        Route::prefix('chain-supplies')->group(function () {
            Route::get('/', [WarehouseChainSupplyController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.chain_supply.view')->name('warehouse.master.chain-supplies.index');
            Route::post('/', [WarehouseChainSupplyController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.chain_supply.create')->name('warehouse.master.chain-supplies.store');
            Route::put('/{id}', [WarehouseChainSupplyController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.chain_supply.update')->name('warehouse.master.chain-supplies.update');
            Route::delete('/{id}', [WarehouseChainSupplyController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.chain_supply.delete')->name('warehouse.master.chain-supplies.destroy');
        });

        Route::prefix('storages')->group(function () {
            Route::get('/', [WarehouseStorageController::class, 'index'])->middleware('permission_or_snapshot:warehouse.master.storage.view')->name('warehouse.master.storages.index');
            Route::post('/', [WarehouseStorageController::class, 'store'])->middleware('permission_or_snapshot:warehouse.master.storage.create')->name('warehouse.master.storages.store');
            Route::put('/{id}', [WarehouseStorageController::class, 'update'])->middleware('permission_or_snapshot:warehouse.master.storage.update')->name('warehouse.master.storages.update');
            Route::delete('/{id}', [WarehouseStorageController::class, 'destroy'])->middleware('permission_or_snapshot:warehouse.master.storage.delete')->name('warehouse.master.storages.destroy');
        });
    });
