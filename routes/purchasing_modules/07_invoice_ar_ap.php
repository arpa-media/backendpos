<?php

use App\Http\Controllers\Api\V1\Purchasing\AccountLedgerController;
use App\Http\Controllers\Api\V1\Purchasing\InvoiceWorkflowController;
use App\Http\Controllers\Api\V1\Warehouse\MasterData\WarehouseSupplierController;
use Illuminate\Support\Facades\Route;

$invoiceRoutes = [
    'incoming' => [
        'legacy' => 'purchasing.incoming_invoice',
        'account' => 'purchasing.account_payable',
    ],
    'outgoing' => [
        'legacy' => 'purchasing.outgoing_invoice',
        'account' => 'purchasing.account_receivable',
    ],
];

foreach ($invoiceRoutes as $direction => $permissions) {
    $legacy = $permissions['legacy'];
    $account = $permissions['account'];

    Route::prefix('api/v1/purchasing/invoices/' . $direction)
        ->middleware(['api', 'auth:sanctum'])
        ->group(function () use ($direction, $legacy, $account): void {
            Route::get('/catalogs', [InvoiceWorkflowController::class, 'catalogs'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.view,{$account}.view")
                ->name("purchasing.invoice.{$direction}.catalogs");
            Route::get('/', [InvoiceWorkflowController::class, 'index'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.view,{$account}.view")
                ->name("purchasing.invoice.{$direction}.index");
            Route::post('/', [InvoiceWorkflowController::class, 'store'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.create,{$account}.create")
                ->name("purchasing.invoice.{$direction}.store");
            Route::get('/{id}', [InvoiceWorkflowController::class, 'show'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.view,{$account}.view")
                ->name("purchasing.invoice.{$direction}.show");
            Route::put('/{id}', [InvoiceWorkflowController::class, 'update'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.update,{$account}.update")
                ->name("purchasing.invoice.{$direction}.update");
            Route::delete('/{id}', [InvoiceWorkflowController::class, 'destroy'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.delete,{$account}.delete")
                ->name("purchasing.invoice.{$direction}.destroy");
            Route::post('/{id}/issue', [InvoiceWorkflowController::class, 'issue'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.update,{$legacy}.issue,{$account}.update,{$account}.issue")
                ->name("purchasing.invoice.{$direction}.issue");
            Route::post('/{id}/payments', [InvoiceWorkflowController::class, 'payment'])
                ->defaults('direction', $direction)
                ->middleware("permission_or_snapshot:{$legacy}.create,{$legacy}.payment,{$account}.create,{$account}.payment")
                ->name("purchasing.invoice.{$direction}.payment");
        });
}

$ledgers = [
    'account-payable' => 'purchasing.account_payable',
    'account-receivable' => 'purchasing.account_receivable',
];

foreach ($ledgers as $ledger => $permission) {
    Route::prefix('api/v1/purchasing/ledger/' . $ledger)
        ->middleware(['api', 'auth:sanctum'])
        ->group(function () use ($ledger, $permission): void {
            Route::get('/catalogs', [AccountLedgerController::class, 'catalogs'])
                ->defaults('ledger', $ledger)
                ->middleware("permission_or_snapshot:{$permission}.view")
                ->name("purchasing.ledger.{$ledger}.catalogs");
            Route::get('/payments', [AccountLedgerController::class, 'payments'])
                ->defaults('ledger', $ledger)
                ->middleware("permission_or_snapshot:{$permission}.view")
                ->name("purchasing.ledger.{$ledger}.payments");
            Route::get('/', [AccountLedgerController::class, 'index'])
                ->defaults('ledger', $ledger)
                ->middleware("permission_or_snapshot:{$permission}.view")
                ->name("purchasing.ledger.{$ledger}.index");
            Route::get('/{id}', [AccountLedgerController::class, 'show'])
                ->defaults('ledger', $ledger)
                ->middleware("permission_or_snapshot:{$permission}.view")
                ->name("purchasing.ledger.{$ledger}.show");
            Route::post('/{id}/payments', [AccountLedgerController::class, 'payment'])
                ->defaults('ledger', $ledger)
                ->middleware("permission_or_snapshot:{$permission}.create,{$permission}.payment")
                ->name("purchasing.ledger.{$ledger}.payment");
        });
}

// Iterasi 08: Vendor Data in Account Payable reuses pur_supplier_sources.
// Warehouse keeps its own menu/permission over the same canonical master data.
Route::prefix('api/v1/purchasing/vendors')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/', [WarehouseSupplierController::class, 'index'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.view')
            ->name('purchasing.account-payable.vendors.index');
        Route::post('/', [WarehouseSupplierController::class, 'store'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.create')
            ->name('purchasing.account-payable.vendors.store');
        Route::put('/{id}', [WarehouseSupplierController::class, 'update'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.update')
            ->name('purchasing.account-payable.vendors.update');
        Route::delete('/{id}', [WarehouseSupplierController::class, 'destroy'])
            ->middleware('permission_or_snapshot:purchasing.account_payable.delete')
            ->name('purchasing.account-payable.vendors.destroy');
    });
