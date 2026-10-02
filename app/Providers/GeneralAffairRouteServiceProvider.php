<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class GeneralAffairRouteServiceProvider extends ServiceProvider
{
    /**
     * ERP POS V10 I01.
     *
     * General Affair is additive by design. Future iterations only add a
     * numbered PHP file under routes/general_affair_modules so this provider
     * and the global route bootstrap do not need to be overwritten again.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $moduleFiles = glob(base_path('routes/general_affair_modules/*.php')) ?: [];
            sort($moduleFiles, SORT_STRING);

            foreach ($moduleFiles as $moduleFile) {
                require $moduleFile;
            }
        });
    }
}
