<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseV2PackageBarcodeCheckCommand extends Command
{
    protected $signature = 'warehouse:v2-package-barcode-check';
    protected $description = 'Validate Warehouse package barcode schema, routes, access matrix, and quantity invariants.';

    public function handle(): int
    {
        $tables=['wh_stock_units','wh_stock_unit_events','wh_package_opname_counts'];
        $columns=['original_qty_base','remaining_qty_base','package_uom_id','package_conversion_factor','parent_stock_unit_id','root_stock_unit_id','package_state','lock_version'];
        $routes=['warehouse.inventory.package-barcodes.index','warehouse.inventory.package-barcodes.options','warehouse.inventory.package-barcodes.configure','warehouse.inventory.package-barcodes.split','warehouse.inventory.package-barcodes.relabel','warehouse.inventory.package-barcodes.consume','warehouse.inventory.package-barcodes.opname-count','warehouse.inventory.package-barcodes.events'];
        $permissions=['warehouse.inventory.package.view','warehouse.inventory.package.create','warehouse.inventory.package.update','warehouse.inventory.package.delete'];
        $missingTables=array_values(array_filter($tables,fn($t)=>!Schema::hasTable($t)));
        $missingColumns=Schema::hasTable('wh_stock_units')?array_values(array_filter($columns,fn($c)=>!Schema::hasColumn('wh_stock_units',$c))):$columns;
        $missingRoutes=array_values(array_filter($routes,fn($r)=>!Route::has($r)));
        $missingPermissions=Schema::hasTable('permissions')?array_values(array_filter($permissions,fn($p)=>!DB::table('permissions')->where('name',$p)->exists())):$permissions;
        $invalidRemaining=Schema::hasTable('wh_stock_units')&&Schema::hasColumn('wh_stock_units','remaining_qty_base')?(int)DB::table('wh_stock_units')->where(fn($q)=>$q->where('remaining_qty_base','<',0)->orWhereColumn('remaining_qty_base','>','original_qty_base'))->count():-1;
        $qtyMismatch=Schema::hasTable('wh_stock_units')&&Schema::hasColumn('wh_stock_units','remaining_qty_base')?(int)DB::table('wh_stock_units')->whereRaw('ABS(qty_base - remaining_qty_base) > 0.0001')->count():-1;
        $missingRoot=Schema::hasTable('wh_stock_units')&&Schema::hasColumn('wh_stock_units','root_stock_unit_id')?(int)DB::table('wh_stock_units')->whereNull('root_stock_unit_id')->count():-1;
        $rows=[['Missing tables',$missingTables?implode(', ',$missingTables):'-'],['Missing columns',$missingColumns?implode(', ',$missingColumns):'-'],['Missing routes',$missingRoutes?implode(', ',$missingRoutes):'-'],['Missing permissions',$missingPermissions?implode(', ',$missingPermissions):'-'],['Invalid remaining quantity',(string)$invalidRemaining],['qty_base mismatch',(string)$qtyMismatch],['Missing root trace',(string)$missingRoot]];
        $failed=$missingTables||$missingColumns||$missingRoutes||$missingPermissions||$invalidRemaining>0||$qtyMismatch>0||$missingRoot>0;
        $rows[]=['Status',$failed?'FAILED':'PASSED']; $this->table(['Check','Result'],$rows);
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
