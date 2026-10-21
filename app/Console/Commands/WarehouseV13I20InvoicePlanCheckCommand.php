<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class WarehouseV13I20InvoicePlanCheckCommand extends Command {
    protected $signature='warehouse:v13-i20:check {--warehouse= : Warehouse outlet ULID} {--limit=200 : Maksimum rencana yang diaudit}';
    protected $description='Periksa outstanding termin, alokasi, dan pembayaran posted (read-only).';
    public function handle():int {
        foreach(['wh_v13_i20_invoice_plans','wh_v13_i20_invoice_terms','wh_v13_i20_term_allocations','wh_v3_invoice_payments'] as $table){
            if(!Schema::hasTable($table)){$this->error("Tabel {$table} belum tersedia.");return self::FAILURE;}
        }
        $limit=max(1,min((int)$this->option('limit'),1000));
        $q=DB::table('wh_v13_i20_invoice_plans')->orderBy('created_at')->orderBy('id');
        if($this->option('warehouse'))$q->where('warehouse_id',(string)$this->option('warehouse'));
        $plans=$q->limit($limit+1)->get();$issues=[];$count=0;
        foreach($plans->take($limit) as $r){
            $count++;$terms=DB::table('wh_v13_i20_invoice_terms')->where('plan_id',$r->id)->get();
            if(abs(round((float)$terms->sum('amount'),2)-(float)$r->invoice_total)>0.009)$issues[]="{$r->id}: nominal termin tidak sama dengan total invoice";
            $allocation=0.0;
            foreach($terms as $term){
                $paid=round((float)DB::table('wh_v13_i20_term_allocations')->where('term_id',$term->id)->sum('amount'),2);
                if($paid>(float)$term->amount+0.009)$issues[]="{$r->id}: termin {$term->sequence} over-allocated";
                $allocation+=$paid;
            }
            $payments=round((float)DB::table('wh_v3_invoice_payments')->where('warehouse_id',$r->warehouse_id)->where('direction',$r->direction)->where('document_source',$r->document_source)->where('document_id',$r->document_id)->where('status','posted')->sum('amount'),2);
            if(abs($allocation-$payments)>0.02)$issues[]="{$r->id}: alokasi {$allocation} != posted {$payments}";
            if($payments>(float)$r->invoice_total+0.009)$issues[]="{$r->id}: pembayaran melebihi total";
        }
        $this->line("I20 audit: {$count} invoice, ".count($issues)." anomaly.");
        foreach(array_slice($issues,0,100) as $m)$this->warn($m);
        if($plans->count()>$limit)$this->warn('Result truncated: gunakan --warehouse/--limit agar pemeriksaan lengkap.');
        return $issues?self::FAILURE:self::SUCCESS;
    }
}
