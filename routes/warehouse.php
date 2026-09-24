<?php

use App\Http\Controllers\Api\V1\Warehouse\WarehouseContextController;
use App\Http\Controllers\Api\V1\Warehouse\WarehouseDashboardController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function () {
        Route::get('/context', WarehouseContextController::class)
            ->middleware('permission_or_snapshot:warehouse.dashboard.view')
            ->name('warehouse.context');

        Route::get('/dashboard', WarehouseDashboardController::class)
            ->middleware('permission_or_snapshot:warehouse.dashboard.view')
            ->name('warehouse.dashboard');
    });
