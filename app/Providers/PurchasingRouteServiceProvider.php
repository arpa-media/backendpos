<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PurchasingRouteServiceProvider extends ServiceProvider
{
    /**
     * Load the Purchasing portal base routes and additive route modules.
     *
     * Iterasi berikutnya cukup menambahkan file baru pada
     * routes/purchasing_modules/*.php. Provider dan route utama ini tidak
     * perlu ditimpa lagi.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            if (! Route::has('purchasing.shell.context')) {
                $baseRouteFile = base_path('routes/purchasing.php');
                if (is_file($baseRouteFile)) {
                    require $baseRouteFile;
                }
            }

            $moduleFiles = glob(base_path('routes/purchasing_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
