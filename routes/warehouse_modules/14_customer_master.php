<?php
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseCustomerClassificationController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseCustomerController;
use App\Http\Middleware\ResolveWarehouseScope;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/warehouse/master')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function (): void {
 Route::prefix('customers')->group(function (): void {
  Route::get('/',[WarehouseCustomerController::class,'index'])->middleware('permission_or_snapshot:warehouse.master.customer.view')->name('warehouse.master.customers.index');
  Route::get('/options',[WarehouseCustomerController::class,'options'])->middleware('permission_or_snapshot:warehouse.master.customer.view')->name('warehouse.master.customers.options');
  Route::get('/template',[WarehouseCustomerController::class,'template'])->middleware('permission_or_snapshot:warehouse.master.customer.view')->name('warehouse.master.customers.template');
  Route::post('/import',[WarehouseCustomerController::class,'import'])->middleware('permission_or_snapshot:warehouse.master.customer.create')->name('warehouse.master.customers.import');
  Route::post('/',[WarehouseCustomerController::class,'store'])->middleware('permission_or_snapshot:warehouse.master.customer.create')->name('warehouse.master.customers.store');
  Route::put('/{id}',[WarehouseCustomerController::class,'update'])->middleware('permission_or_snapshot:warehouse.master.customer.update')->name('warehouse.master.customers.update');
  Route::delete('/{id}',[WarehouseCustomerController::class,'destroy'])->middleware('permission_or_snapshot:warehouse.master.customer.delete')->name('warehouse.master.customers.destroy');
 });
 Route::prefix('customer-groups')->group(function (): void {
  Route::get('/',[WarehouseCustomerClassificationController::class,'groups'])->middleware('permission_or_snapshot:warehouse.master.customer.view')->name('warehouse.master.customer-groups.index');
  Route::post('/',[WarehouseCustomerClassificationController::class,'storeGroup'])->middleware('permission_or_snapshot:warehouse.master.customer.create')->name('warehouse.master.customer-groups.store');
  Route::put('/{id}',[WarehouseCustomerClassificationController::class,'updateGroup'])->middleware('permission_or_snapshot:warehouse.master.customer.update')->name('warehouse.master.customer-groups.update');
 });
 Route::prefix('customer-price-tiers')->group(function (): void {
  Route::get('/',[WarehouseCustomerClassificationController::class,'tiers'])->middleware('permission_or_snapshot:warehouse.master.customer.view')->name('warehouse.master.customer-price-tiers.index');
  Route::post('/',[WarehouseCustomerClassificationController::class,'storeTier'])->middleware('permission_or_snapshot:warehouse.master.customer.create')->name('warehouse.master.customer-price-tiers.store');
  Route::put('/{id}',[WarehouseCustomerClassificationController::class,'updateTier'])->middleware('permission_or_snapshot:warehouse.master.customer.update')->name('warehouse.master.customer-price-tiers.update');
 });
});
