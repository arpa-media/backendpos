<?php
use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehousePackageBarcodeController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/inventory/package-barcodes')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function (): void {
 Route::get('/',[WarehousePackageBarcodeController::class,'index'])->middleware('permission_or_snapshot:warehouse.inventory.package.view')->name('warehouse.inventory.package-barcodes.index');
 Route::get('/options',[WarehousePackageBarcodeController::class,'options'])->middleware('permission_or_snapshot:warehouse.inventory.package.view')->name('warehouse.inventory.package-barcodes.options');
 Route::post('/consume',[WarehousePackageBarcodeController::class,'consume'])->middleware('permission_or_snapshot:warehouse.inventory.package.update')->name('warehouse.inventory.package-barcodes.consume');
 Route::post('/opname-count',[WarehousePackageBarcodeController::class,'countOpname'])->middleware('permission_or_snapshot:warehouse.inventory.package.update')->name('warehouse.inventory.package-barcodes.opname-count');
 Route::put('/{id}/configure',[WarehousePackageBarcodeController::class,'configure'])->middleware('permission_or_snapshot:warehouse.inventory.package.update')->name('warehouse.inventory.package-barcodes.configure');
 Route::post('/{id}/split',[WarehousePackageBarcodeController::class,'split'])->middleware('permission_or_snapshot:warehouse.inventory.package.create')->name('warehouse.inventory.package-barcodes.split');
 Route::post('/{id}/relabel',[WarehousePackageBarcodeController::class,'relabel'])->middleware('permission_or_snapshot:warehouse.inventory.package.create')->name('warehouse.inventory.package-barcodes.relabel');
 Route::get('/{id}/events',[WarehousePackageBarcodeController::class,'events'])->middleware('permission_or_snapshot:warehouse.inventory.package.view')->name('warehouse.inventory.package-barcodes.events');
});
