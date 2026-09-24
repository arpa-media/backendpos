<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration05CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-05-check';
    protected $description = 'Static/runtime checks ERP REV HR + Warehouse iteration 05';

    public function handle(): int
    {
        $checks = [
            'Bulk PR PDF route exists' => Route::has('warehouse.v3-final.bulk.pr'),
            'Bulk PO PDF route exists' => Route::has('warehouse.v3-final.bulk.po'),
            'Bulk Stock Request PDF route exists' => Route::has('warehouse.v3-final.bulk.stock-request'),
            'Bulk DO PDF route exists' => Route::has('warehouse.v3-final.bulk.do'),
            'Bulk GR PDF route exists' => Route::has('warehouse.v3-final.bulk.gr'),
            'Access Matrix table available' => Schema::hasTable('access_menus'),
        ];

        $menus = [
            'warehouse-v3-purchase-requests' => 'warehouse.procurement.request.view',
            'warehouse-v3-purchase-orders' => 'warehouse.procurement.order.view',
            'warehouse-v3-sales-stock-request' => 'warehouse.stock_request.inbox.view',
            'warehouse-v3-logistics-do' => 'warehouse.delivery_order.view',
            'warehouse-v3-logistics-gr' => 'warehouse.receiving.monitor.view',
        ];
        if (Schema::hasTable('access_menus')) {
            foreach ($menus as $code => $permission) {
                $query = DB::table('access_menus')->where('code', $code);
                $checks["Access Matrix {$code} active"] = (clone $query)->where('is_active', true)->exists();
                if (Schema::hasColumn('access_menus', 'permission_view')) {
                    $checks["Access Matrix {$code} view canonical"] = (clone $query)->where('permission_view', $permission)->exists();
                }
            }
        }

        $frontend = base_path('../frontend - Backoffice/src/modules/warehouse');
        $component = $frontend.'/components/WarehouseSkuSearchSelect.vue';
        $checks['Searchable SKU component exists'] = is_file($component);
        $checks['Searchable SKU supports category/brand search'] = is_file($component)
            && str_contains((string) file_get_contents($component), 'categoryOf(item)')
            && str_contains((string) file_get_contents($component), 'brandOf(item)');
        foreach ([
            'WarehousePurchasingV3Page.vue', 'WarehouseProductionV3Page.vue', 'WarehouseTransferStockV4Page.vue',
            'WarehouseSalesCustomerV4Page.vue', 'WarehouseStockV3Page.vue', 'WarehouseInventoryCatalogPage.vue',
        ] as $page) {
            $path = $frontend.'/pages/'.$page;
            $checks["Searchable SKU wired: {$page}"] = is_file($path)
                && str_contains((string) file_get_contents($path), 'WarehouseSkuSearchSelect');
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $ok ? $this->info("[OK] {$label}") : $this->error("[FAIL] {$label}");
            if (! $ok) $failed++;
        }
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
