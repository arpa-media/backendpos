<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockSkuSpreadsheetCheckCommand extends Command
{
    protected $signature = 'stock-inventory:sku-spreadsheet-check';
    protected $description = 'Memeriksa route, service, dan Access Matrix Import/Export Excel SKU.';

    public function handle(): int
    {
        $requiredRoutes = [
            ['GET', 'api/v1/stock-inventory/skus/template'],
            ['GET', 'api/v1/stock-inventory/skus/export'],
            ['POST', 'api/v1/stock-inventory/skus/import'],
        ];
        $missingRoutes = [];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($requiredRoutes as [$method, $uri]) {
            $exists = $routes->contains(fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            if (! $exists) $missingRoutes[] = $method.' '.$uri;
        }

        $missingClasses = collect([
            \App\Services\Support\SimpleXlsxService::class,
            \App\Services\StockInventory\StockSkuSpreadsheetService::class,
        ])->reject(fn ($class) => class_exists($class))->values()->all();

        $menu = null;
        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('code', 'inventory-sku')->first();
        }

        $missingExtensions = [];
        if (! function_exists('simplexml_load_string')) $missingExtensions[] = 'SimpleXML';
        if (! class_exists(\ZipArchive::class) && ! function_exists('gzinflate')) $missingExtensions[] = 'ZipArchive atau Zlib';
        if (! function_exists('mb_substr')) $missingExtensions[] = 'Mbstring';

        $ok = $missingRoutes === [] && $missingClasses === [] && $missingExtensions === [] && $menu;
        $this->table(['Check', 'Result'], [
            ['Missing routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Missing classes', $missingClasses ? implode(', ', $missingClasses) : '-'],
            ['Missing PHP extensions', $missingExtensions ? implode(', ', $missingExtensions) : '-'],
            ['Access Matrix menu', $menu ? $menu->path.' | '.($menu->is_active ? 'active' : 'inactive') : 'MISSING'],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
