<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AppUpdateController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SalesController;
use App\Http\Controllers\Api\V1\SaleCancelRequestController;
use App\Http\Controllers\Api\V1\Finance\SalesCollectedController;
use App\Http\Controllers\Api\V1\Finance\FinanceOverviewController;
use App\Http\Controllers\Api\V1\Finance\ItemSummaryController;
use App\Http\Controllers\Api\V1\Finance\CategorySummaryController;
use App\Http\Controllers\Api\V1\Finance\SalesSummaryController;
use App\Http\Controllers\Api\V1\AddonController;
use App\Http\Controllers\Api\V1\TaxController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportPortalController;
use App\Http\Controllers\Api\V1\OwnerOverviewController;
use App\Http\Controllers\Api\V1\UserManagementController;


use App\Http\Resources\Api\V1\Common\ApiResponse;

Route::prefix('v1')->group(function () {

    /**
     * PUBLIC
     */
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::get('/pos-outlets', [OutletController::class, 'posLoginOptions']);
        Route::get('/pos-login-probe', [AuthController::class, 'posLoginProbe']);
    });

    Route::prefix('public')->group(function () {
        Route::get('/app-update/android', [AppUpdateController::class, 'android']);
    });

    /**
     * AUTHENTICATED (Sanctum)
     */
    // outlet_scope MUST run after auth:sanctum so it can lock cashier by user.outlet_id
    // and allow admin to select outlet via header X-Outlet-Id.
    Route::middleware(['auth:sanctum', 'outlet_scope', 'outlet_timezone'])->group(function () {

        /**
         * AUTH (me, logout)
         */
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::put('/change-password', [AuthController::class, 'changePassword']);
            Route::get('/me', [AuthController::class, 'me'])
                ->middleware('permission:auth.me');
            Route::get('/pos-provision-payload', [AuthController::class, 'posProvisionPayload']);
            Route::post('/pos-device-bind', [AuthController::class, 'posDeviceBind']);
        });

        /**
         * DASHBOARD (sesuai UI: /dashboard/summary)
         */
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->middleware('permission_or_snapshot:dashboard.view');

        /**
         * REPORT PORTALS (Patch-02)
         */

        Route::get('/owner-overview', [OwnerOverviewController::class, 'index'])
            ->middleware(['permission:dashboard.view', 'role:admin']);

        Route::get('/owner-overview/sales/{saleId}', [OwnerOverviewController::class, 'saleDetail'])
            ->middleware(['permission:owner_overview.sale_detail.view', 'role:admin']);

        Route::prefix('report-portals/{portalCode}')->group(function () {
            Route::get('/dashboard', [ReportPortalController::class, 'dashboard'])
                ->middleware('permission:dashboard.view');

            Route::get('/ledger', [ReportPortalController::class, 'ledger'])
                ->middleware('permission:report.view');

            Route::get('/recent-sales', [ReportPortalController::class, 'recentSales'])
                ->middleware('permission:report.view');

            Route::get('/item-sold', [ReportPortalController::class, 'itemSold'])
                ->middleware('permission:report.view');

            Route::get('/item-by-product', [ReportPortalController::class, 'itemByProduct'])
                ->middleware('permission:report.view');

            Route::get('/item-by-variant', [ReportPortalController::class, 'itemByVariant'])
                ->middleware('permission:report.view');

            Route::get('/tax', [ReportPortalController::class, 'tax'])
                ->middleware('permission:report.view');

            Route::get('/sales/{saleId}', [ReportPortalController::class, 'saleDetail'])
                ->middleware('permission:sale.view');
        });


        /**
         * POS
         */
        Route::prefix('pos')->group(function () {
            Route::get('/discounts', [PosController::class, 'discounts'])
                ->middleware('permission:discount.view');

            Route::get('/squad-users', [PosController::class, 'squadUsers'])
                ->middleware('permission:discount.view');
        });

    });

    Route::middleware(['pos_sync_auth', 'outlet_scope', 'outlet_timezone'])->group(function () {
        Route::get('/auth/pos-device-session', [AuthController::class, 'posDeviceSession']);

        Route::prefix('pos')->group(function () {
            Route::post('/checkout', [PosController::class, 'checkout'])
                ->middleware('permission:pos.checkout');

            Route::post('/offline-sync-audit', [PosController::class, 'offlineSyncAudit'])
                ->middleware('permission:pos.checkout');

            Route::post('/offline-sync-rescue', [PosController::class, 'offlineSyncRescue'])
                ->middleware('permission:pos.checkout');

            Route::post('/offline-sync-reconcile', [PosController::class, 'offlineSyncReconcile'])
                ->middleware('permission:pos.checkout');
        });
    });

    Route::middleware(['auth:sanctum', 'outlet_scope', 'outlet_timezone'])->group(function () {
        /**
         * CUSTOMERS
         */
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])
                ->middleware('permission:customer.view');

            Route::get('/search', [CustomerController::class, 'search'])
                ->middleware('permission:customer.view');

            Route::get('/{id}', [CustomerController::class, 'show'])
                ->middleware('permission:customer.view');

            Route::get('/{id}/stats', [CustomerController::class, 'stats'])
                ->middleware('permission:customer.view');

            Route::post('/', [CustomerController::class, 'store'])
                ->middleware('permission:customer.create');
        });

         /**
         * ADD-ONS
         */
        Route::prefix('addons')->group(function () {
            Route::get('/', [AddonController::class, 'index'])
                ->middleware('permission:addon.view');

            Route::post('/', [AddonController::class, 'store'])
                ->middleware('permission:addon.create');

            Route::get('/{id}', [AddonController::class, 'show'])
                ->middleware('permission:addon.view');

            Route::put('/{id}', [AddonController::class, 'update'])
                ->middleware('permission:addon.update');

            Route::delete('/{id}', [AddonController::class, 'destroy'])
                ->middleware('permission:addon.delete');
        });

        /**
         * SALES
         */
        Route::get('/sales', [SalesController::class, 'index'])
            ->middleware('permission:sale.view');

        Route::get('/finance/sales-collected', [SalesCollectedController::class, 'index'])
            ->middleware('permission:sale.view');

        Route::get('/finance/sales-collected/items', [SalesCollectedController::class, 'items'])
            ->middleware('permission:sale.view');

        Route::get('/finance/sales-collected/{saleId}', [SalesCollectedController::class, 'detail'])
            ->middleware('permission:sale.view');

        Route::get('/finance/overview', [FinanceOverviewController::class, 'index'])
            ->middleware('permission:report.view');

        Route::get('/finance/item-summary', [ItemSummaryController::class, 'index'])
            ->middleware('permission:report.view');

        Route::get('/finance/category-summary', [CategorySummaryController::class, 'index'])
            ->middleware('permission:report.view');

        Route::get('/finance/sales-summary', [SalesSummaryController::class, 'index'])
            ->middleware('permission:sale.view');

        Route::get('/sales/{id}', [SalesController::class, 'show']);

        // Patch-8 (extra): Admin cancel bill and confirm delete
        Route::post('/sales/{id}/cancel', [SalesController::class, 'cancel'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');

        Route::delete('/sales/{id}', [SalesController::class, 'destroy'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');

        // Patch-8: Cancel bill request flow
        Route::post('/sales/{id}/cancel-requests', [SaleCancelRequestController::class, 'store'])
            ->middleware('permission_or_snapshot:sale.cancel.request');
        Route::post('/sales/{id}/void-requests', [SaleCancelRequestController::class, 'storeVoid'])
            ->middleware('permission_or_snapshot:sale.cancel.request');
        Route::get('/sales/{id}/cancel-requests', [SaleCancelRequestController::class, 'listForSale'])
            ->middleware('permission_or_snapshot:sale.cancel.request');
        Route::get('/sales/{saleId}/cancel-requests/{requestId}', [SaleCancelRequestController::class, 'showForSale'])
            ->middleware('permission_or_snapshot:sale.cancel.request');

        Route::get('/cancel-requests', [SaleCancelRequestController::class, 'index'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');
        Route::get('/cancel-requests/{id}', [SaleCancelRequestController::class, 'show'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');

        Route::post('/cancel-requests/{id}/decide', [SaleCancelRequestController::class, 'decide'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');

        Route::post('/cancel-requests/{id}/confirm-delete', [SaleCancelRequestController::class, 'confirmDelete'])
            ->middleware('permission_or_snapshot:sale.cancel.approve');

        /**
         * REPORTS
         */
        Route::prefix('reports')->middleware('permission:report.view')->group(function () {
            Route::get('/ledger', [ReportController::class, 'ledger']);
            Route::get('/marking', [ReportController::class, 'marking']);
            Route::get('/marking/config', [ReportController::class, 'markingConfig']);
            Route::post('/marking/config', [ReportController::class, 'updateMarkingConfig']);
            Route::post('/marking/apply-existing', [ReportController::class, 'applyExistingMarking']);
            Route::post('/marking/remove-all', [ReportController::class, 'removeAllMarking']);
            Route::post('/marking/{saleId}/toggle', [ReportController::class, 'toggleMarking']);
            Route::get('/item-sold', [ReportController::class, 'itemSold']);
            Route::get('/recent-sales', [ReportController::class, 'recentSales']);
            Route::get('/item-by-product', [ReportController::class, 'itemByProduct']);
            Route::get('/item-by-variant', [ReportController::class, 'itemByVariant']);
            Route::get('/rounding', [ReportController::class, 'rounding']);
            Route::get('/tax', [ReportController::class, 'tax']);
            Route::get('/discount', [ReportController::class, 'discount']);
            Route::get('/cashier-report', [ReportController::class, 'cashierReport']);
            Route::get('/cashier-report/cashiers', [ReportController::class, 'cashierReportCashiers']);
            Route::get('/cashier-report/{cashierId}', [ReportController::class, 'cashierReportByCashier']);
        });

       /**
 * OUTLET
 */
Route::get('/outlet', [OutletController::class, 'show'])
    ->middleware('permission:outlet.view');

// Multi-outlet admin: list all outlets
Route::get("/outlets", [OutletController::class, "index"])
    ->middleware("permission:auth.me");

Route::put('/outlet', [OutletController::class, 'update'])
    ->middleware('permission:outlet.update');

        /**
         * ADMIN (test)
         */
        Route::get('/admin/ping', function () {
            return ApiResponse::ok(['pong' => true], 'OK');
        })->middleware('permission:admin.access');

        Route::prefix('user-management')->middleware('permission:user_management.view')->group(function () {
            Route::get('/overview', [UserManagementController::class, 'overview']);

            Route::middleware('permission:user_management.edit')->group(function () {
                Route::post('/roles', [UserManagementController::class, 'storeRole']);
                Route::put('/roles/{id}', [UserManagementController::class, 'updateRole']);
                Route::post('/levels', [UserManagementController::class, 'storeLevel']);
                Route::put('/levels/{id}', [UserManagementController::class, 'updateLevel']);
                Route::put('/users/{userId}/access', [UserManagementController::class, 'updateUserAccess']);
                Route::put('/users/{userId}/profile', [UserManagementController::class, 'updateUserProfile']);
                Route::put('/portal-permissions', [UserManagementController::class, 'updatePortalPermission']);
                Route::put('/portal-permissions/bulk', [UserManagementController::class, 'bulkPortalPermissions']);
                Route::put('/menu-permissions', [UserManagementController::class, 'updateMenuPermission']);
                Route::put('/menu-permissions/bulk', [UserManagementController::class, 'bulkMenuPermissions']);
            });
        });

        /**
         * CATEGORIES
         */
        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])
                ->middleware('permission:category.view');

            Route::post('/', [CategoryController::class, 'store'])
                ->middleware('permission:category.create');

            Route::get('/{id}', [CategoryController::class, 'show'])
                ->middleware('permission:category.view');

            Route::put('/{id}', [CategoryController::class, 'update'])
                ->middleware('permission:category.update');

            Route::delete('/{id}', [CategoryController::class, 'destroy'])
                ->middleware('permission:category.delete');
        });

        /**
         * PRODUCTS
         */
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index'])
                ->middleware('permission:product.view');

            Route::post('/', [ProductController::class, 'store'])
                ->middleware('permission:product.create');

            Route::get('/{id}', [ProductController::class, 'show'])
                ->middleware('permission:product.view');

            Route::put('/{id}', [ProductController::class, 'update'])
                ->middleware('permission:product.update');

            Route::put('/{id}/outlet-active', [ProductController::class, 'setOutletActive'])
                ->middleware('permission:product.update');

            Route::delete('/{id}', [ProductController::class, 'destroy'])
                ->middleware('permission:product.delete');
        });

                /**
         * DISCOUNTS (Package Discount per outlet)
         */
        Route::prefix('discounts')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\DiscountController::class, 'index'])
                ->middleware('permission:discount.view');

            Route::post('/', [\App\Http\Controllers\Api\V1\DiscountController::class, 'store'])
                ->middleware('permission:discount.create');

            Route::get('/{id}', [\App\Http\Controllers\Api\V1\DiscountController::class, 'show'])
                ->middleware('permission:discount.view');

            Route::put('/{id}', [\App\Http\Controllers\Api\V1\DiscountController::class, 'update'])
                ->middleware('permission:discount.update');

            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\DiscountController::class, 'destroy'])
                ->middleware('permission:discount.delete');
        });

/**
         * PAYMENT METHODS
         */
        Route::prefix('payment-methods')->group(function () {
            Route::get('/', [PaymentMethodController::class, 'index'])
                ->middleware('permission:payment_method.view');

            Route::post('/', [PaymentMethodController::class, 'store'])
                ->middleware('permission:payment_method.create');

            Route::get('/{id}', [PaymentMethodController::class, 'show']);

            Route::put('/{id}', [PaymentMethodController::class, 'update'])
                ->middleware('permission:payment_method.update');

            // Outlet-specific active toggle (admin selects outlet scope)
            Route::put('/{id}/outlet-active', [PaymentMethodController::class, 'setOutletActive'])
                ->middleware('permission:payment_method.update');

            Route::delete('/{id}', [PaymentMethodController::class, 'destroy'])
                ->middleware('permission:payment_method.delete');
        });

        /**
         * TAXES (global)
         */
        Route::prefix('taxes')->group(function () {
            Route::get('/', [TaxController::class, 'index'])
                ->middleware('permission:taxes.view');

            Route::post('/', [TaxController::class, 'store'])
                ->middleware('permission:taxes.create');

            // Used by POS bootstrap (cashier). Do NOT require taxes.* permission.
            // POS always uses default active tax automatically.
            Route::get('/default', [TaxController::class, 'default']);

            Route::get('/{id}', [TaxController::class, 'show'])
                ->middleware('permission:taxes.view');

            Route::put('/{id}', [TaxController::class, 'update'])
                ->middleware('permission:taxes.update');

            Route::put('/{id}/status', [TaxController::class, 'updateStatus'])
                ->middleware('permission:taxes.update');

            Route::delete('/{id}', [TaxController::class, 'destroy'])
                ->middleware('permission:taxes.delete');

            Route::post('/{id}/set-default', [TaxController::class, 'setDefault'])
                ->middleware('permission:taxes.update');
        });

    });
});
