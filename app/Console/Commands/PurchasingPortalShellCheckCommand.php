<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingPortalShellCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-shell';

    protected $description = 'Validate Purchasing Iterasi 02 portal shell, registry, routes, frontend files, and Access Matrix.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $expectedKeys = [
            'dashboard', 'fund-requests', 'order-management', 'realization-orders',
            'account-payables', 'account-receivables',
        ];

        $actualKeys = collect($registry->all())->pluck('key')->all();
        $missingModules = array_values(array_diff($expectedKeys, $actualKeys));
        $missingRoutes = collect(['purchasing.shell.context', 'purchasing.shell.module'])
            ->reject(fn (string $name): bool => Route::has($name))
            ->values()
            ->all();

        $frontendFiles = [
            base_path('../frontend - Backoffice/src/modules/purchasing/routes.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/moduleRegistry.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingDashboardPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingShellPage.vue'),
        ];
        $missingFrontend = array_values(array_filter($frontendFiles, fn (string $path): bool => ! is_file($path)));

        $missingMenus = [];
        if (Schema::hasTable('access_menus')) {
            $expectedCodes = collect($registry->all())->pluck('code')->filter()->all();
            $existingCodes = DB::table('access_menus')->whereIn('code', $expectedCodes)->pluck('code')->all();
            $missingMenus = array_values(array_diff($expectedCodes, $existingCodes));
        }

        $portalOk = Schema::hasTable('access_portals')
            && DB::table('access_portals')->where('code', 'purchasing')->where('is_active', true)->exists();

        $rows = [
            ['Registry modules', $missingModules === [] ? 'OK (' . count($actualKeys) . ')' : implode(', ', $missingModules)],
            ['Named API routes', $missingRoutes === [] ? 'OK' : implode(', ', $missingRoutes)],
            ['Frontend registry/pages', $missingFrontend === [] ? 'OK' : implode(', ', array_map('basename', $missingFrontend))],
            ['Purchasing portal', $portalOk ? 'OK' : 'MISSING/INACTIVE'],
            ['Access Matrix menus', $missingMenus === [] ? 'OK' : implode(', ', $missingMenus)],
        ];

        $failed = $missingModules !== [] || $missingRoutes !== [] || $missingFrontend !== [] || ! $portalOk || $missingMenus !== [];
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];

        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
