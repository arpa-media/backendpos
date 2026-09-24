<?php

use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseInvoicePaymentLifecycleController;
use App\Http\Controllers\Api\V1\Warehouse\Ledger\WarehouseLedgerAdjustmentController;
use App\Http\Controllers\Api\V1\Warehouse\LogisticsV3\WarehouseLogisticsV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\LogisticsV3\WarehouseLogisticsFinanceV4BridgeController;
use App\Http\Controllers\Api\V1\Warehouse\FinanceV3\WarehouseFinancePaymentPostingIteration04Controller;
use App\Http\Controllers\Api\V1\Warehouse\ProductionV3\WarehouseProductionV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehousePurchaseOrderV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\PurchasingV3\WarehousePurchaseRequestV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\SalesCustomerV4\WarehouseSalesCustomerController;
use App\Http\Controllers\Api\V1\Warehouse\SalesTransferV3\WarehouseSalesTransferV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\SalesV3\WarehouseSalesDemandV3Controller;
use App\Http\Controllers\Api\V1\Warehouse\TransferStockV4\WarehouseTransferStockController;
use App\Http\Middleware\ResolveWarehouseScope;
use App\Http\Middleware\WarehouseFinanceAutoPostingV4 as AutoFinance;
use Illuminate\Support\Facades\Route;

/*
 | Warehouse v4 Iterasi 05 cut-over routes.
 | Filename sengaja zzzzzz agar dimuat SETELAH 99_finance_iteration_04_auto_posting_hooks.php.
 | Dengan RouteCollection Laravel, method+URI yang sama digantikan oleh definisi terakhir.
 | Operational controller tetap existing; middleware hanya membungkus transaksi + General Posting v4.
 */

Route::prefix('api/v1/warehouse/purchasing-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/purchase-requests/{id}/approve',[WarehousePurchaseRequestV3Controller::class,'approve'])
        ->middleware(['permission_or_snapshot:warehouse.procurement.request.approve,warehouse.procurement.request.update',AutoFinance::class.':purchase_request_approve'])
        ->name('warehouse.purchasing-v3.purchase-requests.approve');
    Route::post('/purchase-orders/{id}/complete-stock-in',[WarehousePurchaseOrderV3Controller::class,'completeStockIn'])
        ->middleware(['permission_or_snapshot:warehouse.stock_in.approve,warehouse.procurement.order.update',AutoFinance::class.':purchase_stock_receipt'])
        ->name('warehouse.purchasing-v3.purchase-orders.complete-stock-in');
});

Route::prefix('api/v1/warehouse/sales-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/production-requests/{id}/approve',[WarehouseSalesDemandV3Controller::class,'approveProductionRequest'])
        ->middleware(['permission_or_snapshot:warehouse.production_request.update',AutoFinance::class.':production_material_out'])
        ->name('warehouse.sales-v3.production-requests.approve');
});

Route::prefix('api/v1/warehouse/production-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/orders/{id}/results/{resultId}/approve',[WarehouseProductionV3Controller::class,'approveResult'])
        ->middleware(['permission_or_snapshot:warehouse.production.approve,warehouse.production.update',AutoFinance::class.':production_finished_in'])
        ->name('warehouse.production-v3.results.approve.v4-finance');
    Route::post('/orders/{id}/finish',[WarehouseProductionV3Controller::class,'finish'])
        ->middleware(['permission_or_snapshot:warehouse.production.done,warehouse.production.update',AutoFinance::class.':production_finish'])
        ->name('warehouse.production-v3.finish.v4-finance');
});

Route::prefix('api/v1/warehouse/logistics-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/checker-prepare/{id}/delivery-order',[WarehouseLogisticsV3Controller::class,'generateDeliveryOrder'])
        ->middleware(['permission_or_snapshot:warehouse.delivery_order.create,warehouse.fulfillment.task.update',AutoFinance::class.':delivery_dispatch'])
        ->name('warehouse.logistics-v3.checker-prepare.delivery-order');

    // Bridge: mempertahankan Finance destination/corporate lama, sementara middleware menambah canonical Warehouse GP v4.
    Route::post('/goods-receipts/{id}/complete',[WarehouseLogisticsFinanceV4BridgeController::class,'completeGoodsReceipt'])
        ->middleware(['permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate',AutoFinance::class.':goods_receipt_complete'])
        ->name('warehouse.logistics-v3.goods-receipts.complete');
});

Route::prefix('api/v1/warehouse/sales-transfer-v3')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/checker-prepare/{id}/delivery-order',[WarehouseSalesTransferV3Controller::class,'generateDeliveryOrder'])
        ->middleware(['permission_or_snapshot:warehouse.delivery_order.create,warehouse.fulfillment.task.update',AutoFinance::class.':delivery_dispatch'])
        ->name('warehouse.sales-transfer-v3.checker-prepare.delivery-order');
    Route::post('/warehouse-receiving/{id}/receive',[WarehouseSalesTransferV3Controller::class,'receiveWarehouse'])
        ->middleware(['permission_or_snapshot:warehouse.logistics.receiving.update,warehouse.transfer.receive',AutoFinance::class.':transfer_receive'])
        ->name('warehouse.sales-transfer-v3.warehouse-receiving.receive');
    Route::post('/goods-receipts/{id}/complete',[WarehouseSalesTransferV3Controller::class,'completeGoodsReceipt'])
        ->middleware(['permission_or_snapshot:warehouse.receiving.monitor.update,warehouse.receiving.goods_receipt.generate',AutoFinance::class.':goods_receipt_complete'])
        ->name('warehouse.sales-transfer-v3.goods-receipts.complete');
});

Route::prefix('api/v1/warehouse/sales-customer-v4')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/orders/{id}/complete-gr',[WarehouseSalesCustomerController::class,'completeGoodsReceipt'])
        ->middleware(['permission_or_snapshot:warehouse.sales.customer.receive,warehouse.sales.customer.update',AutoFinance::class.':sales_customer_complete'])
        ->name('warehouse.sales-customer-v4.complete-gr');
});

Route::prefix('api/v1/warehouse/transfer-stock-v4')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/receiving/{id}/receive',[WarehouseTransferStockController::class,'receive'])
        ->middleware(['permission_or_snapshot:warehouse.logistics.receiving.update,warehouse.transfer.receive',AutoFinance::class.':transfer_receive'])
        ->name('warehouse.transfer-stock-v4.receiving.receive');
});

Route::prefix('api/v1/warehouse/finance-v3-lifecycle')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    foreach(['incoming','outgoing'] as $direction){
        $update=$direction==='incoming'?'warehouse.purchasing.invoice.update':'warehouse.finance.invoice.update';
        // Payment legacy/corporate tetap dipertahankan; middleware menambah canonical Warehouse General Posting v4.
        Route::post("/{$direction}/invoices/{source}/{id}/payments",[WarehouseFinancePaymentPostingIteration04Controller::class,'payment'])
            ->where('source','auto_incoming|auto_outgoing|legacy_outgoing|manual')->defaults('direction',$direction)
            ->middleware(["permission_or_snapshot:{$update}",AutoFinance::class.':invoice_payment'])
            ->name("warehouse.finance-v3-lifecycle.{$direction}.payment");
    }
});

Route::prefix('api/v1/warehouse/ledger')->middleware(['api','auth:sanctum',ResolveWarehouseScope::class])->group(function():void{
    Route::post('/adjustments',[WarehouseLedgerAdjustmentController::class,'store'])
        ->middleware(['permission_or_snapshot:warehouse.ledger.adjustment.create',AutoFinance::class.':ledger_adjustment'])
        ->name('warehouse.ledger.adjustments.store');
    Route::post('/postings/{id}/reverse',[WarehouseLedgerAdjustmentController::class,'reverse'])
        ->middleware(['permission_or_snapshot:warehouse.ledger.adjustment.update',AutoFinance::class.':ledger_reverse'])
        ->name('warehouse.ledger.postings.reverse');
});
