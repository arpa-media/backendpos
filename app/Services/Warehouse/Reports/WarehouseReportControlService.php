<?php

namespace App\Services\Warehouse\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WarehouseReportControlService
{
    public function executive(array $filters): array
    {
        [$from,$to,$warehouseId] = $this->filters($filters);
        $sales = $this->sum('wh_sales_invoices','grand_total',$from,$to,$warehouseId,'invoice_date',['status','!=','void']);
        $purchase = $this->sum('wh_supplier_invoices','grand_total',$from,$to,$warehouseId,'invoice_date',['status','!=','void']);
        $cogs = $this->sum('wh_sales_invoices','cogs_total',$from,$to,$warehouseId,'invoice_date',['status','!=','void']);
        $margin = $this->sum('wh_sales_invoices','gross_margin',$from,$to,$warehouseId,'invoice_date',['status','!=','void']);
        $inventory = $this->inventoryTotals($warehouseId);
        $ar = $this->sumCurrent('wh_sales_invoices','balance_due',$warehouseId,['status','!=','void']);
        $ap = $this->sumCurrent('wh_supplier_invoices','balance_due',$warehouseId,['status','!=','void']);
        $openExceptions = Schema::hasTable('wh_reconciliation_exceptions') ? DB::table('wh_reconciliation_exceptions')->where('status','open')->when($warehouseId,fn($q)=>$q->where('warehouse_id',$warehouseId))->count() : 0;
        return compact('sales','purchase','cogs','margin','inventory','ar','ap','openExceptions') + ['from'=>$from,'to'=>$to];
    }

    public function operations(array $filters): array
    {
        [$from,$to,$warehouseId] = $this->filters($filters);
        return [
            'sales_by_customer'=>$this->grouped('wh_sales_invoices','customer_id','grand_total','invoice_date',$from,$to,$warehouseId),
            'purchase_by_supplier'=>$this->grouped('wh_supplier_invoices','supplier_source_id','grand_total','invoice_date',$from,$to,$warehouseId),
            'inventory'=>$this->inventoryRows($warehouseId),
            'expiry'=>$this->expiryRows($warehouseId),
            'stock_movements'=>$this->movementRows($from,$to,$warehouseId),
        ];
    }

    public function finance(array $filters): array
    {
        [$from,$to,$warehouseId] = $this->filters($filters);
        return [
            'ar_aging'=>$this->aging('wh_sales_invoices',$warehouseId),
            'ap_aging'=>$this->aging('wh_supplier_invoices',$warehouseId),
            'margin'=>$this->marginRows($from,$to,$warehouseId),
            'customer_receipts'=>$this->sum('wh_customer_receipts','amount',$from,$to,$warehouseId,'receipt_date',['status','!=','void']),
            'supplier_payments'=>$this->sum('wh_supplier_payments','amount',$from,$to,$warehouseId,'payment_date',['status','!=','void']),
        ];
    }

    public function audit(array $filters): array
    {
        [$from,$to,$warehouseId] = $this->filters($filters);
        $tables=['wh_sales_order_events','wh_logistics_events','wh_finance_events','wh_purchasing_events','wh_production_events','wh_stock_unit_events'];
        $rows=[];
        foreach($tables as $table){
            if(!Schema::hasTable($table)) continue;
            $query=DB::table($table)->whereBetween('created_at',[$from.' 00:00:00',$to.' 23:59:59']);
            if($warehouseId && Schema::hasColumn($table,'warehouse_id')) $query->where('warehouse_id',$warehouseId);
            foreach($query->latest('created_at')->limit(100)->get() as $row){
                $a=(array)$row;$rows[]=['source'=>$table,'event'=>$a['event_type']??$a['event']??$a['action']??'-','document_number'=>$a['document_number']??$a['reference_number']??null,'entity_id'=>$a['entity_id']??$a['sales_order_id']??$a['production_id']??null,'actor_id'=>$a['created_by']??$a['actor_id']??null,'created_at'=>$a['created_at']??null,'payload'=>$a['payload_json']??$a['metadata']??null];
            }
        }
        usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
        return array_slice($rows,0,300);
    }

    public function runReconciliation(array $filters, ?string $userId): array
    {
        [$from,$to,$warehouseId] = $this->filters($filters);
        $runId=(string)Str::ulid();$exceptions=[];$checks=0;
        DB::transaction(function() use($runId,$warehouseId,$from,$to,$userId,&$exceptions,&$checks){
            DB::table('wh_reconciliation_runs')->insert(['id'=>$runId,'warehouse_id'=>$warehouseId,'scope'=>'all','period_from'=>$from,'period_to'=>$to,'status'=>'running','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
            $definitions=[
                ['sales_gr_without_invoice','wh_sales_goods_receipts','wh_sales_invoices','goods_receipt_id','GR completed belum memiliki Sales Invoice','critical'],
                ['stock_in_without_invoice','wh_stock_ins','wh_supplier_invoices','stock_in_id','Stock In approved belum memiliki Purchase Invoice','critical'],
            ];
            foreach($definitions as [$code,$source,$target,$fk,$message,$severity]){
                if(!Schema::hasTable($source)||!Schema::hasTable($target)) continue;$checks++;
                $q=DB::table($source.' as s')->leftJoin($target.' as t','t.'.$fk,'=','s.id')->whereNull('t.id');
                if(Schema::hasColumn($source,'status')) $q->where('s.status','approved')->orWhere('s.status','completed');
                if($warehouseId && Schema::hasColumn($source,'warehouse_id')) $q->where('s.warehouse_id',$warehouseId);
                foreach($q->select('s.*')->limit(500)->get() as $row){$a=(array)$row;$exceptions[]=$this->exception($runId,$warehouseId,$code,$severity,$source,(string)$a['id'],$a['document_number']??$a['gr_number']??$a['stock_in_number']??null,$message);}
            }
            if(Schema::hasTable('wh_sales_invoices')){$checks++;foreach(DB::table('wh_sales_invoices')->whereRaw('ABS(grand_total - paid_total - balance_due) > 0.01')->limit(500)->get() as $r){$exceptions[]=$this->exception($runId,$warehouseId,'ar_balance_mismatch','critical','wh_sales_invoices',(string)$r->id,$r->invoice_number??null,'Grand total, pembayaran, dan saldo piutang tidak seimbang',(float)$r->grand_total-(float)$r->paid_total,(float)$r->balance_due);}}
            if(Schema::hasTable('wh_supplier_invoices')){$checks++;foreach(DB::table('wh_supplier_invoices')->whereRaw('ABS(grand_total - paid_total - balance_due) > 0.01')->limit(500)->get() as $r){$exceptions[]=$this->exception($runId,$warehouseId,'ap_balance_mismatch','critical','wh_supplier_invoices',(string)$r->id,$r->invoice_number??null,'Grand total, pembayaran, dan saldo hutang tidak seimbang',(float)$r->grand_total-(float)$r->paid_total,(float)$r->balance_due);}}
            if(Schema::hasTable('wh_batch_balances')){$checks++;foreach(DB::table('wh_batch_balances')->where('on_hand_qty','<',0)->limit(500)->get() as $r){$exceptions[]=$this->exception($runId,$warehouseId,'negative_inventory','critical','wh_batch_balances',(string)$r->id,null,'Saldo batch negatif',0,(float)$r->on_hand_qty);}}
            if($exceptions) DB::table('wh_reconciliation_exceptions')->insert($exceptions);
            $failed=count($exceptions);DB::table('wh_reconciliation_runs')->where('id',$runId)->update(['status'=>'completed','total_checks'=>$checks,'passed_checks'=>max(0,$checks-$failed),'failed_checks'=>$failed,'summary_json'=>json_encode(['exception_count'=>$failed]),'completed_at'=>now(),'updated_at'=>now()]);
        });
        return ['run_id'=>$runId,'checks'=>$checks,'exceptions'=>count($exceptions)];
    }

    public function reconciliation(array $filters): array
    {
        $warehouseId=$filters['warehouse_id']??null;
        $runs=Schema::hasTable('wh_reconciliation_runs')?DB::table('wh_reconciliation_runs')->when($warehouseId,fn($q)=>$q->where('warehouse_id',$warehouseId))->latest()->limit(50)->get():collect();
        $exceptions=Schema::hasTable('wh_reconciliation_exceptions')?DB::table('wh_reconciliation_exceptions')->when($warehouseId,fn($q)=>$q->where('warehouse_id',$warehouseId))->latest()->limit(300)->get():collect();
        return ['runs'=>$runs,'exceptions'=>$exceptions];
    }

    public function resolve(string $id,string $notes,?string $userId): void
    { DB::table('wh_reconciliation_exceptions')->where('id',$id)->update(['status'=>'resolved','resolution_notes'=>$notes,'resolved_by'=>$userId,'resolved_at'=>now(),'updated_at'=>now()]); }

    private function filters(array $f): array { return [$f['from']??now()->startOfMonth()->toDateString(),$f['to']??now()->toDateString(),$f['warehouse_id']??null]; }
    private function sum(string $t,string $c,string $f,string $to,?string $w,string $d,array $cond): float { if(!Schema::hasTable($t)||!Schema::hasColumn($t,$c))return 0; $q=DB::table($t)->whereBetween($d,[$f,$to]);if($w&&Schema::hasColumn($t,'warehouse_id'))$q->where('warehouse_id',$w);if(Schema::hasColumn($t,$cond[0]))$q->where($cond[0],$cond[1],$cond[2]);return(float)$q->sum($c); }
    private function sumCurrent(string $t,string $c,?string $w,array $cond): float { if(!Schema::hasTable($t))return 0;$q=DB::table($t);if($w&&Schema::hasColumn($t,'warehouse_id'))$q->where('warehouse_id',$w);if(Schema::hasColumn($t,$cond[0]))$q->where($cond[0],$cond[1],$cond[2]);return(float)$q->sum($c); }
    private function inventoryTotals(?string $w): array {if(!Schema::hasTable('wh_batch_balances'))return ['qty'=>0,'value'=>0];$q=DB::table('wh_batch_balances');if($w&&Schema::hasColumn('wh_batch_balances','warehouse_id'))$q->where('warehouse_id',$w);return ['qty'=>(float)$q->sum('on_hand_qty'),'value'=>(float)$q->sum('inventory_value')];}
    private function grouped(string $t,string $group,string $sum,string $date,string $f,string $to,?string $w): array {if(!Schema::hasTable($t)||!Schema::hasColumn($t,$group))return[];$q=DB::table($t)->select($group,DB::raw("SUM($sum) total"),DB::raw('COUNT(*) documents'))->whereBetween($date,[$f,$to])->groupBy($group);if($w&&Schema::hasColumn($t,'warehouse_id'))$q->where('warehouse_id',$w);return $q->orderByDesc('total')->limit(100)->get()->all();}
    private function inventoryRows(?string $w): array {if(!Schema::hasTable('wh_batch_balances'))return[];$q=DB::table('wh_batch_balances')->select('*');if($w&&Schema::hasColumn('wh_batch_balances','warehouse_id'))$q->where('warehouse_id',$w);return $q->orderBy('sku_id')->limit(500)->get()->all();}
    private function expiryRows(?string $w): array {if(!Schema::hasTable('wh_batches')||!Schema::hasColumn('wh_batches','expiry_date'))return[];$q=DB::table('wh_batches')->whereNotNull('expiry_date')->where('expiry_date','<=',now()->addDays(90)->toDateString());if($w&&Schema::hasColumn('wh_batches','warehouse_id'))$q->where('warehouse_id',$w);return$q->orderBy('expiry_date')->limit(300)->get()->all();}
    private function movementRows(string $f,string $to,?string $w): array {if(!Schema::hasTable('wh_ledger_entries'))return[];$q=DB::table('wh_ledger_entries')->whereBetween('created_at',[$f.' 00:00:00',$to.' 23:59:59']);if($w&&Schema::hasColumn('wh_ledger_entries','warehouse_id'))$q->where('warehouse_id',$w);return$q->latest()->limit(500)->get()->all();}
    private function aging(string $t,?string $w): array {if(!Schema::hasTable($t))return[];$q=DB::table($t)->where('balance_due','>',0)->where('status','!=','void');if($w&&Schema::hasColumn($t,'warehouse_id'))$q->where('warehouse_id',$w);$rows=$q->get();$b=['current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'over_90'=>0];foreach($rows as$r){$days=now()->startOfDay()->diffInDays($r->due_date??now(),false)*-1;$key=$days<=0?'current':($days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'over_90')));$b[$key]+=(float)$r->balance_due;}return$b;}
    private function marginRows(string $f,string $to,?string $w): array {if(!Schema::hasTable('wh_sales_invoice_items'))return[];$q=DB::table('wh_sales_invoice_items as i')->join('wh_sales_invoices as h','h.id','=','i.sales_invoice_id')->select('i.sku_id',DB::raw('SUM(i.quantity_base) quantity'),DB::raw('SUM(i.line_total) revenue'),DB::raw('SUM(i.cogs_total) cogs'),DB::raw('SUM(i.line_total-i.cogs_total) margin'))->whereBetween('h.invoice_date',[$f,$to])->where('h.status','!=','void')->groupBy('i.sku_id');if($w)$q->where('h.warehouse_id',$w);return$q->orderByDesc('margin')->limit(300)->get()->all();}
    private function exception($run,$w,$code,$severity,$type,$id,$doc,$msg,$expected=null,$actual=null): array{return['id'=>(string)Str::ulid(),'run_id'=>$run,'warehouse_id'=>$w,'check_code'=>$code,'severity'=>$severity,'entity_type'=>$type,'entity_id'=>$id,'document_number'=>$doc,'expected_value'=>$expected,'actual_value'=>$actual,'variance_value'=>($expected!==null&&$actual!==null)?$actual-$expected:null,'message'=>$msg,'status'=>'open','created_at'=>now(),'updated_at'=>now()];}
}
