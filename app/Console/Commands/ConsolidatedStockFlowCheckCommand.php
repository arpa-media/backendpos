<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ConsolidatedStockFlowCheckCommand extends Command
{
    protected $signature = 'erp:smoke-check-consolidated-stock-flow';
    protected $description = 'Smoke check consolidated Stock Request, PR/PO, GR and Invoice flow.';

    public function handle(): int
    {
        $errors = [];
        foreach (['wh_stock_requests','wh_stock_request_items','pur_fund_requests','pur_purchase_orders','pur_purchase_order_items','stk_goods_receipts','pur_goods_receipts','pur_goods_receipt_items','pur_invoices'] as $table) {
            if (! Schema::hasTable($table)) $errors[] = 'Missing table: '.$table;
        }
        foreach (['warehouse.stock-requests.outlet.submit','warehouse.stock-requests.outlet.approve'] as $name) {
            if (! Route::has($name)) $errors[] = 'Missing route: '.$name;
        }
        foreach ([
            app_path('Services/Purchasing/StockRequestDraftPoBridgeService.php') => 'approveStockRequest',
            app_path('Services/Purchasing/StockRequestReceiptInvoiceBridgeService.php') => 'syncFromManualGoodsReceipt',
            app_path('Services/Purchasing/StockRequestReceiptInvoiceBridgeService.php') => 'createWarehouseInvoiceAfterFinanceApproval',
            app_path('Console/Commands/ResetStockPurchasingWarehouseDocumentsCommand.php') => 'erp:reset-stock-purchasing-documents',
        ] as $file => $needle) {
            if (! is_file($file) || ! str_contains((string) file_get_contents($file), $needle)) $errors[] = 'Missing contract: '.$needle;
        }

        $this->table(['Check','Result'], [
            ['Stock Request approval route', Route::has('warehouse.stock-requests.outlet.approve') ? 'OK' : 'FAILED'],
            ['Auto PR/PO bridge', is_file(app_path('Services/Purchasing/StockRequestDraftPoBridgeService.php')) ? 'OK' : 'FAILED'],
            ['Outlet/Manual GR bridge', is_file(app_path('Services/Purchasing/StockRequestReceiptInvoiceBridgeService.php')) ? 'OK' : 'FAILED'],
            ['Reset command', is_file(app_path('Console/Commands/ResetStockPurchasingWarehouseDocumentsCommand.php')) ? 'OK' : 'FAILED'],
            ['Errors', $errors ? implode(' | ', $errors) : '-'],
            ['Status', $errors ? 'FAILED' : 'PASSED'],
        ]);
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
