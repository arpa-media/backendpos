<?php
use App\Http\Controllers\Api\V1\Warehouse\Sales\WarehouseSalesOrderController; use App\Http\Middleware\ResolveWarehouseScope; use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/sales')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
 Route::get('/orders',[WarehouseSalesOrderController::class,'index'])->middleware('permission_or_snapshot:warehouse.sales.order.view')->name('warehouse.sales.orders.index');
 Route::get('/orders/options',[WarehouseSalesOrderController::class,'options'])->middleware('permission_or_snapshot:warehouse.sales.order.view')->name('warehouse.sales.orders.options');
 Route::get('/orders/template',[WarehouseSalesOrderController::class,'template'])->middleware('permission_or_snapshot:warehouse.sales.order.view')->name('warehouse.sales.orders.template');
 Route::post('/orders/import',[WarehouseSalesOrderController::class,'import'])->middleware('permission_or_snapshot:warehouse.sales.order.create')->name('warehouse.sales.orders.import');
 Route::post('/orders',[WarehouseSalesOrderController::class,'store'])->middleware('permission_or_snapshot:warehouse.sales.order.create')->name('warehouse.sales.orders.store');
 Route::put('/orders/{id}',[WarehouseSalesOrderController::class,'update'])->middleware('permission_or_snapshot:warehouse.sales.order.update')->name('warehouse.sales.orders.update');
 Route::post('/orders/{id}/submit',[WarehouseSalesOrderController::class,'submit'])->middleware('permission_or_snapshot:warehouse.sales.order.update')->name('warehouse.sales.orders.submit');
 Route::post('/orders/{id}/approve',[WarehouseSalesOrderController::class,'approve'])->middleware('permission_or_snapshot:warehouse.sales.order.approve')->name('warehouse.sales.orders.approve');
 Route::post('/orders/{id}/request-cancel',[WarehouseSalesOrderController::class,'requestCancel'])->middleware('permission_or_snapshot:warehouse.sales.order.cancel')->name('warehouse.sales.orders.request-cancel');
 Route::post('/orders/{id}/cancel',[WarehouseSalesOrderController::class,'cancel'])->middleware('permission_or_snapshot:warehouse.sales.order.cancel')->name('warehouse.sales.orders.cancel');
});
