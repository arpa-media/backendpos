<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Purchasing\StockRequestFundApprovalController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingStockRequestBridgeCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-stock-request-bridge';

    protected $description = 'Validate Iterasi 04 Stock Request UX, canonical request bridge, draft PO linkage, routes, and Access Matrix.';

    public function handle(): int
    {
        $expectedColumns = [
            'stk_requests' => [
                'canonical_fund_request_id', 'draft_purchase_order_id',
                'request_approval_status', 'request_approved_by_user_id', 'request_approved_at',
            ],
            'pur_purchase_orders' => ['fund_request_id', 'order_type'],
        ];
        $missingColumns = [];
        foreach ($expectedColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingColumns[] = $table . '.*';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
        }

        $expectedRoutes = [
            'warehouse.stock-requests.outlet.options',
            'warehouse.stock-requests.outlet.store',
            'warehouse.stock-requests.outlet.submit',
            'purchasing.fund-requests.approve',
            'purchasing.fund-requests.reject',
            'stock-inventory.request-stock.non-warehouse.catalogs',
            'stock-inventory.request-stock.non-warehouse.index',
            'stock-inventory.request-stock.non-warehouse.store',
            'stock-inventory.request-stock.non-warehouse.release',
        ];
        $missingRoutes = collect($expectedRoutes)
            ->reject(fn (string $name): bool => Route::has($name))
            ->values()
            ->all();

        $approvalAction = Route::getRoutes()->getByName('purchasing.fund-requests.approve')?->getActionName();
        $approvalOverrideOk = $approvalAction === StockRequestFundApprovalController::class . '@approve';

        $frontendFiles = [
            base_path('../frontend - Backoffice/src/components/stock-inventory/NonWarehouseStockRequestPanel.vue'),
            base_path('../frontend - Backoffice/src/lib/nonWarehouseStockRequestApi.js'),
            base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseStockRequestPage.vue'),
        ];
        $missingFrontend = array_values(array_filter($frontendFiles, fn (string $file): bool => ! is_file($file)));

        $requestMenuActive = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'inventory-request-stock')->where('is_active', true)->exists();
        $manualMenuHidden = ! Schema::hasTable('access_menus')
            || ! DB::table('access_menus')->where('code', 'inventory-manual-stock')->where('is_active', true)->exists();
        $legacyApprovalHidden = ! Schema::hasTable('access_menus')
            || ! DB::table('access_menus')->where('code', 'purchasing-stock-request-approval')->where('is_active', true)->exists();

        $checks = [
            ['Bridge columns', $missingColumns === [] ? 'OK' : implode(', ', $missingColumns)],
            ['Named routes', $missingRoutes === [] ? 'OK' : implode(', ', $missingRoutes)],
            ['Approval orchestration route', $approvalOverrideOk ? 'OK' : ($approvalAction ?: 'MISSING')],
            ['Frontend files', $missingFrontend === [] ? 'OK' : implode(', ', array_map('basename', $missingFrontend))],
            ['Request Stock Access Matrix', $requestMenuActive ? 'ACTIVE' : 'MISSING/INACTIVE'],
            ['Manual Stock standalone menu', $manualMenuHidden ? 'HIDDEN' : 'STILL ACTIVE'],
            ['Legacy approval menu', $legacyApprovalHidden ? 'HIDDEN' : 'STILL ACTIVE'],
        ];

        $failed = $missingColumns !== []
            || $missingRoutes !== []
            || ! $approvalOverrideOk
            || $missingFrontend !== []
            || ! $requestMenuActive
            || ! $manualMenuHidden
            || ! $legacyApprovalHidden;

        $this->table(['Check', 'Result'], $checks);
        $this->{$failed ? 'error' : 'info'}('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
