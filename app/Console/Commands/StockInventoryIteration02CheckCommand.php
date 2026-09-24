<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\StockInventory\NonWarehouseStockRequestController;
use App\Services\Purchasing\NonWarehouseStockRequestFundBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockInventoryIteration02CheckCommand extends Command
{
    protected $signature = 'stock-inventory:iteration-02-check';

    protected $description = 'Smoke check ERP v4 Iterasi 02: canonical Non-Warehouse Stock Request, UOM snapshots, Fund Request handoff, and no direct stock receipt.';

    public function handle(): int
    {
        $controller = @file_get_contents(app_path('Http/Controllers/Api/V1/StockInventory/NonWarehouseStockRequestController.php')) ?: '';
        $service = @file_get_contents(app_path('Services/StockInventory/NonWarehouseStockRequestService.php')) ?: '';
        $bridge = @file_get_contents(app_path('Services/Purchasing/NonWarehouseStockRequestFundBridgeService.php')) ?: '';
        $approval = @file_get_contents(app_path('Http/Controllers/Api/V1/Purchasing/StockRequestFundApprovalController.php')) ?: '';
        $legacyApproval = @file_get_contents(app_path('Http/Controllers/Api/V1/StockInventory/StockRequestApprovalController.php')) ?: '';
        $frontend = @file_get_contents(base_path('../frontend - Backoffice/src/components/stock-inventory/NonWarehouseStockRequestPanel.vue')) ?: '';
        $frontendApi = @file_get_contents(base_path('../frontend - Backoffice/src/lib/nonWarehouseStockRequestApi.js')) ?: '';

        $missingSchema = [];
        foreach ([
            'stk_requests' => [
                'request_channel', 'non_warehouse_supplier_source_id', 'supplier_name_snapshot',
                'supplier_contact_snapshot', 'supplier_phone_snapshot', 'canonical_fund_request_id',
                'purchasing_handoff_status', 'request_approval_status',
            ],
            'stk_request_items' => [
                'request_uom_id', 'base_uom_id_snapshot', 'requested_qty_uom',
                'conversion_factor_snapshot', 'requested_qty_base', 'request_uom_code_snapshot',
            ],
            'pur_fund_requests' => ['source_type', 'source_id', 'source_key', 'request_type', 'status'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingSchema[] = $table . '.*';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingSchema[] = $table . '.' . $column;
                }
            }
        }

        $expectedRoutes = [
            'stock-inventory.request-stock.non-warehouse.catalogs',
            'stock-inventory.request-stock.non-warehouse.index',
            'stock-inventory.request-stock.non-warehouse.store',
            'stock-inventory.request-stock.non-warehouse.show',
            'stock-inventory.request-stock.non-warehouse.update',
            'stock-inventory.request-stock.non-warehouse.submit',
            'stock-inventory.request-stock.non-warehouse.destroy',
            'stock-inventory.request-stock.non-warehouse.release',
        ];
        $missingRoutes = collect($expectedRoutes)->reject(fn (string $name): bool => Route::has($name))->values()->all();
        $storeAction = Route::getRoutes()->getByName('stock-inventory.request-stock.non-warehouse.store')?->getActionName();
        $releaseAction = Route::getRoutes()->getByName('stock-inventory.request-stock.non-warehouse.release')?->getActionName();

        $checks = [
            'Schema Non-Warehouse + UOM snapshot tersedia' => $missingSchema === [],
            'Named routes canonical lengkap' => $missingRoutes === [],
            'POST Non-Warehouse tidak lagi memakai ManualStockController' =>
                $storeAction === NonWarehouseStockRequestController::class . '@store',
            'Legacy /release diproteksi deprecated guard' =>
                $releaseAction === NonWarehouseStockRequestController::class . '@deprecatedRelease',
            'Service tidak mempunyai direct Goods Receipt/stock receipt dependency' =>
                ! str_contains($service, 'GoodsReceiptService')
                && ! str_contains($service, 'createManual(')
                && ! str_contains($service, '->release('),
            'Supplier menggunakan free-text snapshot' =>
                str_contains($service, "'supplier_name_snapshot'")
                && str_contains($frontend, 'v-model="form.supplier_name"')
                && ! str_contains($frontend, 'supplier_source_id'),
            'Item menyimpan transaction UOM + Base snapshot' =>
                str_contains($service, "'requested_qty_uom'")
                && str_contains($service, "'conversion_factor_snapshot'")
                && str_contains($service, "'requested_qty_base'")
                && str_contains($frontend, 'v-model="item.uom_id"'),
            'Submit membuat Fund Request idempotent melalui source_key' =>
                str_contains($bridge, NonWarehouseStockRequestFundBridgeService::SOURCE_KEY_PREFIX)
                && str_contains($bridge, 'syncDraft(')
                && str_contains($bridge, "FundRequest::TYPE_STOCK"),
            'Approval Purchasing dispatch bridge Non-Warehouse khusus' =>
                str_contains($approval, 'NonWarehouseStockRequestFundBridgeService')
                && str_contains($approval, '$this->nonWarehouseBridge->synchronizeDecision'),
            'Legacy Stock Request approval mengecualikan channel Non-Warehouse' =>
                str_contains($legacyApproval, 'non_warehouse_procurement'),
            'Frontend tidak lagi expose create/release Manual GR' =>
                ! str_contains($frontendApi, 'createManualStock')
                && ! str_contains($frontendApi, 'releaseManualStock')
                && str_contains($frontendApi, 'submitNonWarehouseStockRequest'),
        ];

        $accessOk = ! Schema::hasTable('access_menus')
            || DB::table('access_menus')
                ->where('code', 'inventory-request-stock')
                ->where('is_active', true)
                ->exists();
        $checks['Access Matrix reuse menu Request Stock aktif'] = $accessOk;

        $rows = collect($checks)->map(fn (bool $ok, string $name): array => [$name, $ok ? 'OK' : 'FAILED'])->values()->all();
        $rows[] = ['Missing schema', $missingSchema ? implode(', ', $missingSchema) : '-'];
        $rows[] = ['Missing routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'];
        $rows[] = ['Store action', $storeAction ?: 'MISSING'];
        $rows[] = ['Release guard action', $releaseAction ?: 'MISSING'];

        $passed = ! in_array(false, $checks, true);
        $rows[] = ['Status', $passed ? 'PASSED' : 'FAILED'];
        $this->table(['Check', 'Result'], $rows);

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
