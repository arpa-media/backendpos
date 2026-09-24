<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockInventorySmokeCheckCommand extends Command
{
    protected $signature = 'stock-inventory:smoke-check {--json : Output JSON}';

    protected $description = 'Verify Stock Inventory Iterasi 01 tables, routes, Access Matrix menus, and default master data.';

    public function handle(): int
    {
        $requiredTables = [
            'stk_uoms',
            'stk_categories',
            'stk_skus',
            'stk_par_stocks',
            'stk_stock_opnames',
            'stk_stock_opname_items',
        ];

        $requiredRoutes = [
            ['GET', 'api/v1/stock-inventory/dashboard'],
            ['GET', 'api/v1/stock-inventory/uoms'],
            ['POST', 'api/v1/stock-inventory/uoms'],
            ['PUT', 'api/v1/stock-inventory/uoms/{id}'],
            ['DELETE', 'api/v1/stock-inventory/uoms/{id}'],
            ['GET', 'api/v1/stock-inventory/categories'],
            ['POST', 'api/v1/stock-inventory/categories'],
            ['PUT', 'api/v1/stock-inventory/categories/{id}'],
            ['DELETE', 'api/v1/stock-inventory/categories/{id}'],
            ['GET', 'api/v1/stock-inventory/skus'],
            ['GET', 'api/v1/stock-inventory/skus/options'],
            ['POST', 'api/v1/stock-inventory/skus'],
            ['PUT', 'api/v1/stock-inventory/skus/{id}'],
            ['DELETE', 'api/v1/stock-inventory/skus/{id}'],
            ['GET', 'api/v1/stock-inventory/par-stocks'],
            ['PUT', 'api/v1/stock-inventory/par-stocks'],
            ['DELETE', 'api/v1/stock-inventory/par-stocks/{skuId}'],
            ['GET', 'api/v1/stock-inventory/stock-opnames'],
            ['POST', 'api/v1/stock-inventory/stock-opnames'],
            ['POST', 'api/v1/stock-inventory/stock-opnames/submit'],
        ];

        $requiredMenus = [
            'inventory-dashboard',
            'inventory-uom',
            'inventory-stock-category',
            'inventory-sku',
            'inventory-par-stock',
            'inventory-stock-opname',
            'inventory-request-stock',
            'inventory-receive-stock',
            'inventory-manual-stock',
        ];

        $missingTables = collect($requiredTables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values()
            ->all();

        $registeredRoutes = collect(Route::getRoutes())->flatMap(function ($route) {
            $uri = ltrim((string) $route->uri(), '/');

            return collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => strtoupper($method).' '.$uri);
        })->unique()->values();

        $missingRoutes = collect($requiredRoutes)
            ->map(fn (array $route): string => strtoupper($route[0]).' '.ltrim($route[1], '/'))
            ->reject(fn (string $route): bool => $registeredRoutes->contains($route))
            ->values()
            ->all();

        $legacyWrongPrefixRoutes = $registeredRoutes
            ->filter(fn (string $route): bool => str_contains($route, ' stock-inventory/'))
            ->reject(fn (string $route): bool => str_contains($route, ' api/v1/stock-inventory/'))
            ->values()
            ->all();

        $missingMenus = [];
        if (! Schema::hasTable('access_menus')) {
            $missingMenus = $requiredMenus;
        } else {
            $existingMenus = DB::table('access_menus')->whereIn('code', $requiredMenus)->pluck('code');
            $missingMenus = collect($requiredMenus)->diff($existingMenus)->values()->all();
        }

        $counts = [
            'uoms' => Schema::hasTable('stk_uoms') ? DB::table('stk_uoms')->whereNull('deleted_at')->count() : 0,
            'categories' => Schema::hasTable('stk_categories') ? DB::table('stk_categories')->whereNull('deleted_at')->count() : 0,
            'skus' => Schema::hasTable('stk_skus') ? DB::table('stk_skus')->whereNull('deleted_at')->count() : 0,
        ];

        $diagnostics = [
            'route_file_exists' => is_file(base_path('routes/stock_inventory.php')),
            'route_provider_file_exists' => is_file(app_path('Providers/StockInventoryRouteServiceProvider.php')),
            'route_provider_registered' => $this->routeProviderIsRegistered(),
            'routes_cached' => app()->routesAreCached(),
            'legacy_wrong_prefix_routes' => $legacyWrongPrefixRoutes,
        ];

        $result = [
            'status' => ($missingTables === [] && $missingRoutes === [] && $missingMenus === []) ? 'ok' : 'fail',
            'missing_tables' => $missingTables,
            'missing_routes' => $missingRoutes,
            'missing_access_menus' => $missingMenus,
            'counts' => $counts,
            'diagnostics' => $diagnostics,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('Stock Inventory Iterasi 01B Route Prefix Smoke Check');
            $this->table(['Check', 'Result'], [
                ['Status', strtoupper($result['status'])],
                ['Missing tables', $missingTables === [] ? '-' : implode(', ', $missingTables)],
                ['Missing routes', $missingRoutes === [] ? '-' : implode(', ', $missingRoutes)],
                ['Wrong-prefix routes', $legacyWrongPrefixRoutes === [] ? '-' : implode(', ', $legacyWrongPrefixRoutes)],
                ['Missing Access Matrix menus', $missingMenus === [] ? '-' : implode(', ', $missingMenus)],
                ['Route file', $diagnostics['route_file_exists'] ? 'FOUND' : 'MISSING'],
                ['Route provider file', $diagnostics['route_provider_file_exists'] ? 'FOUND' : 'MISSING'],
                ['Route provider registered', $diagnostics['route_provider_registered'] ? 'YES' : 'NO'],
                ['Laravel routes cached', $diagnostics['routes_cached'] ? 'YES' : 'NO'],
                ['Default/active UOM', (string) $counts['uoms']],
                ['Default/active categories', (string) $counts['categories']],
                ['SKU', (string) $counts['skus']],
            ]);

            if ($legacyWrongPrefixRoutes !== []) {
                $this->newLine();
                $this->components->warn('Routes are loaded with the old /stock-inventory prefix. Apply Iterasi 01B and clear route cache.');
            } elseif ($missingRoutes !== []) {
                $this->newLine();
                $this->components->warn('Route registration remains incomplete. Verify bootstrap/app.php or the route provider registration.');
            }
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    private function routeProviderIsRegistered(): bool
    {
        $providersFile = base_path('bootstrap/providers.php');

        if (! is_file($providersFile)) {
            return false;
        }

        $providers = require $providersFile;

        return is_array($providers)
            && in_array(\App\Providers\StockInventoryRouteServiceProvider::class, $providers, true);
    }
}
