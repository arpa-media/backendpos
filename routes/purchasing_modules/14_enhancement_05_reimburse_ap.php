<?php

use App\Http\Controllers\Api\V1\Purchasing\ReimbursePayableController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchasing/reimburse-payables')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/', [ReimbursePayableController::class, 'index'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.view')
            ->name('purchasing.enh05.reimburse-payables.index');
        Route::get('/execution/{executionId}/receipt', [ReimbursePayableController::class, 'receipt'])
            ->middleware('permission_or_snapshot:purchasing.reimburse_payment.view,purchasing.account_payable.view')
            ->name('purchasing.enh05.reimburse-payables.receipt');
        Route::get('/{id}', [ReimbursePayableController::class, 'show'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.view')
            ->name('purchasing.enh05.reimburse-payables.show');
        Route::post('/{id}/confirm-paid', [ReimbursePayableController::class, 'confirmPaid'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.create,purchasing.account_payable.payment')
            ->name('purchasing.enh05.reimburse-payables.confirm-paid');
    });
