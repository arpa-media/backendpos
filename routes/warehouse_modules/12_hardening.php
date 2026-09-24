<?php

use App\Http\Controllers\Api\V1\Warehouse\Hardening\WarehouseHardeningController;
use App\Http\Controllers\Api\V1\Warehouse\Hardening\WarehouseMobileScannerController;
use App\Http\Middleware\ResolveWarehouseScope;
use App\Http\Middleware\WarehouseHardeningMiddleware;
use Illuminate\Support\Facades\Route;

// Additive global hardening for every route that uses the API middleware group.
// The middleware itself is a no-op outside /api/v1/warehouse, so other modules remain unaffected.
$router = app('router');
$apiGroup = $router->getMiddlewareGroups()['api'] ?? [];
if (! in_array(WarehouseHardeningMiddleware::class, $apiGroup, true)) {
    $router->pushMiddlewareToGroup('api', WarehouseHardeningMiddleware::class);
}

Route::prefix('api/v1/warehouse')
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class])
    ->group(function (): void {
        Route::prefix('hardening')->group(function (): void {
            Route::get('/overview', [WarehouseHardeningController::class, 'overview'])
                ->middleware('permission_or_snapshot:warehouse.go_live.view')
                ->name('warehouse.hardening.overview');
            Route::post('/health-runs', [WarehouseHardeningController::class, 'runHealth'])
                ->middleware('permission_or_snapshot:warehouse.hardening.health.run,warehouse.go_live.run')
                ->name('warehouse.hardening.health.run');
            Route::post('/uat-runs', [WarehouseHardeningController::class, 'runUat'])
                ->middleware('permission_or_snapshot:warehouse.hardening.uat.run,warehouse.go_live.run')
                ->name('warehouse.hardening.uat.run');
            Route::put('/uat-runs/{runId}/cases/{caseId}', [WarehouseHardeningController::class, 'updateUatCase'])
                ->middleware('permission_or_snapshot:warehouse.go_live.manage')
                ->name('warehouse.hardening.uat.case.update');
            Route::get('/go-live-gate', [WarehouseHardeningController::class, 'goLiveGate'])
                ->middleware('permission_or_snapshot:warehouse.hardening.go_live.run,warehouse.go_live.view')
                ->name('warehouse.hardening.go-live');
        });

        Route::prefix('mobile-scanner')->group(function (): void {
            Route::get('/tasks', [WarehouseMobileScannerController::class, 'tasks'])
                ->middleware('permission_or_snapshot:warehouse.mobile_scanner.view')
                ->name('warehouse.mobile-scanner.tasks');
            Route::post('/tokens', [WarehouseMobileScannerController::class, 'issueTokens'])
                ->middleware('permission_or_snapshot:warehouse.hardening.scan_token.issue,warehouse.mobile_scanner.scan,warehouse.mobile_scanner.run')
                ->name('warehouse.mobile-scanner.tokens');
            Route::post('/scan', [WarehouseMobileScannerController::class, 'scan'])
                ->middleware('permission_or_snapshot:warehouse.mobile_scanner.scan,warehouse.mobile_scanner.run')
                ->name('warehouse.mobile-scanner.scan');
        });
    });
