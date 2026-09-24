<?php

use App\Http\Controllers\Api\V1\Purchasing\StockRequestFundApprovalController;
use Illuminate\Support\Facades\Route;

// URI yang sama sengaja diregistrasikan setelah Iterasi 03. Laravel mengganti
// route method+URI sehingga approval request lain tetap memakai service yang
// sama, sementara Stock Request memperoleh side-effect draft PO yang atomik.
Route::prefix('api/v1/purchasing/fund-requests')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::post('/{id}/approve', [StockRequestFundApprovalController::class, 'approve'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.approve');

        Route::post('/{id}/reject', [StockRequestFundApprovalController::class, 'reject'])
            ->middleware('permission_or_snapshot:purchasing.fund_request.view')
            ->name('purchasing.fund-requests.reject');
    });
