<?php
use App\Http\Controllers\Api\V1\Warehouse\LogisticsV3\WarehouseLogisticsFinanceIteration04Controller as GrC;
use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseFinancePaymentPostingIteration04Controller as PayC;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/logistics-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/goods-receipts/{id}/complete',[GrC::class,'completeGoodsReceipt'])->middleware('permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate')->name('warehouse.logistics-v3.goods-receipts.complete');
});
Route::prefix('api/v1/warehouse/finance-v3-lifecycle')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/incoming/invoices/{source}/{id}/payments',[PayC::class,'payment'])->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction','incoming')->middleware('permission_or_snapshot:warehouse.purchasing.invoice.update')->name('warehouse.finance-v3-lifecycle.incoming.payment');
    Route::post('/outgoing/invoices/{source}/{id}/payments',[PayC::class,'payment'])->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction','outgoing')->middleware('permission_or_snapshot:warehouse.finance.invoice.update')->name('warehouse.finance-v3-lifecycle.outgoing.payment');
});
