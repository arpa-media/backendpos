<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class StockInventoryRouteServiceProvider extends ServiceProvider
{
    /**
     * Load the base Stock Inventory routes and additive operation modules.
     *
     * Future iterations only need to add a file below
     * routes/stock_inventory_modules/*.php. Existing route files do not need
     * to be overwritten again.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            if (! $this->stockInventoryRoutesAreRegistered()) {
                $baseRouteFile = base_path('routes/stock_inventory.php');
                if (is_file($baseRouteFile)) {
                    require $baseRouteFile;
                }
            }

            $moduleFiles = glob(base_path('routes/stock_inventory_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }

    private function stockInventoryRoutesAreRegistered(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (ltrim((string) $route->uri(), '/') === 'api/v1/stock-inventory/dashboard') {
                return true;
            }
        }

        return false;
    }
}
