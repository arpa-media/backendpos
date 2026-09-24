<?php

use App\Http\Controllers\Api\V1\Purchasing\OrderWorkflowController;
use App\Http\Controllers\Api\V1\Purchasing\OrderManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/order-management')
    ->middleware(['api', 'auth:sanctum', 'permission_or_snapshot:purchasing.order_management.view,purchasing.purchase_order.view,purchasing.service_order.view,purchasing.reimburse_order.view'])
    ->group(function (): void {
        Route::get('/overview', [OrderManagementController::class, 'overview'])
            ->name('purchasing.order-management.overview');
    });

$viewPermissions = implode(',', [
    'purchasing.order_management.view',
    'purchasing.purchase_order.view',
    'purchasing.service_order.view',
    'purchasing.reimburse_order.view',
]);

Route::prefix('api/v1/purchasing/order-workflow/{orderKind}')
    ->where(['orderKind' => 'purchase-order|service-order|reimburse-order'])
    ->middleware(['api', 'auth:sanctum', 'permission_or_snapshot:' . $viewPermissions])
    ->group(function (): void {
        Route::get('/catalogs', [OrderWorkflowController::class, 'catalogs'])
            ->name('purchasing.order-workflow.catalogs');

        Route::get('/', [OrderWorkflowController::class, 'index'])
            ->name('purchasing.order-workflow.index');

        Route::post('/suppliers/minimal', [OrderWorkflowController::class, 'storeMinimalSupplier'])
            ->name('purchasing.order-workflow.suppliers.minimal');

        Route::post('/', [OrderWorkflowController::class, 'store'])
            ->name('purchasing.order-workflow.store');

        Route::post('/{id}/submit', [OrderWorkflowController::class, 'submit'])
            ->name('purchasing.order-workflow.submit');

        Route::post('/{id}/approve-finance-1', [OrderWorkflowController::class, 'approveFinance1'])
            ->name('purchasing.order-workflow.approve-finance-1');

        Route::post('/{id}/approve-finance-2', [OrderWorkflowController::class, 'approveFinance2'])
            ->name('purchasing.order-workflow.approve-finance-2');

        Route::post('/{id}/reject', [OrderWorkflowController::class, 'reject'])
            ->name('purchasing.order-workflow.reject');

        Route::get('/{id}/timeline', [OrderWorkflowController::class, 'timeline'])
            ->name('purchasing.order-workflow.timeline');

        Route::get('/{id}', [OrderWorkflowController::class, 'show'])
            ->name('purchasing.order-workflow.show');

        Route::put('/{id}', [OrderWorkflowController::class, 'update'])
            ->name('purchasing.order-workflow.update');

        Route::delete('/{id}', [OrderWorkflowController::class, 'destroy'])
            ->name('purchasing.order-workflow.destroy');
    });
