<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OperationalAnalyticsRouteServiceProvider extends ServiceProvider
{
    /**
     * ERP FINANCE V8 I07+ operational analytics routes are additive.
     * Future iterations only add numbered files under
     * routes/operational_analytics_modules/*.php.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $moduleFiles = glob(base_path('routes/operational_analytics_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
