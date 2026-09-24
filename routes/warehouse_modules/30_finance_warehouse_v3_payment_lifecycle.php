<?php
use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseInvoicePaymentLifecycleController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/finance-v3-lifecycle')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    foreach(['incoming','outgoing'] as $direction){
        $view=$direction==='incoming'?'warehouse.purchasing.invoice.view':'warehouse.finance.invoice.view';
        $update=$direction==='incoming'?'warehouse.purchasing.invoice.update':'warehouse.finance.invoice.update';
        Route::get("/{$direction}/invoices",[WarehouseInvoicePaymentLifecycleController::class,'index'])->defaults('direction',$direction)->middleware("permission_or_snapshot:{$view}")->name("warehouse.finance-v3-lifecycle.{$direction}.index");
        Route::get("/{$direction}/invoices/{source}/{id}",[WarehouseInvoicePaymentLifecycleController::class,'show'])->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction',$direction)->middleware("permission_or_snapshot:{$view}")->name("warehouse.finance-v3-lifecycle.{$direction}.show");
        Route::post("/{$direction}/invoices/{source}/{id}/payments",[WarehouseInvoicePaymentLifecycleController::class,'payment'])->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction',$direction)->middleware("permission_or_snapshot:{$update}")->name("warehouse.finance-v3-lifecycle.{$direction}.payment");
    }
});
