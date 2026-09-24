<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration07CheckCommand extends Command
{
    protected $signature='erp-rev:hr-warehouse-iteration-07-check';
    protected $description='Verify ERP REV Iteration 07 DO Dispatch stock and Production Material Opname.';

    public function handle(): int
    {
        $checks=[
            'DO dispatch ledger columns exist'=>Schema::hasTable('wh_v3_delivery_orders')&&Schema::hasColumn('wh_v3_delivery_orders','dispatch_ledger_posting_id')&&Schema::hasColumn('wh_v3_delivery_orders','stock_dispatched_at'),
            'Production Stock table exists'=>Schema::hasTable('wh_v7_production_stock_balances'),
            'Production allocation table exists'=>Schema::hasTable('wh_v7_production_material_allocations'),
            'Production Opname table exists'=>Schema::hasTable('wh_v7_production_material_opnames'),
            'Production Opname items table exists'=>Schema::hasTable('wh_v7_production_material_opname_items'),
            'Production Opname list route exists'=>Route::has('warehouse.production-v7.opname.orders.i07'),
            'Production Opname detail route exists'=>Route::has('warehouse.production-v7.material-opname.show.i07'),
            'Production Opname finalize route exists'=>Route::has('warehouse.production-v7.material-opname.finalize.i07'),
            'Dispatch service available'=>class_exists(\App\Services\Warehouse\Iteration07\WarehouseDispatchStockV7Service::class),
            'Production Opname service available'=>class_exists(\App\Services\Warehouse\Iteration07\WarehouseProductionMaterialOpnameV7Service::class),
        ];

        if(Schema::hasTable('permissions')) foreach(['warehouse.production.opname.view','warehouse.production.opname.create','warehouse.production.opname.update','warehouse.production.opname.delete','warehouse.production.opname.finalize'] as $p) $checks["Permission {$p} exists"]=DB::table('permissions')->where('name',$p)->exists();
        if(Schema::hasTable('access_menus')){
            $menu=DB::table('access_menus')->where('path','/warehouse/production/opname')->first();
            $checks['Production Opname Access Matrix canonical']=$menu
                &&(!Schema::hasColumn('access_menus','permission_view')||$menu->permission_view==='warehouse.production.opname.view')
                &&(!Schema::hasColumn('access_menus','permission_update')||$menu->permission_update==='warehouse.production.opname.update');
        }

        $logistics=@file_get_contents(app_path('Services/Warehouse/LogisticsV3/WarehouseLogisticsV3Service.php'))?:'';
        $dispatch=@file_get_contents(app_path('Services/Warehouse/Iteration07/WarehouseDispatchStockV7Service.php'))?:'';
        $sales=@file_get_contents(app_path('Services/Warehouse/SalesV3/WarehouseSalesDemandV3Service.php'))?:'';
        $prod=@file_get_contents(app_path('Services/Warehouse/ProductionV3/WarehouseProductionV3Service.php'))?:'';
        $transfer=@file_get_contents(app_path('Services/Warehouse/SalesTransferV3/WarehouseLogisticsV7ExtensionService.php'))?:'';
        $finance=@file_get_contents(app_path('Services/Warehouse/FinanceV4/WarehouseAutoPostingV4Service.php'))?:'';
        $front=base_path('../frontend - Backoffice/src/modules/warehouse');
        $page=@file_get_contents($front.'/pages/WarehouseProductionOpnameV7Page.vue')?:'';
        $demandPage=@file_get_contents($front.'/pages/WarehouseSalesDemandV3Page.vue')?:'';
        $nav=@file_get_contents($front.'/lib/warehouseV3Navigation.js')?:'';

        $checks['DO posts OUT at Dispatch']=str_contains($logistics,'$this->dispatchStock->ensurePosted($warehouseId,$deliveryId,$userId)')&&str_contains($dispatch,"'stock_point'=>'delivery_order_dispatch'");
        $checks['Old GR stock-out key removed']=!str_contains($logistics,'WAREHOUSE-V3-LOGISTICS-OUT:');
        $checks['GR reuses Dispatch posting']=str_contains($logistics,'$this->dispatchStock->ensurePosted($warehouseId,(string)$d->id,$userId)');
        $checks['Transfer v4 idempotency preserved']=str_contains($transfer,'WAREHOUSE-V4-TRANSFER-OUT:')&&str_contains($transfer,'markIteration07DispatchAudit');
        $checks['Production Request uses carry-forward']=str_contains($sales,'reserveForMaterial(')&&str_contains($sales,'production_stock_carry_qty_base');
        $checks['Production result requires finalized material opname']=str_contains($prod,'requireFinalizedBeforeResult($productionId)');
        $checks['Finance supports carry-only production']=str_contains($finance,'production_stock_carry_only');
        $checks['Production Opname UI active']=str_contains($page,'Actual Bahan Produksi')&&str_contains($page,'Production Stock');
        $checks['Production Request UI shows Need WH']=str_contains($demandPage,'Need WH')&&str_contains($demandPage,'Production Stock');
        $checks['Production Opname navigation active']=str_contains($nav,"path: '/warehouse/production/opname'")&&str_contains($nav,'placeholder: false');

        if(Schema::hasTable('wh_v7_production_stock_balances')){
            $checks['Production Stock no negative qty']=!DB::table('wh_v7_production_stock_balances')->where('qty_base','<',-0.0001)->exists();
            $checks['Production Stock no negative value']=!DB::table('wh_v7_production_stock_balances')->where('inventory_value','<',-0.01)->exists();
        }
        if(Schema::hasTable('wh_v7_production_material_opname_items')){
            $checks['Finalized remaining <= available']=!DB::table('wh_v7_production_material_opname_items as i')->join('wh_v7_production_material_opnames as o','o.id','=','i.opname_id')->where('o.status','finalized')->whereRaw('i.remaining_qty_base>i.available_qty_base+0.0001')->exists();
        }

        $failed=false; foreach($checks as $label=>$ok){$ok=(bool)$ok;$this->line(sprintf('[%s] %s',$ok?'OK':'FAIL',$label));if(!$ok)$failed=true;}
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
