<?php

use App\Http\Controllers\Api\V1\Finance\FinanceSummaryXlsxExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::post('/summary-xlsx-export', [FinanceSummaryXlsxExportController::class, 'store'])
        ->middleware('permission_or_snapshot:report.view,sale.view')
        ->name('finance.summary-xlsx-export');
});
