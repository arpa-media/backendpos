<?php

use App\Http\Controllers\Api\V1\Operational\OperationalSalesAnalyticController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'api',
    'auth:sanctum',
    'outlet_scope',
    'outlet_timezone',
    'permission_or_snapshot:operational.sales_analytic.daily.view',
])->prefix('api/v1/operational/sales-analytic')->group(function (): void {
    Route::get('/daily', [OperationalSalesAnalyticController::class, 'daily'])
        ->name('operational.sales-analytic.daily');
});
