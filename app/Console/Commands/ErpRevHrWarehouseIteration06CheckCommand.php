<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration06CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-06-check';
    protected $description = 'Verify ERP REV Iteration 06 Transfer Stock Excel/import/readiness and Sales Order Customer flow.';

    public function handle(): int
    {
        $checks = [
            'Transfer template route exists' => Route::has('warehouse.transfer-stock-v4.import.template.i06'),
            'Transfer import preview route exists' => Route::has('warehouse.transfer-stock-v4.import.preview.i06'),
            'Transfer submit readiness route exists' => Route::has('warehouse.transfer-stock-v4.submit-ready.i06'),
            'Transfer approve readiness route exists' => Route::has('warehouse.transfer-stock-v4.approve-ready.i06'),
            'Sales submit readiness route exists' => Route::has('warehouse.sales-customer-v4.submit-ready.i06'),
            'Sales approve readiness route exists' => Route::has('warehouse.sales-customer-v4.approve-ready.i06'),
            'Transfer Stock tables exist' => Schema::hasTable('wh_v3_transfer_orders') && Schema::hasTable('wh_v3_transfer_order_items'),
            'Sales Customer tables exist' => Schema::hasTable('wh_v3_sales_orders') && Schema::hasTable('wh_v3_sales_order_items'),
            'Warehouse batch availability exists' => Schema::hasTable('wh_batch_balances'),
            'Simple XLSX service available' => class_exists(\App\Services\Support\SimpleXlsxService::class),
        ];

        if (Schema::hasTable('permissions')) {
            foreach (['warehouse.transfer.import','warehouse.transfer.submit','warehouse.transfer.assign','warehouse.sales.customer.submit','warehouse.sales.customer.approve','warehouse.sales.customer.ready'] as $permission) {
                $checks["Permission {$permission} exists"] = DB::table('permissions')->where('name', $permission)->exists();
            }
        }

        if (Schema::hasTable('access_menus')) {
            $transfer = DB::table('access_menus')->where('path', '/warehouse/transfers')->first();
            $sales = DB::table('access_menus')->where('path', '/warehouse/sales/customer')->first();
            $checks['Transfer Access Matrix canonical'] = $transfer
                && (! Schema::hasColumn('access_menus', 'permission_view') || $transfer->permission_view === 'warehouse.transfer.view')
                && (! Schema::hasColumn('access_menus', 'permission_create') || $transfer->permission_create === 'warehouse.transfer.create')
                && (! Schema::hasColumn('access_menus', 'permission_update') || $transfer->permission_update === 'warehouse.transfer.update');
            $checks['Sales Customer Access Matrix canonical'] = $sales
                && (! Schema::hasColumn('access_menus', 'permission_view') || $sales->permission_view === 'warehouse.sales.customer.view')
                && (! Schema::hasColumn('access_menus', 'permission_create') || $sales->permission_create === 'warehouse.sales.customer.create')
                && (! Schema::hasColumn('access_menus', 'permission_update') || $sales->permission_update === 'warehouse.sales.customer.update');
        }

        $frontendRoot = base_path('../frontend - Backoffice/src/modules/warehouse');
        $transferApi = @file_get_contents($frontendRoot.'/lib/warehouseTransferStockV4Api.js') ?: '';
        $salesApi = @file_get_contents($frontendRoot.'/lib/warehouseSalesCustomerV4Api.js') ?: '';
        $transferPage = @file_get_contents($frontendRoot.'/pages/WarehouseTransferStockV4Page.vue') ?: '';
        $salesPage = @file_get_contents($frontendRoot.'/pages/WarehouseSalesCustomerV4Page.vue') ?: '';
        $skuPicker = @file_get_contents($frontendRoot.'/components/WarehouseSkuSearchSelect.vue') ?: '';
        $checks['Frontend Transfer uses readiness endpoints'] = str_contains($transferApi, '/submit-ready') && str_contains($transferApi, '/approve-ready');
        $checks['Frontend Transfer has XLSX template/import'] = str_contains($transferApi, '/import/template') && str_contains($transferApi, '/import/preview') && str_contains($transferPage, 'Import Excel');
        $checks['Frontend Sales uses readiness endpoints'] = str_contains($salesApi, '/submit-ready') && str_contains($salesApi, '/approve-ready');
        $checks['Frontend Sales exposes Approved/Ready flow'] = str_contains($salesPage, 'Approve & Ready Logistics');
        $checks['Standalone searchable SKU dependency exists'] = str_contains($skuPicker, 'defineModel') || str_contains($skuPicker, 'modelValue');

        $failed = false;
        foreach ($checks as $label => $ok) {
            $ok = (bool) $ok;
            $this->line(sprintf('[%s] %s', $ok ? 'OK' : 'FAIL', $label));
            if (! $ok) $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
