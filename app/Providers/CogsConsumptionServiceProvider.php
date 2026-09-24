<?php

namespace App\Providers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Observers\Cogs\SaleConsumptionObserver;
use App\Observers\Cogs\SaleItemConsumptionObserver;
use Illuminate\Support\ServiceProvider;

class CogsConsumptionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sale::observe(SaleConsumptionObserver::class);
        SaleItem::observe(SaleItemConsumptionObserver::class);
    }
}
