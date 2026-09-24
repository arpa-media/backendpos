<?php
use App\Http\Controllers\Api\V1\StockInventory\ActualStockResetController;
use App\Http\Controllers\Api\V1\StockInventory\TransactionResetController;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1/stock-inventory')->middleware(['api','auth:sanctum','outlet_scope','outlet_timezone'])->group(function():void{
    Route::get('/actual-stock-reset/preview',[ActualStockResetController::class,'preview'])->middleware('permission_or_snapshot:stock_inventory.actual_stock_reset.view')->name('stock-inventory.actual-stock-reset.preview');
    Route::post('/actual-stock-reset',[ActualStockResetController::class,'reset'])->middleware('permission_or_snapshot:stock_inventory.actual_stock_reset.update')->name('stock-inventory.actual-stock-reset.run');
});
Route::prefix('api/v1/stock-inventory/transaction-reset')->middleware(['api','auth:sanctum'])->group(function():void{
    Route::get('/',[TransactionResetController::class,'preview'])->middleware('permission_or_snapshot:stock_inventory.reset_transactions.view')->name('stock-inventory.transaction-reset.preview');
    Route::post('/',[TransactionResetController::class,'reset'])->middleware('permission_or_snapshot:stock_inventory.reset_transactions.update')->name('stock-inventory.transaction-reset.run');
});
