<?php

use App\Http\Controllers\Api\V1\Warehouse\WarehouseV2DashboardController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/v2')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/dashboard', WarehouseV2DashboardController::class)
            ->middleware('permission_or_snapshot:warehouse.dashboard.view|warehouse.sales.dashboard.view|warehouse.finance.dashboard.view')
            ->name('warehouse.v2.dashboard');
    });
