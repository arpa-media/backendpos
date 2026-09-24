<?php

use App\Http\Controllers\Api\V1\StockInventory\ParStockController;
use App\Http\Controllers\Api\V1\StockInventory\StockCategoryController;
use App\Http\Controllers\Api\V1\StockInventory\StockCancellationController;
use App\Http\Controllers\Api\V1\StockInventory\StockDashboardController;
use App\Http\Controllers\Api\V1\StockInventory\StockOpnameController;
use App\Http\Controllers\Api\V1\StockInventory\StockSkuController;
use App\Http\Controllers\Api\V1\StockInventory\StockRequestController;
use App\Http\Controllers\Api\V1\StockInventory\StockRequestApprovalController;
use App\Http\Controllers\Api\V1\StockInventory\StockUomController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/stock-inventory')
    ->middleware(['api', 'auth:sanctum', 'outlet_scope', 'outlet_timezone'])
    ->group(function () {
        Route::get('/dashboard', [StockDashboardController::class, 'index'])
            ->middleware('permission_or_snapshot:stock_inventory.dashboard.view');

        Route::prefix('uoms')->group(function () {
            Route::get('/', [StockUomController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.uom.view');
            Route::post('/', [StockUomController::class, 'store'])
                ->middleware('permission_or_snapshot:stock_inventory.uom.create');
            Route::put('/{id}', [StockUomController::class, 'update'])
                ->middleware('permission_or_snapshot:stock_inventory.uom.update');
            Route::delete('/{id}', [StockUomController::class, 'destroy'])
                ->middleware('permission_or_snapshot:stock_inventory.uom.delete');
        });

        Route::prefix('categories')->group(function () {
            Route::get('/', [StockCategoryController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.category.view');
            Route::post('/', [StockCategoryController::class, 'store'])
                ->middleware('permission_or_snapshot:stock_inventory.category.create');
            Route::put('/{id}', [StockCategoryController::class, 'update'])
                ->middleware('permission_or_snapshot:stock_inventory.category.update');
            Route::delete('/{id}', [StockCategoryController::class, 'destroy'])
                ->middleware('permission_or_snapshot:stock_inventory.category.delete');
        });

        Route::prefix('skus')->group(function () {
            Route::get('/template', [StockSkuController::class, 'template'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.view');
            Route::get('/export', [StockSkuController::class, 'export'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.view');
            Route::post('/import', [StockSkuController::class, 'import'])
                ->middleware([
                    'permission_or_snapshot:stock_inventory.sku.create',
                    'permission_or_snapshot:stock_inventory.sku.update',
                ]);
            Route::get('/options', [StockSkuController::class, 'options'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.view,stock_inventory.par_stock.view,stock_inventory.opname.view');
            Route::get('/', [StockSkuController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.view');
            Route::post('/', [StockSkuController::class, 'store'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.create');
            Route::put('/{id}', [StockSkuController::class, 'update'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.update');
            Route::delete('/{id}', [StockSkuController::class, 'destroy'])
                ->middleware('permission_or_snapshot:stock_inventory.sku.delete');
        });

        Route::prefix('par-stocks')->group(function () {
            Route::get('/catalogs', [ParStockController::class, 'catalogs'])
                ->middleware('permission_or_snapshot:stock_inventory.par_stock.view,stock_inventory.par_stock.create');
            Route::get('/', [ParStockController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.par_stock.view');
            Route::put('/', [ParStockController::class, 'upsert'])
                ->middleware('permission_or_snapshot:stock_inventory.par_stock.create,stock_inventory.par_stock.update');
            Route::delete('/{skuId}', [ParStockController::class, 'destroy'])
                ->middleware('permission_or_snapshot:stock_inventory.par_stock.delete');
        });

        Route::prefix('stock-opnames')->group(function () {
            Route::get('/', [StockOpnameController::class, 'show'])
                ->middleware('permission_or_snapshot:stock_inventory.opname.view');
            Route::post('/', [StockOpnameController::class, 'store'])
                ->middleware('permission_or_snapshot:stock_inventory.opname.create,stock_inventory.opname.update');
            Route::post('/submit', [StockOpnameController::class, 'submit'])
                ->middleware('permission_or_snapshot:stock_inventory.opname.update');
            Route::post('/{id}/cancellation-request', [StockCancellationController::class, 'requestStockOpname'])
                ->middleware('permission_or_snapshot:stock_inventory.opname.update');
        });


        // STOCK-INVENTORY-ITERASI-02: outlet request draft, submit, and timeline.
        Route::prefix('request-stocks')->group(function () {
            Route::get('/catalogs', [StockRequestController::class, 'catalogs'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.view,stock_inventory.request_stock.create');
            Route::post('/draft-from-opname', [StockRequestController::class, 'autoDraft'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.create');
            Route::get('/', [StockRequestController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.view');
            Route::get('/{id}', [StockRequestController::class, 'show'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.view');
            Route::put('/{id}', [StockRequestController::class, 'update'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.update');
            Route::post('/{id}/submit', [StockRequestController::class, 'submit'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.update');
            Route::post('/{id}/cancellation-request', [StockCancellationController::class, 'requestStockRequest'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.update');
            Route::get('/{id}/timeline', [StockRequestController::class, 'timeline'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.view');
            Route::delete('/{id}', [StockRequestController::class, 'destroy'])
                ->middleware('permission_or_snapshot:stock_inventory.request_stock.delete');
        });

        // Purchasing is management scoped. Frontend sends X-Skip-Outlet-Scope and
        // the Access Matrix permission remains the authorization source of truth.
        Route::prefix('request-approvals')->group(function () {
            Route::get('/supplier-sources', [StockRequestApprovalController::class, 'supplierSources'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.view');
            Route::post('/supplier-sources', [StockRequestApprovalController::class, 'storeSupplierSource'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.create');
            Route::put('/supplier-sources/{id}', [StockRequestApprovalController::class, 'updateSupplierSource'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.update');
            Route::get('/price-suggestion', [StockRequestApprovalController::class, 'priceSuggestion'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.view');
            Route::get('/', [StockRequestApprovalController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.view');
            Route::get('/{id}', [StockRequestApprovalController::class, 'show'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.view');
            Route::post('/{id}/decide', [StockRequestApprovalController::class, 'decide'])
                ->middleware('permission_or_snapshot:stock_inventory.request_approval.update');
        });

        Route::prefix('cancellation-approvals')->group(function () {
            Route::get('/', [StockCancellationController::class, 'index'])
                ->middleware('permission_or_snapshot:stock_inventory.cancellation_approval.view');
            Route::get('/{id}', [StockCancellationController::class, 'show'])
                ->middleware('permission_or_snapshot:stock_inventory.cancellation_approval.view');
            Route::post('/{id}/decide', [StockCancellationController::class, 'decide'])
                ->middleware('permission_or_snapshot:stock_inventory.cancellation_approval.update');
        });
    });
