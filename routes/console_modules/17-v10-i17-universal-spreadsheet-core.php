<?php

use App\Http\Controllers\Api\V1\Support\SpreadsheetTransferBatchController;
use Illuminate\Support\Facades\Route;

// Cross-portal infrastructure. Module-specific mutation endpoints remain responsible
// for their own Access Matrix permission in I18; these routes only own the user's batch file/state.
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/spreadsheet-transfers/batches')
    ->group(function (): void {
        Route::post('/', [SpreadsheetTransferBatchController::class, 'store'])->name('spreadsheet-transfers.batches.store');
        Route::get('/{batch}', [SpreadsheetTransferBatchController::class, 'show'])->name('spreadsheet-transfers.batches.show');
        Route::post('/{batch}/cancel', [SpreadsheetTransferBatchController::class, 'cancel'])->name('spreadsheet-transfers.batches.cancel');
        Route::delete('/{batch}', [SpreadsheetTransferBatchController::class, 'destroy'])->name('spreadsheet-transfers.batches.destroy');
    });
