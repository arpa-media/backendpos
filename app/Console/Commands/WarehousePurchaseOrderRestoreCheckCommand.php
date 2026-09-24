<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehousePurchaseOrderRestoreCheckCommand extends Command
{
    protected $signature = 'warehouse:smoke-check-purchase-order-restore';
    protected $description = 'Validate restored Warehouse Purchase Request and Supplier Purchase Order flow.';

    public function handle(): int
    {
        $tables = [
            'wh_purchase_requests', 'wh_purchase_request_items',
            'wh_supplier_purchase_orders', 'wh_supplier_purchase_order_items',
            'wh_purchase_invoices', 'wh_stock_ins',
        ];
        $routes = [
            'warehouse.purchase-requests.index', 'warehouse.purchase-requests.store',
            'warehouse.purchase-requests.show', 'warehouse.purchase-requests.update',
            'warehouse.purchase-requests.submit', 'warehouse.purchase-requests.decision',
            'warehouse.supplier-purchase-orders.index', 'warehouse.supplier-purchase-orders.show',
            'warehouse.supplier-purchase-orders.assign-buyer',
            'warehouse.supplier-purchase-orders.purchase',
            'warehouse.supplier-purchase-orders.approve-purchase',
            'warehouse.supplier-purchase-orders.invoices.store',
            'warehouse.supplier-purchase-orders.invoices.download',
        ];

        $missingTables = collect($tables)->reject(fn (string $table) => Schema::hasTable($table))->values();
        $missingRoutes = collect($routes)->reject(fn (string $route) => Route::has($route))->values();

        $routeControllerErrors = [];
        foreach (['warehouse.purchase-requests.index', 'warehouse.supplier-purchase-orders.index'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $action = (string) ($route?->getActionName() ?? '');
            if ($action === '' || str_contains($action, 'LegacyWarehousePurchasingBridgeController')) {
                $routeControllerErrors[] = $name . ' => ' . ($action ?: 'MISSING');
            }
        }

        $menus = ['warehouse-purchase-requests', 'warehouse-supplier-purchase-orders'];
        $activeMenus = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->whereIn('code', $menus)->where('is_active', true)->pluck('code')->all()
            : [];
        $missingMenus = array_values(array_diff($menus, $activeMenus));

        $frontendFiles = [
            base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseProcurementPage.vue'),
            base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseProcurementDocumentPrintPage.vue'),
            base_path('../frontend - Backoffice/src/modules/warehouse/lib/warehouseProcurementApi.js'),
            base_path('../frontend - Backoffice/src/modules/warehouse/menu-modules/08-procurement-stock-in.js'),
            base_path('../frontend - Backoffice/src/modules/warehouse/route-modules/08-procurement-stock-in.js'),
        ];
        $missingFrontend = collect($frontendFiles)->reject(fn (string $file) => is_file($file))->map('basename')->values();

        $ok = $missingTables->isEmpty()
            && $missingRoutes->isEmpty()
            && $routeControllerErrors === []
            && $missingMenus === []
            && $missingFrontend->isEmpty();

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->join(', ')],
            ['Missing routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->join(', ')],
            ['Legacy bridge routes', $routeControllerErrors === [] ? 'REMOVED' : implode(' | ', $routeControllerErrors)],
            ['Active Warehouse menus', $missingMenus === [] ? 'PR + PO ACTIVE' : implode(', ', $missingMenus)],
            ['Frontend files', $missingFrontend->isEmpty() ? 'OK' : $missingFrontend->join(', ')],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
