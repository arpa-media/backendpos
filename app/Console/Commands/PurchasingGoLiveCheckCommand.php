<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingGoLiveCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-go-live';

    protected $description = 'Validate ERP v4 Iterasi 09 final go-live installation contract.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $tables = [
            'pur_go_live_runs','pur_go_live_checks','pur_document_attachments',
            'pur_service_entry_sheets','pur_order_ap_lifecycles','pur_order_ap_settlements',
            'pur_invoices','pur_invoice_payments',
        ];
        $routes = [
            'purchasing.go-live.catalogs',
            'purchasing.go-live.runs.index',
            'purchasing.go-live.run',
            'purchasing.go-live.runs.show',
            'purchasing.fund-requests.index',
            'purchasing.order-management.overview',
            'purchasing.realization-orders.index',
            'purchasing.ledger.account-payable.index',
            'purchasing.ledger.account-receivable.index',
        ];
        $permissions = [
            'purchasing.go_live.view','purchasing.go_live.run',
            'purchasing.fund_request.view',
            'purchasing.order_management.view',
            'purchasing.realization_order.view',
            'purchasing.account_payable.view',
            'purchasing.account_receivable.view',
        ];

        $missingTables = collect($tables)->reject(fn (string $table) => Schema::hasTable($table))->values();
        $missingRoutes = collect($routes)->reject(fn (string $route) => Route::has($route))->values();

        $availablePermissions = Schema::hasTable('permissions')
            ? DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all()
            : [];
        $missingPermissions = collect(array_diff($permissions, $availablePermissions))->values();

        $moduleKeys = ['fund-requests','order-management','realization-orders','account-payables','account-receivables','go-live'];
        $missingModules = collect($moduleKeys)->filter(function (string $key) use ($registry): bool {
            $module = $registry->find($key);
            return ! $module || (($module['implementation_status'] ?? '') !== 'WORKFLOW');
        })->values();

        $canonicalMenus = [
            'purchasing-fund-requests',
            'purchasing-order-management',
            'purchasing-realization-orders',
            'purchasing-account-payables',
            'purchasing-account-receivables',
            'purchasing-go-live',
        ];
        $legacyMenus = [
            'purchasing-stock-request-approval',
            'purchasing-purchase-orders','purchasing-service-orders','purchasing-reimburse-orders',
            'purchasing-goods-receipts','purchasing-service-acceptances','purchasing-reimburse-payments',
            'purchasing-incoming-invoices','purchasing-outgoing-invoices',
        ];

        $inactiveCanonical = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->whereIn('code',$canonicalMenus)->where('is_active',false)->pluck('code')
            : collect($canonicalMenus);
        $missingCanonical = Schema::hasTable('access_menus')
            ? collect(array_diff($canonicalMenus, DB::table('access_menus')->whereIn('code',$canonicalMenus)->pluck('code')->all()))
            : collect($canonicalMenus);
        $activeLegacy = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->whereIn('code',$legacyMenus)->where('is_active',true)->pluck('code')
            : collect();

        $frontendFiles = [
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingFundRequestPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingOrderManagementPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingRealizationOrderPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingAccountWorkspacePage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingGoLivePage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/menu-modules/02-shell.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/route-modules/07-invoice-ar-ap.js'),
        ];
        $missingFrontend = collect($frontendFiles)->reject(fn (string $file) => is_file($file))->map('basename')->values();

        $ok = $missingTables->isEmpty()
            && $missingRoutes->isEmpty()
            && $missingPermissions->isEmpty()
            && $missingModules->isEmpty()
            && $inactiveCanonical->isEmpty()
            && $missingCanonical->isEmpty()
            && $activeLegacy->isEmpty()
            && $missingFrontend->isEmpty();

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->join(', ')],
            ['Missing routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->join(', ')],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->join(', ')],
            ['Missing WORKFLOW modules', $missingModules->isEmpty() ? '-' : $missingModules->join(', ')],
            ['Missing canonical menus', $missingCanonical->isEmpty() ? '-' : $missingCanonical->join(', ')],
            ['Inactive canonical menus', $inactiveCanonical->isEmpty() ? '-' : $inactiveCanonical->join(', ')],
            ['Active legacy menus', $activeLegacy->isEmpty() ? '-' : $activeLegacy->join(', ')],
            ['Frontend files', $missingFrontend->isEmpty() ? 'OK' : $missingFrontend->join(', ')],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
