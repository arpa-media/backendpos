<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration12CheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-12-check';
    protected $description = 'Validate ERP V5 Iteration 12 canonical Warehouse selling price and incoming invoice auto-issue contracts.';

    public function handle(): int
    {
        $checks = [];
        $failed = false;

        $requiredTables = [
            'stk_requests', 'stk_request_items',
            'pur_fund_requests', 'pur_fund_request_items',
            'pur_purchase_orders', 'pur_purchase_order_items',
            'pur_invoices', 'pur_invoice_items', 'pur_invoice_events', 'pur_finance_posting_outbox',
            'wh_v3_outgoing_invoices', 'wh_v3_outgoing_invoice_items',
            'access_menus', 'permissions',
        ];
        foreach ($requiredTables as $table) {
            $this->gate($checks, $failed, "Table {$table}", Schema::hasTable($table));
        }

        $this->gate(
            $checks,
            $failed,
            'Commercial snapshot column',
            Schema::hasTable('stk_request_items') && Schema::hasColumn('stk_request_items', 'commercial_price_snapshot')
        );

        $requiredRoutes = [
            'purchasing.fund-requests.index',
            'purchasing.order-workflow.index',
            'purchasing.invoice.incoming.index',
            'purchasing.ledger.account-payable.index',
        ];
        foreach ($requiredRoutes as $route) {
            $this->gate($checks, $failed, "Route {$route}", Route::has($route));
        }

        $menus = [
            'purchasing-fund-requests' => '/purchasing/fund-requests',
            'purchasing-purchase-orders' => '/purchasing/purchase-orders',
            'purchasing-account-payables' => '/purchasing/account-payables',
        ];
        foreach ($menus as $code => $path) {
            $ok = Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code', $code)->where('path', $path)->where('is_active', true)->exists();
            $this->gate($checks, $failed, "Access Matrix {$code}", $ok);
        }

        $permissions = [
            'purchasing.fund_request.view',
            'purchasing.purchase_order.view',
            'purchasing.account_payable.view',
        ];
        foreach ($permissions as $permission) {
            $ok = Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
            $this->gate($checks, $failed, "Permission {$permission}", $ok);
        }

        $static = [
            'Warehouse Stock Request uses canonical commercial service' => [
                'app/Services/Warehouse/WarehouseStockRequestService.php',
                ['WarehouseCommercialPriceService', 'commercial_price_snapshot', "'estimated_total'"],
                ['resolvedOutletPrice('],
            ],
            'Stock Request bridge stores Warehouse commercial snapshot' => [
                'app/Services/Purchasing/StockRequestDraftPoBridgeService.php',
                ['WarehouseCommercialPriceService', 'warehouse_commercial_price', 'billing_uom_code', 'billing_unit_price'],
                ['StockRequestPriceResolver'],
            ],
            'PO API exposes canonical billing display without changing stock storage' => [
                'app/Services/Purchasing/OrderWorkflowService.php',
                ['warehouse_commercial_price', 'billing_qty', 'billing_unit_price', 'stock_storage'],
                [],
            ],
            'Warehouse Incoming Invoice uses canonical issue lifecycle' => [
                'app/Services/Purchasing/WarehouseOutletInvoiceBridgeService.php',
                ['InvoiceWorkflowService', 'WAREHOUSE_AUTO_ISSUE', "->issue('incoming'", "'auto_issue'=>true"],
                [],
            ],
            'Warehouse invoice reprice honors issued Purchasing audit lock' => [
                'app/Services/Warehouse/Billing/WarehouseOutgoingInvoiceRepriceService.php',
                ['WAREHOUSE_OUTGOING_INVOICE', "->where('status', '!=', 'DRAFT')"],
                [],
            ],
        ];
        foreach ($static as $label => [$relative, $needles, $forbidden]) {
            $source = @file_get_contents(base_path($relative));
            $ok = is_string($source);
            foreach ($needles as $needle) $ok = $ok && str_contains((string) $source, $needle);
            foreach ($forbidden as $needle) $ok = $ok && ! str_contains((string) $source, $needle);
            $this->gate($checks, $failed, $label, $ok);
        }

        if (Schema::hasTable('pur_invoices')) {
            $warehouseDraft = DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
                ->where('status', 'DRAFT')
                ->whereNull('deleted_at')
                ->count();
            $this->gate($checks, $failed, 'Warehouse Incoming Invoice still DRAFT', $warehouseDraft === 0, (string) $warehouseDraft);

            $badBalance = DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_kind', 'WAREHOUSE_OUTGOING_INVOICE')
                ->whereIn('status', ['ISSUED', 'PARTIALLY_PAID', 'PAID'])
                ->whereNull('deleted_at')
                ->whereRaw('ABS(balance_due - GREATEST(total_amount - paid_amount, 0)) > 0.01')
                ->count();
            $this->gate($checks, $failed, 'Warehouse Incoming Invoice balance integrity', $badBalance === 0, (string) $badBalance);
        }

        $missingSnapshots = 0;
        if (Schema::hasTable('stk_request_items') && Schema::hasColumn('stk_request_items', 'commercial_price_snapshot') && Schema::hasTable('stk_requests')) {
            $missingSnapshots = DB::table('stk_request_items as i')
                ->join('stk_requests as r', 'r.id', '=', 'i.stock_request_id')
                ->whereNotNull('r.destination_warehouse_id')
                ->whereNull('i.commercial_price_snapshot')
                ->count();
        }
        $checks[] = ['Historical commercial snapshots missing', $missingSnapshots === 0 ? '0' : "WARN {$missingSnapshots}"];

        $this->table(['Check', 'Result'], $checks);
        $this->{$failed ? 'error' : 'info'}('Status: '.($failed ? 'FAILED' : 'PASSED'));
        if ($missingSnapshots > 0) {
            $this->warn('Jalankan erp-v5:iteration-12-reconcile untuk backfill snapshot yang masih dapat direprice secara aman.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function gate(array &$checks, bool &$failed, string $label, bool $ok, ?string $detail = null): void
    {
        $checks[] = [$label, $ok ? 'PASS' : 'FAIL'.($detail !== null ? " ({$detail})" : '')];
        if (! $ok) $failed = true;
    }
}
