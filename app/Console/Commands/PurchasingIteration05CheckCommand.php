<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingIteration05CheckCommand extends Command
{
    protected $signature = 'purchasing:iteration-05-check';
    protected $description = 'Smoke check ERP v4 Iterasi 05 Order Management consolidation.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $checks = [];
        $fail = false;
        $push = function (string $name, bool $ok, string $detail = '') use (&$checks, &$fail): void {
            $checks[] = [$name, $ok ? 'OK' : 'FAILED', $detail];
            if (! $ok) $fail = true;
        };

        $module = $registry->find('order-management');
        $push('Registry Order Management', ($module['implementation_status'] ?? null) === 'WORKFLOW', (string) ($module['path'] ?? 'missing'));
        $push('Overview API route', Route::has('purchasing.order-management.overview'));
        $push('Order workflow routes', Route::has('purchasing.order-workflow.index') && Route::has('purchasing.order-workflow.approve-finance-2'));

        foreach (['pur_purchase_orders','pur_service_orders','pur_reimburse_orders','pur_fund_requests'] as $table) {
            $push('Table '.$table, Schema::hasTable($table));
        }

        $menu = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('code', 'purchasing-order-management')->first() : null;
        $push('Access Matrix Order Management', (bool) $menu && (bool) $menu->is_active && $menu->path === '/purchasing/order-management');
        if (Schema::hasTable('access_menus')) {
            $activeLegacy = DB::table('access_menus')->whereIn('code', ['purchasing-purchase-orders','purchasing-service-orders','purchasing-reimburse-orders'])->where('is_active', true)->count();
            $push('Legacy order menu inactive', $activeLegacy === 0, $activeLegacy.' active');
        }

        $permissions = ['purchasing.order_management.view','purchasing.order_management.create','purchasing.order_management.update','purchasing.order_management.delete','purchasing.order_management.print'];
        $available = Schema::hasTable('permissions') ? DB::table('permissions')->whereIn('name', $permissions)->pluck('name')->all() : [];
        $missing = array_values(array_diff($permissions, $available));
        $push('Order Management permissions', $missing === [], implode(', ', $missing));

        $frontend = [
            '../frontend - Backoffice/src/modules/purchasing/pages/PurchasingOrderManagementPage.vue',
            '../frontend - Backoffice/src/modules/purchasing/pages/PurchasingOrderPrintPage.vue',
            '../frontend - Backoffice/src/modules/purchasing/pages/PurchasingOrderWorkflowPage.vue',
            '../frontend - Backoffice/src/modules/purchasing/lib/orderManagementApi.js',
        ];
        $missingFrontend = array_values(array_filter($frontend, fn ($path) => ! is_file(base_path($path))));
        $push('Frontend Order Management', $missingFrontend === [], implode(', ', $missingFrontend));

        $this->table(['Check','Result','Detail'], $checks);
        $this->{$fail ? 'error' : 'info'}('Status: '.($fail ? 'FAILED' : 'PASSED'));
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
