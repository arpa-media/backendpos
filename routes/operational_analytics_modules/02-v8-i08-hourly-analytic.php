<?php

use App\Http\Controllers\Api\V1\Operational\OperationalSalesAnalyticController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'api',
    'auth:sanctum',
    'outlet_scope',
    'outlet_timezone',
    'permission_or_snapshot:operational.sales_analytic.hourly.view',
])->prefix('api/v1/operational/sales-analytic')->group(function (): void {
    Route::get('/hourly', [OperationalSalesAnalyticController::class, 'hourly'])
        ->name('operational.sales-analytic.hourly');
});

Route::middleware([
    'api',
    'auth:sanctum',
    'outlet_scope',
    'outlet_timezone',
    'permission_or_snapshot:operational.sales_analytic.hourly_summary.view',
])->prefix('api/v1/operational/sales-analytic')->group(function (): void {
    Route::get('/hourly-summary', [OperationalSalesAnalyticController::class, 'hourlySummary'])
        ->name('operational.sales-analytic.hourly-summary');
});
