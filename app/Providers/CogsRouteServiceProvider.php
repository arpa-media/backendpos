<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CogsRouteServiceProvider extends ServiceProvider
{
    /**
     * Register HPP/COGS base routes and additive route modules.
     * Later iterations only add files under routes/cogs_modules/*.php.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            if (! $this->cogsRoutesAreRegistered()) {
                $baseRouteFile = base_path('routes/cogs.php');
                if (is_file($baseRouteFile)) {
                    require $baseRouteFile;
                }
            }

            $moduleFiles = glob(base_path('routes/cogs_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }

    private function cogsRoutesAreRegistered(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (ltrim((string) $route->uri(), '/') === 'api/v1/cogs/dashboard') {
                return true;
            }
        }

        return false;
    }
}
