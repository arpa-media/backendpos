<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ErpPosV10I15GlobalTopbarCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i15-topbar-check';

    protected $description = 'Verify V10 I15 global topbar spacing, Warehouse refresh, portal branding, and logout placement.';

    public function handle(): int
    {
        $topbar = $this->read(base_path('../frontend - Backoffice/src/components/Topbar.vue'));
        $unified = $this->read(base_path('../frontend - Backoffice/src/components/shared/UnifiedPortalTopbar.vue'));
        $accountMenu = $this->read(base_path('../frontend - Backoffice/src/components/shared/AccountHamburgerMenu.vue'));
        $app = $this->read(base_path('../frontend - Backoffice/src/App.vue'));
        $dashboard = $this->read(base_path('../frontend - Backoffice/src/pages/UserDashboardPage.vue'));
        $warehouseLayout = $this->read(base_path('../frontend - Backoffice/src/modules/warehouse/layouts/WarehouseLayout.vue'));
        $warehouseHome = $this->read(base_path('../frontend - Backoffice/src/modules/warehouse/pages/WarehouseV3HomePage.vue'));

        $checks = [];
        $checks['Backoffice topbar reserves desktop hamburger gutter'] = str_contains($topbar, 'sm:pr-20 lg:pr-24');
        $checks['Unified portal topbar reserves desktop hamburger gutter'] = str_contains($unified, 'sm:pr-20 lg:pr-24');
        $checks['Warehouse topbar reserves desktop hamburger gutter'] = str_contains($warehouseLayout, 'sm:pr-20')
            && str_contains($warehouseLayout, 'lg:pr-24');

        $checks['Dashboard mobile date/time has no bordered card'] = str_contains($dashboard, 'px-0 py-0 leading-tight sm:min-w-[8.75rem] sm:rounded-xl sm:border')
            && ! str_contains($dashboard, 'items-end rounded-xl border border-[#eadfce] bg-white/85 px-2 py-1.5');

        $checks['Topbar logout button removed'] = ! str_contains($topbar, '>\n          Logout\n        </button>')
            && ! str_contains($topbar, '@click="onLogout"')
            && ! str_contains($topbar, 'useLogoutGuard');
        $checks['Logout remains in account hamburger submenu'] = str_contains($accountMenu, "run('logout')")
            && str_contains($accountMenu, '<span>Logout</span>');
        $checks['Global account hamburger remains enabled outside user dashboard'] = str_contains($app, '<AccountHamburgerMenu />')
            && str_contains($app, "if (path === '/user-dashboard') return false");

        $checks['Warehouse portal topbar title is Toko Kopi Jaya'] = str_contains($warehouseLayout, 'UnifiedPortalTopbar title="Toko Kopi Jaya" subtitle="Warehouse"');
        $checks['Report/Warehouse suffix normalization exists'] = str_contains($unified, '/^Toko Kopi Jaya\\s*-\\s*(Warehouse|Report)$/i')
            && str_contains($unified, "? 'Toko Kopi Jaya' : value");

        $checks['Warehouse topbar refresh is enabled on portal home'] = str_contains($warehouseLayout, 'show-refresh :refreshing="refreshing" @refresh="refreshWarehousePage"');
        $checks['Warehouse inner topbar refresh is enabled'] = str_contains($warehouseLayout, 'aria-label="Refresh halaman Warehouse"')
            && str_contains($warehouseLayout, '@click="refreshWarehousePage"');
        $checks['Warehouse refresh remounts active route'] = str_contains($warehouseLayout, 'warehouseRefreshKey.value += 1')
            && str_contains($warehouseLayout, ':${warehouseRefreshKey}`');
        $checks['Warehouse home duplicate refresh removed'] = ! str_contains($warehouseHome, 'refreshWarehouse')
            && ! str_contains($warehouseHome, "refreshing ? 'Refreshing' : 'Refresh'");
        $checks['Warehouse pages have no duplicate generic Refresh buttons'] = ! $this->warehousePagesContainGenericRefresh();

        $failed = [];
        foreach ($checks as $label => $ok) {
            if ($ok) {
                $this->components->info("PASS — {$label}");
            } else {
                $this->components->error("FAIL — {$label}");
                $failed[] = $label;
            }
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('ERP POS V10 I15 Global Topbar check FAILED.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I15 Global Topbar UI Consistency is READY.');
        return self::SUCCESS;
    }


    private function warehousePagesContainGenericRefresh(): bool
    {
        $files = glob(base_path('../frontend - Backoffice/src/modules/warehouse/pages/*.vue')) ?: [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/>\s*Refresh\s*</i', $source) === 1) {
                return true;
            }
            if (preg_match('/[:?]\s*[\'\"]Refresh[\'\"]/i', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
