<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpStockCogsIteration03CheckCommand extends Command
{
    protected $signature = 'erp-stock-cogs:iteration-03-check';
    protected $description = 'Smoke-check patch Iterasi 03 fast and resilient Item Sold reconciliation.';

    public function handle(): int
    {
        $checks = [
            'Consumption tables' => Schema::hasTable('cogs_sale_consumptions') && Schema::hasTable('cogs_sale_consumption_items'),
            'Process route' => Route::has('cogs.item-sold.process'),
            'Service installed' => class_exists(\App\Services\Cogs\SaleConsumptionService::class),
            'Cache lock supported' => is_object(Cache::lock('erp-stock-cogs-iteration-03-check', 5)),
        ];

        $this->table(['Check', 'Result'], array_map(
            fn (string $label, bool $ok): array => [$label, $ok ? 'OK' : 'FAILED'],
            array_keys($checks),
            array_values($checks),
        ));

        if (in_array(false, $checks, true)) {
            $this->error('FAILED');
            return self::FAILURE;
        }

        $this->info('PASSED');
        return self::SUCCESS;
    }
}
