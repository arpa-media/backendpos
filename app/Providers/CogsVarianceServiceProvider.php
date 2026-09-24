<?php

namespace App\Providers;

use App\Models\StockInventory\StockOpname;
use App\Observers\Cogs\StockOpnameVarianceObserver;
use Illuminate\Support\ServiceProvider;

class CogsVarianceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        StockOpname::observe(StockOpnameVarianceObserver::class);
    }
}
