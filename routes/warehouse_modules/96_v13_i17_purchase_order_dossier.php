<?php
use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehousePurchaseOrderDossierV13Controller;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::get('api/v1/warehouse/purchasing-v3/purchase-orders/{id}/dossier/pdf', WarehousePurchaseOrderDossierV13Controller::class)
    ->middleware(['api','auth:sanctum',ResolveWarehouseScope::class,'permission_or_snapshot:warehouse.procurement.order.view'])
    ->name('warehouse.v13.i17.purchase-order.dossier.pdf');
