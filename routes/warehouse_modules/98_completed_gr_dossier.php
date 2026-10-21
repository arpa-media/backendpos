<?php

use App\Http\Controllers\Api\V1\Warehouse\Documents\WarehouseCompletedGrDossierController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;

Route::get('api/v1/warehouse/logistics-v3/goods-receipts/{id}/dossier/pdf', WarehouseCompletedGrDossierController::class)
    ->middleware(['api', 'auth:sanctum', ResolveWarehouseScope::class,
        'permission_or_snapshot:warehouse.receiving.goods_receipt.print,warehouse.receiving.monitor.view'])
    ->name('warehouse.logistics-v3.goods-receipts.dossier-pdf');
