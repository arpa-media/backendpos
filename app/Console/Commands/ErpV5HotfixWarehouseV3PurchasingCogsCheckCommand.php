<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5HotfixWarehouseV3PurchasingCogsCheckCommand extends Command
{
    protected $signature='erp-v5:hotfix-v3-gr-cogs-check {--outlet= : Outlet ULID tertentu}';
    protected $description='Check Warehouse V3 Stock Request → Purchasing GR dan zero-cost COGS integration.';

    public function handle(): int
    {
        $outlet=trim((string)($this->option('outlet')??''));
        $checks=[];
        $required=[
            'wh_v3_goods_receipts','wh_v3_goods_receipt_items','wh_v3_delivery_orders','wh_v3_delivery_order_items',
            'pur_purchase_orders','pur_purchase_order_items','pur_goods_receipts','pur_goods_receipt_items',
            'cogs_purchasing_cost_snapshots','cogs_sale_consumptions','cogs_sale_consumption_items','stk_inventory_balances',
        ];
        $missing=array_values(array_filter($required,fn($t)=>!Schema::hasTable($t)));
        $checks[]=['Required tables',$missing?implode(', ',$missing):'-',$missing?'FAILED':'PASSED'];
        if ($missing) return $this->finish($checks);

        $completed=DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->where('g.status','completed')->where('g.destination_type','outlet')->where('d.source_type','stock_request');
        if ($outlet!=='') $completed->where('g.destination_id',$outlet);
        $completedCount=(int)(clone $completed)->count();

        $missingPurchasing=(int)(clone $completed)
            ->leftJoin('pur_purchase_orders as po',function($j){$j->on('po.stock_request_id','=','d.source_id')->whereNull('po.deleted_at');})
            ->leftJoin('pur_goods_receipts as pgr',function($j){$j->on('pgr.order_id','=','po.id')->where('pgr.order_kind','=','PURCHASE_ORDER')->whereNull('pgr.deleted_at');})
            ->whereNull('pgr.id')->count();
        $checks[]=['Completed Warehouse V3 Stock Request GR',$completedCount,'INFO'];
        $checks[]=['Missing Purchasing GR',$missingPurchasing,$missingPurchasing===0?'PASSED':'FAILED'];

        $missingSnapshot=(int)(clone $completed)
            ->join('wh_v3_goods_receipt_items as gi','gi.goods_receipt_id','=','g.id')
            ->leftJoin('cogs_purchasing_cost_snapshots as cs','cs.goods_receipt_item_id','=','gi.id')
            ->where('gi.received_qty_base','>',0)
            ->whereNull('cs.id')->count();
        $checks[]=['Warehouse GR item without COGS snapshot',$missingSnapshot,$missingSnapshot===0?'PASSED':'FAILED'];

        $zeroCostQuery=DB::table('cogs_sale_consumption_items as ci')
            ->join('cogs_sale_consumptions as c','c.id','=','ci.consumption_id')
            ->join('stk_inventory_balances as b',function($j){$j->on('b.outlet_id','=','c.outlet_id')->on('b.sku_id','=','ci.sku_id');})
            ->whereIn('c.status',['posted','reversed'])
            ->where('b.average_unit_cost','>',0)
            ->where(function($q){$q->where('ci.unit_cost_snapshot','<=',0)->orWhere('ci.total_cost','<=',0);});
        if ($outlet!=='') $zeroCostQuery->where('c.outlet_id',$outlet);
        if (Schema::hasTable('cogs_calculation_runs')) {
            $zeroCostQuery->whereNotExists(function($q){
                $q->selectRaw('1')->from('cogs_calculation_runs as run')
                    ->whereColumn('run.outlet_id','c.outlet_id')->where('run.status','closed')
                    ->whereColumn('run.period_from','<=','c.business_date')->whereColumn('run.period_to','>=','c.business_date');
            });
        }
        $zeroWithAvailableValuation=(int)$zeroCostQuery->count();
        $checks[]=['Open consumption cost=0 although current valuation > 0',$zeroWithAvailableValuation,$zeroWithAvailableValuation===0?'PASSED':'FAILED'];

        $controller=app_path('Http/Controllers/Api/V1/Warehouse/LogisticsV3/WarehouseLogisticsFinanceIteration04Controller.php');
        $source=is_file($controller)?file_get_contents($controller):'';
        $hook=str_contains($source,'WarehouseV3CompletionIntegrationService')&&str_contains($source,'afterCompletedReceipt');
        $checks[]=['Warehouse completion downstream hook',$hook?'available':'missing',$hook?'PASSED':'FAILED'];

        return $this->finish($checks);
    }

    private function finish(array $checks): int
    {
        $this->table(['Check','Result','Status'],$checks);
        $failed=collect($checks)->contains(fn($r)=>($r[2]??'')==='FAILED');
        $this->newLine();
        $this->{$failed?'error':'info'}('Status: '.($failed?'FAILED':'PASSED'));
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
