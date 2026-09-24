<?php

use App\Http\Controllers\Api\V1\Purchasing\PurchasingShellController;
use Illuminate\Support\Facades\Route;

$viewPermissions = implode(',', [
    'purchasing.dashboard.view',
    'purchasing.fund_request.view',
    'purchasing.order_management.view',
    'purchasing.realization_order.view',
    'purchasing.purchase_order.view',
    'purchasing.service_order.view',
    'purchasing.reimburse_order.view',
    'purchasing.goods_receipt.view',
    'purchasing.service_acceptance.view',
    'purchasing.reimburse_payment.view',
    'purchasing.incoming_invoice.view',
    'purchasing.outgoing_invoice.view',
    'purchasing.account_payable.view',
    'purchasing.account_receivable.view',
]);

Route::prefix('api/v1/purchasing')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () use ($viewPermissions): void {
        Route::get('/shell/context', [PurchasingShellController::class, 'context'])
            ->middleware('permission_or_snapshot:' . $viewPermissions)
            ->name('purchasing.shell.context');

        Route::get('/shell/modules/{moduleKey}', [PurchasingShellController::class, 'module'])
            ->where('moduleKey', '[a-z0-9-]+')
            ->middleware('permission_or_snapshot:' . $viewPermissions)
            ->name('purchasing.shell.module');
    });
