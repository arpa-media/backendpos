<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5HotfixCogsSellingPriceResetCheckCommand extends Command
{
    protected $signature = 'erp-v5:hotfix-cogs-selling-reset-check';
    protected $description = 'Validate Warehouse selling-price COGS, Item Sold hardening, Reset COGS routes and Access Matrix.';

    public function handle(): int
    {
        $checks = [];
        $checks['Reset preview route'] = Route::has('cogs.reset.preview');
        $checks['Reset run route'] = Route::has('cogs.reset.run');
        $checks['Reset permission menu migrated'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'cogs-reset')->where('path', '/cogs/reset')->where('is_active', true)->exists();
        $checks['Reset permissions exist'] = Schema::hasTable('permissions')
            && collect(['cogs.reset.view', 'cogs.reset.create', 'cogs.reset.update', 'cogs.reset.delete'])
                ->every(fn (string $name): bool => DB::table('permissions')->where('name', $name)->exists());
        $checks['Warehouse invoice tables'] = Schema::hasTable('wh_v3_outgoing_invoices')
            && Schema::hasTable('wh_v3_outgoing_invoice_items')
            && Schema::hasTable('wh_v3_goods_receipts');
        $checks['COGS snapshot table'] = Schema::hasTable('cogs_purchasing_cost_snapshots');

        $resolverSource = @file_get_contents(app_path('Services/Cogs/CogsValuationResolverService.php')) ?: '';
        $bridgeSource = @file_get_contents(app_path('Services/Cogs/WarehouseV3ValuationBridgeService.php')) ?: '';
        $resetSource = @file_get_contents(app_path('Services/Cogs/CogsResetService.php')) ?: '';
        $saleSource = @file_get_contents(app_path('Services/Cogs/SaleConsumptionService.php')) ?: '';

        $checks['Warehouse selling price resolver'] = str_contains($resolverSource, 'warehouse_selling_price')
            && str_contains($resolverSource, 'lineTotal / $qty');
        $checks['Warehouse snapshot selling price'] = str_contains($bridgeSource, "'price_source_snapshot' => 'warehouse_selling_price'")
            && str_contains($bridgeSource, 'warehouse_invoice_selling_price');
        $checks['Item Sold valuation cache'] = str_contains($resolverSource, 'private array $cache')
            && str_contains($saleSource, 'valuationResolver->clearCache()');
        $checks['Reset excludes master recipe'] = ! str_contains($resetSource, "DB::table('cogs_recipes')->delete")
            && ! str_contains($resetSource, "DB::table('cogs_uom_conversions')->delete");

        $rows = collect($checks)->map(fn (bool $ok, string $name): array => [$name, $ok ? 'PASS' : 'FAIL'])->values()->all();
        $this->table(['Check', 'Result'], $rows);
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
