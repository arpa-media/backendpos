<?php

use App\Http\Controllers\Api\V1\StockInventory\TransactionResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/reset-transactions')->middleware(['api','auth:sanctum'])->group(function (): void {
    Route::get('/', [TransactionResetController::class, 'preview'])
        ->middleware('permission_or_snapshot:purchasing.reset_transactions.view')
        ->name('purchasing.reset-transactions.preview');
    Route::post('/', [TransactionResetController::class, 'reset'])
        ->middleware('permission_or_snapshot:purchasing.reset_transactions.update')
        ->name('purchasing.reset-transactions.run');
});
