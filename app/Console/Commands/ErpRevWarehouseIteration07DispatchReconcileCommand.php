<?php

namespace App\Console\Commands;

use App\Services\Warehouse\Iteration07\WarehouseDispatchStockV7Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpRevWarehouseIteration07DispatchReconcileCommand extends Command
{
    protected $signature='erp-rev:warehouse-iteration-07-dispatch-reconcile {--apply : Apply safe linking/backfill for eligible legacy in-flight DO}';
    protected $description='Dry-run/safe reconcile legacy DO dispatch stock without mutating completed history or Transfer v4 idempotency.';

    public function handle(WarehouseDispatchStockV7Service $service): int
    {
        if(!Schema::hasTable('wh_v3_delivery_orders')||!Schema::hasColumn('wh_v3_delivery_orders','dispatch_ledger_posting_id')){$this->error('Iteration 07 migration belum terpasang.');return self::FAILURE;}
        $rows=DB::table('wh_v3_delivery_orders as d')->leftJoin('wh_v3_goods_receipts as g','g.delivery_order_id','=','d.id')->whereNotNull('d.dispatched_at')->whereNull('d.dispatch_ledger_posting_id')->orderBy('d.dispatched_at')->get(['d.id','d.delivery_number','d.warehouse_id','d.source_type','d.status','d.dispatched_at','d.prepared_by_user_id','d.sender_user_id','g.status as gr_status','g.ledger_posting_id']);
        $summary=['link_existing'=>0,'eligible_inflight'=>0,'transfer_v4_skip'=>0,'completed_manual_audit'=>0,'missing_actor'=>0,'applied'=>0,'failed'=>0]; $apply=(bool)$this->option('apply');
        foreach($rows as $row){
            if($row->ledger_posting_id){$summary['link_existing']++;if($apply){DB::table('wh_v3_delivery_orders')->where('id',$row->id)->update(['dispatch_ledger_posting_id'=>$row->ledger_posting_id,'stock_dispatched_at'=>$row->dispatched_at,'updated_at'=>now()]);$summary['applied']++;}continue;}
            if((string)$row->source_type==='transfer_stock'){$summary['transfer_v4_skip']++;continue;}
            if(!in_array((string)$row->status,['dispatched','receiving'],true)){$summary['completed_manual_audit']++;continue;}
            $actor=(string)($row->prepared_by_user_id ?: $row->sender_user_id ?: ''); if($actor===''){$summary['missing_actor']++;continue;}
            $summary['eligible_inflight']++;if(!$apply)continue;
            try{DB::transaction(fn()=>$service->ensurePosted((string)$row->warehouse_id,(string)$row->id,$actor),5);$summary['applied']++;}catch(Throwable $e){$summary['failed']++;$this->error(($row->delivery_number?:$row->id).' · '.$e->getMessage());}
        }
        $this->table(['Category','Count'],collect($summary)->map(fn($v,$k)=>[str_replace('_',' ',$k),(string)$v])->values()->all());
        if(!$apply)$this->info('DRY RUN. Gunakan --apply hanya setelah hasil diperiksa.');
        if($summary['transfer_v4_skip'])$this->warn('Transfer v4 sengaja tidak diposting ulang oleh command ini.');
        if($summary['completed_manual_audit'])$this->warn('DO completed tanpa posting perlu audit manual; history completed tidak dimutasi otomatis.');
        return $summary['failed']?self::FAILURE:self::SUCCESS;
    }
}
