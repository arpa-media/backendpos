<?php

namespace App\Console\Commands;

use App\Services\Cogs\WarehouseV3ReceiptCogsRepairService;
use App\Services\Purchasing\WarehouseV3PurchasingGoodsReceiptBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5HotfixWarehouseV3PurchasingCogsReconcileCommand extends Command
{
    protected $signature = 'erp-v5:hotfix-v3-gr-cogs-reconcile
        {--gr= : Warehouse V3 Goods Receipt ULID tertentu}
        {--outlet= : Outlet ULID tertentu}
        {--dry-run : Analisis tanpa menulis perubahan}';

    protected $description = 'Hotfix: backfill Purchasing GR dari Warehouse V3 completed GR dan revalue zero-cost COGS untuk SKU yang sudah memiliki valuation.';

    public function handle(
        WarehouseV3PurchasingGoodsReceiptBridgeService $purchasing,
        WarehouseV3ReceiptCogsRepairService $cogs,
    ): int {
        $dryRun=(bool)$this->option('dry-run');
        $grId=$this->nullable($this->option('gr'));
        $outletId=$this->nullable($this->option('outlet'));

        $this->info('ERP V5 HOTFIX — Warehouse V3 Purchasing GR + COGS Reconcile');
        $this->line('Mode: '.($dryRun?'DRY-RUN':'REPAIR'));
        if ($grId) $this->line('Warehouse GR: '.$grId);
        if ($outletId) $this->line('Outlet: '.$outletId);

        $purchase=$purchasing->reconcile($outletId,$grId,$dryRun);
        $this->newLine();
        $this->info('Warehouse V3 → Purchasing Goods Receipt');
        $this->table(['Metric','Value'],collect((array)($purchase['summary']??[]))->map(fn($v,$k)=>[$k,$v])->values()->all());

        $query=DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->where('g.status','completed')
            ->where('g.destination_type','outlet')
            ->where('d.source_type','stock_request');
        if ($grId) $query->where('g.id',$grId);
        if ($outletId) $query->where('g.destination_id',$outletId);

        $summary=['receipts'=>0,'snapshot_created'=>0,'snapshot_repaired'=>0,'cost_unresolved'=>0,'consumption_scanned'=>0,'consumption_revalued'=>0,'consumption_unresolved'=>0,'closed_period_skipped'=>0,'parents_recalculated'=>0,'errors'=>0];
        $details=[];
        foreach ($query->orderBy('g.completed_at')->pluck('g.id') as $id) {
            $summary['receipts']++;
            try {
                $result=$cogs->repair((string)$id,null,$dryRun);
                $details[]=$result;
                $bridge=(array)($result['valuation_bridge']??[]);
                $rv=(array)($result['consumption_revaluation']??[]);
                $summary['snapshot_created']+=(int)($bridge['created']??0);
                $summary['snapshot_repaired']+=(int)($bridge['repaired']??0);
                $summary['cost_unresolved']+=(int)($bridge['unresolved_cost']??0);
                $summary['consumption_scanned']+=(int)($rv['scanned']??0);
                $summary['consumption_revalued']+=(int)($rv['revalued']??0);
                $summary['consumption_unresolved']+=(int)($rv['unresolved']??0);
                $summary['closed_period_skipped']+=(int)($rv['closed_period_skipped']??0);
                $summary['parents_recalculated']+=(int)($rv['parents_recalculated']??0);
            } catch (\Throwable $e) {
                $summary['errors']++;
                $details[]=['warehouse_gr_id'=>(string)$id,'error'=>$e->getMessage()];
            }
        }

        $this->newLine();
        $this->info('Warehouse valuation → Item Sold / Recipe Consumption');
        $this->table(['Metric','Value'],collect($summary)->map(fn($v,$k)=>[$k,$v])->values()->all());

        $errors=(int)($purchase['summary']['errors']??0)+(int)$summary['errors'];
        $unresolved=(int)$summary['cost_unresolved']+(int)$summary['consumption_unresolved'];
        if ($errors>0) $this->error("Ada {$errors} integration error. Jalankan check command untuk detail gap.");
        if ($unresolved>0) $this->warn("Masih ada {$unresolved} baris tanpa valuation yang dapat ditentukan.");
        if ((int)$summary['closed_period_skipped']>0) $this->warn('Consumption pada periode CLOSED sengaja tidak dimutasi.');

        $this->newLine();
        $this->info($dryRun?'Dry-run selesai. Jalankan ulang tanpa --dry-run untuk apply.':'Repair selesai. Jalankan erp-v5:hotfix-v3-gr-cogs-check.');
        return $errors>0?self::FAILURE:self::SUCCESS;
    }

    private function nullable(mixed $value): ?string
    {
        $value=trim((string)($value??''));
        return $value===''?null:$value;
    }
}
