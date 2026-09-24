<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FinanceRouteServiceProvider extends ServiceProvider
{
    /**
     * Finance is additive by design.
     *
     * Iterasi berikutnya cukup menambahkan file baru pada
     * routes/finance_modules/*.php. File provider/base route ini tidak perlu
     * ditimpa lagi sehingga setiap patch Finance dapat berdiri sendiri.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            if (! Route::has('finance.foundation.context')) {
                $baseRouteFile = base_path('routes/finance.php');
                if (is_file($baseRouteFile)) {
                    require $baseRouteFile;
                }
            }

            $moduleFiles = glob(base_path('routes/finance_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
