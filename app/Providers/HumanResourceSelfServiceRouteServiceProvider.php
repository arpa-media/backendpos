<?php

namespace App\Providers;

use App\Http\Middleware\EnforceCareerTokenBoundary;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HumanResourceSelfServiceRouteServiceProvider extends ServiceProvider
{
    /**
     * Stable extension point for employee-facing HR pages (attendance, schedule,
     * leave, etc.). Iterasi 03+ only add numbered files under
     * routes/hr_self_service_modules without changing Iterasi 02 files.
     */
    public function boot(): void
    {
        // Iterasi 17: enforce a hard boundary between Career tokens and POS/Backoffice API.
        // The middleware resolves the bearer token directly, so it is effective even before auth:sanctum.
        $this->app['router']->pushMiddlewareToGroup('api', EnforceCareerTokenBoundary::class);

        $this->app->booted(function (): void {
            $moduleFiles = glob(base_path('routes/hr_self_service_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_NATURAL | SORT_FLAG_CASE);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
