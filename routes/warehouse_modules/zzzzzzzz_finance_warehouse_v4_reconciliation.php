<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV4\WarehouseFinanceReconciliationV4Controller as ReconV4;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/finance-v4/reconciliation')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::get('/', [ReconV4::class,'index'])
            ->middleware('permission_or_snapshot:warehouse.finance.reconciliation.view')
            ->name('warehouse.finance-v4.reconciliation.index');
        Route::post('/run', [ReconV4::class,'run'])
            ->middleware('permission_or_snapshot:warehouse.finance.reconciliation.run')
            ->name('warehouse.finance-v4.reconciliation.run');
        Route::post('/baseline', [ReconV4::class,'baseline'])
            ->middleware('permission_or_snapshot:warehouse.finance.reconciliation.baseline')
            ->name('warehouse.finance-v4.reconciliation.baseline');
        Route::get('/runs/{id}', [ReconV4::class,'show'])
            ->middleware('permission_or_snapshot:warehouse.finance.reconciliation.view')
            ->name('warehouse.finance-v4.reconciliation.show');
    });
