<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I09GeneralAffairInventoryLogsCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i09-general-affair-inventory-logs-check';
    protected $description = 'Verify ERP POS V10 I09 General Affair Inventory Logs and transactional location balances';

    public function handle(): int
    {
        $checks = [
            'ga_inventory_movements table' => Schema::hasTable('ga_inventory_movements'),
            'Before/after state columns' => Schema::hasTable('ga_inventory_movements') && Schema::hasColumns('ga_inventory_movements', ['item_qty_before','item_qty_after','source_qty_before','source_qty_after','destination_qty_before','destination_qty_after']),
            'ga_asset_location_balances table' => Schema::hasTable('ga_asset_location_balances'),
            'ga_inventory_location_balances table' => Schema::hasTable('ga_inventory_location_balances'),
            'Inventory Logs menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'ga-inventory-logs')->where('path', '/general-affair/inventory-logs')->exists(),
            'View permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.inventory_log.view')->exists(),
            'Create permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.inventory_log.create')->exists(),
            'Movement service' => class_exists(\App\Services\GeneralAffair\InventoryMovementService::class),
            'Photo service' => class_exists(\App\Services\GeneralAffair\InventoryMovementPhotoService::class),
            'Controller' => class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryLogController::class),
            'Movement model' => class_exists(\App\Models\GeneralAffair\InventoryMovement::class),
        ];

        $routes = collect(Route::getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->all();
        foreach ([
            'Inventory Logs list API' => 'api/v1/general-affair/inventory-logs',
            'Inventory Logs item lookup API' => 'api/v1/general-affair/inventory-logs/items',
            'Inventory Logs create API' => 'api/v1/general-affair/inventory-logs',
        ] as $label => $needle) {
            $checks[$label] = collect($routes)->contains(fn ($row) => str_contains($row, $needle));
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }
        if ($failed) {
            $this->error("ERP POS V10 I09 Inventory Logs check failed: {$failed} check(s).");
            return self::FAILURE;
        }
        $this->info('ERP POS V10 I09 General Affair Inventory Logs is READY.');
        return self::SUCCESS;
    }
}
