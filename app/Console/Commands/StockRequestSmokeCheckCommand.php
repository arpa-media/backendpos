<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockRequestSmokeCheckCommand extends Command
{
    protected $signature = 'stock-inventory:smoke-check-iteration-02';
    protected $description = 'Validate Stock Request and Purchasing Approval Iteration 02 installation.';

    public function handle(): int
    {
        $tables = [
            'pur_supplier_sources',
            'pur_price_lists',
            'stk_requests',
            'stk_request_items',
            'pur_purchase_orders',
            'pur_purchase_order_items',
            'stk_request_approvals',
            'stk_request_timelines',
            'stk_cancellation_requests',
        ];
        $routes = [
            ['GET', 'api/v1/stock-inventory/request-stocks'],
            ['GET', 'api/v1/stock-inventory/request-stocks/catalogs'],
            ['POST', 'api/v1/stock-inventory/request-stocks/draft-from-opname'],
            ['GET', 'api/v1/stock-inventory/request-stocks/{id}'],
            ['PUT', 'api/v1/stock-inventory/request-stocks/{id}'],
            ['POST', 'api/v1/stock-inventory/request-stocks/{id}/submit'],
            ['POST', 'api/v1/stock-inventory/request-stocks/{id}/cancellation-request'],
            ['GET', 'api/v1/stock-inventory/request-stocks/{id}/timeline'],
            ['DELETE', 'api/v1/stock-inventory/request-stocks/{id}'],
            ['GET', 'api/v1/stock-inventory/request-approvals'],
            ['GET', 'api/v1/stock-inventory/request-approvals/{id}'],
            ['POST', 'api/v1/stock-inventory/request-approvals/{id}/decide'],
            ['GET', 'api/v1/stock-inventory/request-approvals/supplier-sources'],
            ['POST', 'api/v1/stock-inventory/request-approvals/supplier-sources'],
            ['PUT', 'api/v1/stock-inventory/request-approvals/supplier-sources/{id}'],
            ['GET', 'api/v1/stock-inventory/request-approvals/price-suggestion'],
            ['POST', 'api/v1/stock-inventory/stock-opnames/{id}/cancellation-request'],
            ['GET', 'api/v1/stock-inventory/cancellation-approvals'],
            ['GET', 'api/v1/stock-inventory/cancellation-approvals/{id}'],
            ['POST', 'api/v1/stock-inventory/cancellation-approvals/{id}/decide'],
        ];

        $missingTables = array_values(array_filter($tables, fn ($table) => ! Schema::hasTable($table)));
        $routeRows = collect(Route::getRoutes())->map(fn ($route) => [
            'uri' => ltrim((string) $route->uri(), '/'),
            'methods' => $route->methods(),
        ]);
        $missingRoutes = [];
        foreach ($routes as [$method, $uri]) {
            if (! $routeRows->contains(fn ($row) => $row['uri'] === $uri && in_array($method, $row['methods'], true))) {
                $missingRoutes[] = "$method $uri";
            }
        }

        $missingMenus = [];
        if (Schema::hasTable('access_menus')) {
            foreach (['inventory-request-stock', 'purchasing-stock-request-approval', 'inventory-cancellation-approval'] as $code) {
                if (! DB::table('access_menus')->where('code', $code)->where('is_active', true)->exists()) {
                    $missingMenus[] = $code;
                }
            }
        } else {
            $missingMenus[] = 'access_menus table unavailable';
        }

        $supplierCount = Schema::hasTable('pur_supplier_sources')
            ? DB::table('pur_supplier_sources')->where('is_active', true)->count()
            : 0;
        $ok = $missingTables === [] && $missingRoutes === [] && $missingMenus === [] && $supplierCount >= 2;

        $this->table(['Check', 'Result'], [
            ['Status', $ok ? 'OK' : 'FAIL'],
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Missing Access Matrix menus', $missingMenus ? implode(', ', $missingMenus) : '-'],
            ['Active supplier sources', (string) $supplierCount],
            ['Stock requests', Schema::hasTable('stk_requests') ? (string) DB::table('stk_requests')->count() : '0'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
