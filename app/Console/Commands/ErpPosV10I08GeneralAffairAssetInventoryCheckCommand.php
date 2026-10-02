<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I08GeneralAffairAssetInventoryCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i08-general-affair-asset-inventory-check';
    protected $description = 'Verify ERP POS V10 I08 General Affair Asset and Inventory Recap';

    public function handle(): int
    {
        $checks = [
            'ga_assets table'=>Schema::hasTable('ga_assets'), 'ga_inventory_items table'=>Schema::hasTable('ga_inventory_items'),
            'Asset menu'=>Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','ga-asset-recap')->where('path','/general-affair/assets')->exists(),
            'Inventory menu'=>Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','ga-inventory-recap')->where('path','/general-affair/inventory')->exists(),
            'Asset import permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','ga.asset.import')->exists(),
            'Inventory import permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','ga.inventory.import')->exists(),
            'Spreadsheet service'=>class_exists(\App\Services\GeneralAffair\AssetInventorySpreadsheetService::class),
            'Photo service'=>class_exists(\App\Services\GeneralAffair\AssetInventoryPhotoService::class),
            'Asset controller'=>class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairAssetController::class),
            'Inventory controller'=>class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryController::class),
        ];
        $routes = collect(Route::getRoutes())->map(fn ($r) => implode('|',$r->methods()).' '.$r->uri())->all();
        foreach ([
            'Asset list API'=>'api/v1/general-affair/assets', 'Asset import API'=>'api/v1/general-affair/assets/import-xlsx',
            'Inventory list API'=>'api/v1/general-affair/inventory', 'Inventory import API'=>'api/v1/general-affair/inventory/import-xlsx',
        ] as $label=>$needle) $checks[$label] = collect($routes)->contains(fn ($row) => str_contains($row, $needle));

        $failed = 0;
        foreach ($checks as $label=>$ok) { $this->line(sprintf('[%s] %s', $ok ? 'PASS':'FAIL', $label)); if (! $ok) $failed++; }
        if ($failed) { $this->error("ERP POS V10 I08 Asset/Inventory check failed: {$failed} check(s)."); return self::FAILURE; }
        $this->info('ERP POS V10 I08 General Affair Asset & Inventory Recap is READY.');
        return self::SUCCESS;
    }
}
