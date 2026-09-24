<?php

use App\Http\Controllers\Api\V1\Finance\FinanceGeneralPostingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP POS FINAL I02 - General Posting single source / historical reconcile
|--------------------------------------------------------------------------
|
| Additive route only. Existing Finance routes are not replaced.
| Historical reconciliation is intentionally throttled because it can touch
| multiple journal/link rows in one request.
|
*/
Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
    Route::post('/finance/general-posting/reconcile-historical', [FinanceGeneralPostingController::class, 'reconcileHistorical'])
        ->middleware(['permission_or_snapshot:finance.general_posting.update,finance.general_posting.reopen', 'throttle:2,1'])
        ->name('finance.general-posting.reconcile-historical.i02');
});
