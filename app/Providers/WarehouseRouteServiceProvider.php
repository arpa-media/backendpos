<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WarehouseRouteServiceProvider extends ServiceProvider
{
    /**
     * Modul berikutnya cukup menambah file pada routes/warehouse_modules.
     * Provider dan route utama tidak perlu ditimpa lagi.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            if (! Route::has('warehouse.context')) {
                $baseRouteFile = base_path('routes/warehouse.php');
                if (is_file($baseRouteFile)) {
                    require $baseRouteFile;
                }
            }

            $moduleFiles = glob(base_path('routes/warehouse_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
