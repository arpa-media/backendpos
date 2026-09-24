<?php

use App\Http\Controllers\Api\V1\Warehouse\ProductionV3\WarehouseProductionResultLifecycleI05Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/production-v3')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::post('/orders/{id}/results/{resultId}/reject', [WarehouseProductionResultLifecycleI05Controller::class, 'reject'])
            ->middleware('permission_or_snapshot:warehouse.production.result.reject,warehouse.production.approve,warehouse.production.update')
            ->name('warehouse.production.results.reject.i05');
        Route::delete('/orders/{id}/results/{resultId}', [WarehouseProductionResultLifecycleI05Controller::class, 'destroy'])
            ->middleware('permission_or_snapshot:warehouse.production.result.delete,warehouse.production.delete')
            ->name('warehouse.production.results.delete.i05');
    });
