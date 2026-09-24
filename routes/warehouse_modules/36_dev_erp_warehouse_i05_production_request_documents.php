<?php

use App\Http\Controllers\Api\V1\Warehouse\Documents\I05\WarehouseProductionRequestCanonicalI05Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/warehouse/canonical-documents-i05/production-request')
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class,'permission_or_snapshot:warehouse.production_request.view'])
    ->group(function (): void {
        Route::get('/{id}/pdf', [WarehouseProductionRequestCanonicalI05Controller::class, 'pdf'])->name('warehouse.i05.production-request.pdf');
        Route::post('/bulk', [WarehouseProductionRequestCanonicalI05Controller::class, 'bulk'])->name('warehouse.i05.production-request.bulk');
    });
