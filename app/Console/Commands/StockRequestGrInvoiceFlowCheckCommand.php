<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockRequestGrInvoiceFlowCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-stock-request-gr-invoice';
    protected $description = 'Check Stock Request auto handoff, Outlet GR bridge, Finance approval, and Warehouse invoice flow.';

    public function handle(): int
    {
        $tables = ['stk_goods_receipts','stk_goods_receipt_items','pur_goods_receipts','pur_goods_receipt_items','pur_invoices','pur_invoice_items'];
        $missing = array_values(array_filter($tables, fn($table) => !Schema::hasTable($table)));
        $routes = ['purchasing.execution.post'];
        $missingRoutes = array_values(array_filter($routes, fn($name) => !Route::has($name)));
        $service = app_path('Services/Purchasing/StockRequestReceiptInvoiceBridgeService.php');
        $controller = app_path('Http/Controllers/Api/V1/Warehouse/Receiving/WarehouseOutletReceivingController.php');
        $execution = app_path('Services/Purchasing/ExecutionWorkflowService.php');
        $source = implode("\n", array_map(fn($f) => is_file($f) ? file_get_contents($f) : '', [$service,$controller,$execution]));
        $checks = [
            ['Required tables', $missing ? implode(', ', $missing) : 'OK'],
            ['Named routes', $missingRoutes ? implode(', ', $missingRoutes) : 'OK'],
            ['Outlet GR -> Purchasing GR', str_contains($source, 'syncFromOutletGoodsReceipt') ? 'OK' : 'FAILED'],
            ['Finance GR -> Invoice', str_contains($source, 'createWarehouseInvoiceAfterFinanceApproval') ? 'OK' : 'FAILED'],
            ['Idempotency', str_contains($source, "STOCK_GR:") ? 'OK' : 'FAILED'],
        ];
        $failed = $missing || $missingRoutes || collect($checks)->contains(fn($r) => $r[1] === 'FAILED');
        $checks[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check','Result'],$checks);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
