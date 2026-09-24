<?php

namespace App\Services\Warehouse\ProductionCogs\I07;

use App\Services\Warehouse\FinanceV4\WarehouseAutoPostingV4Service;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseProductionCogsReconciliationI07Service
{
    public const CONTRACT_VERSION = 'WAREHOUSE_PRODUCTION_COGS_RECON_I07';

    public function __construct(private readonly WarehouseAutoPostingV4Service $finance) {}

    public function index(string $warehouseId, array $filters): array
    {
        $from = $filters['from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now('Asia/Jakarta')->toDateString();
        $perPage = max(10, min((int)($filters['per_page'] ?? 25), 100));
        $query = DB::table('wh_productions as p')
            ->leftJoin('wh_i07_production_cogs_reconciliations as r','r.production_id','=','p.id')
            ->where('p.warehouse_id',$warehouseId)
            ->whereIn('p.status',['completed','approved'])
            ->whereBetween('p.production_date',[$from,$to])
            ->select([
                'p.id','p.production_number','p.production_date','p.status','p.finished_at','p.actual_input_value',
                'p.actual_output_value','p.selected_output_value','p.yield_percent','p.labor_cost','p.overhead_cost','p.waste_cost',
                'r.id as reconciliation_id','r.reconciliation_status','r.finance_status','r.final_cogs','r.output_inventory_value',
                'r.variance_value','r.output_qty_base','r.unit_cogs','r.source_fingerprint','r.reconciled_at','r.posted_at',
            ])
            ->orderByDesc('p.production_date')->orderByDesc('p.created_at');
        if (($q=trim((string)($filters['q'] ?? ''))) !== '') {
            $like='%'.$q.'%';
            $query->where(function($x) use($like):void{$x->where('p.production_number','like',$like)->orWhere('p.id','like',$like);});
        }
        if (($status=trim((string)($filters['finance_status'] ?? ''))) !== '') {
            if ($status==='UNRECONCILED') $query->whereNull('r.id');
            else $query->where('r.finance_status',$status);
        }
        /** @var LengthAwarePaginator $p */
        $p=$query->paginate($perPage);
        $items=collect($p->items())->map(function(object $row):array{
            $liveFinal=round((float)$row->actual_input_value+(float)$row->labor_cost+(float)$row->overhead_cost,2);
            return [
                'id'=>(string)$row->id,'production_number'=>(string)$row->production_number,'production_date'=>$row->production_date,
                'status'=>(string)$row->status,'finished_at'=>$row->finished_at,
                'material_cost'=>(float)$row->actual_input_value,'labor_cost'=>(float)$row->labor_cost,'overhead_cost'=>(float)$row->overhead_cost,
                'waste_cost_memo'=>(float)$row->waste_cost,'final_cogs'=>$row->reconciliation_id?(float)$row->final_cogs:$liveFinal,
                'output_inventory_value'=>$row->reconciliation_id?(float)$row->output_inventory_value:(float)$row->actual_output_value,
                'selected_output_value'=>(float)$row->selected_output_value,'variance_value'=>$row->reconciliation_id?(float)$row->variance_value:round($liveFinal-(float)$row->actual_output_value,2),
                'output_qty_base'=>$row->reconciliation_id?(float)$row->output_qty_base:0,'unit_cogs'=>$row->reconciliation_id?(float)$row->unit_cogs:0,
                'yield_percent'=>(float)$row->yield_percent,'reconciliation_id'=>$row->reconciliation_id? (string)$row->reconciliation_id:null,
                'reconciliation_status'=>$row->reconciliation_status ?: 'UNRECONCILED','finance_status'=>$row->finance_status ?: 'NOT_POSTED',
                'reconciled_at'=>$row->reconciled_at,'posted_at'=>$row->posted_at,
            ];
        })->values()->all();
        $base=DB::table('wh_productions as p')->leftJoin('wh_i07_production_cogs_reconciliations as r','r.production_id','=','p.id')
            ->where('p.warehouse_id',$warehouseId)->whereIn('p.status',['completed','approved'])->whereBetween('p.production_date',[$from,$to]);
        return [
            'period'=>compact('from','to'),
            'summary'=>[
                'production_count'=>(clone $base)->count(),
                'reconciled_count'=>(clone $base)->whereNotNull('r.id')->count(),
                'posted_count'=>(clone $base)->where('r.finance_status','POSTED')->count(),
                'pending_count'=>(clone $base)->where(function($q):void{$q->whereNull('r.id')->orWhere('r.finance_status','<>','POSTED')->orWhereNull('r.finance_status');})->count(),
                'final_cogs'=>(float)(clone $base)->sum(DB::raw('COALESCE(r.final_cogs, p.actual_input_value + p.labor_cost + p.overhead_cost)')),
                'output_inventory_value'=>(float)(clone $base)->sum(DB::raw('COALESCE(r.output_inventory_value, p.actual_output_value)')),
                'variance_value'=>(float)(clone $base)->sum(DB::raw('COALESCE(r.variance_value, (p.actual_input_value + p.labor_cost + p.overhead_cost) - p.actual_output_value)')),
            ],
            'items'=>$items,
            'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'from'=>$p->firstItem(),'to'=>$p->lastItem()],
        ];
    }

    public function detail(string $warehouseId, string $productionId): array
    {
        $source=$this->source($warehouseId,$productionId,false);
        $row=DB::table('wh_i07_production_cogs_reconciliations')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->first();
        $events=$row?DB::table('wh_i07_production_cogs_reconciliation_events')->where('reconciliation_id',$row->id)->orderByDesc('occurred_at')->limit(30)->get()->map(fn($e)=>[
            'event_type'=>(string)$e->event_type,'payload'=>$this->json($e->payload),'occurred_at'=>$e->occurred_at,
        ])->values()->all():[];
        return ['source'=>$source,'reconciliation'=>$row?$this->row($row):null,'events'=>$events];
    }

    public function reconcile(string $warehouseId,string $productionId,string $userId): array
    {
        return DB::transaction(function() use($warehouseId,$productionId,$userId):array{
            DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first() ?: abort(404);
            $source=$this->source($warehouseId,$productionId,true);
            $existing=DB::table('wh_i07_production_cogs_reconciliations')->where('production_id',$productionId)->lockForUpdate()->first();
            if ($existing && (string)$existing->source_fingerprint === (string)$source['source_fingerprint']) {
                DB::table('wh_i07_production_cogs_reconciliations')->where('id',$existing->id)->update(['last_synced_at'=>now(),'updated_at'=>now()]);
                return $this->row(DB::table('wh_i07_production_cogs_reconciliations')->where('id',$existing->id)->first());
            }
            if ($existing && (string)$existing->finance_status==='POSTED') {
                DB::table('wh_i07_production_cogs_reconciliations')->where('id',$existing->id)->update(['reconciliation_status'=>'ADJUSTMENT_REQUIRED','last_synced_at'=>now(),'updated_at'=>now()]);
                $this->event((string)$existing->id,'source_changed_adjustment_required',['old_fingerprint'=>$existing->source_fingerprint,'new_fingerprint'=>$source['source_fingerprint']],$userId);
                throw ValidationException::withMessages(['reconciliation'=>['Source Production berubah setelah Finance POSTED. Reconciliation ditandai ADJUSTMENT_REQUIRED; jangan membuat jurnal baru otomatis. Lakukan investigasi/reversal Finance sebelum sinkron ulang.']]);
            }
            $id=$existing? (string)$existing->id:(string)Str::ulid();
            $data=[
                'warehouse_id'=>$warehouseId,'production_id'=>$productionId,'production_number'=>$source['production']['production_number'],'production_date'=>$source['production']['production_date'],
                'reconciliation_status'=>'RECONCILED','finance_status'=>'NOT_POSTED','material_cost'=>$source['totals']['material_cost'],'labor_cost'=>$source['totals']['labor_cost'],
                'overhead_cost'=>$source['totals']['overhead_cost'],'waste_cost_memo'=>$source['totals']['waste_cost_memo'],'final_cogs'=>$source['totals']['final_cogs'],
                'output_qty_base'=>$source['totals']['output_qty_base'],'output_inventory_value'=>$source['totals']['output_inventory_value'],'selected_output_value'=>$source['totals']['selected_output_value'],
                'variance_value'=>$source['totals']['variance_value'],'yield_percent'=>$source['totals']['yield_percent'],'unit_cogs'=>$source['totals']['unit_cogs'],
                'source_fingerprint'=>$source['source_fingerprint'],'source_snapshot'=>json_encode($source),'reconciled_by_user_id'=>$userId,'reconciled_at'=>now(),'last_synced_at'=>now(),'updated_at'=>now(),
            ];
            if ($existing) DB::table('wh_i07_production_cogs_reconciliations')->where('id',$id)->update($data);
            else DB::table('wh_i07_production_cogs_reconciliations')->insert($data+['id'=>$id,'created_at'=>now()]);
            $this->event($id,'reconciled',['final_cogs'=>$source['totals']['final_cogs'],'variance_value'=>$source['totals']['variance_value'],'contract_version'=>self::CONTRACT_VERSION],$userId);
            return $this->row(DB::table('wh_i07_production_cogs_reconciliations')->where('id',$id)->first());
        },5);
    }

    public function reconcileAndPost(string $warehouseId,string $productionId,string $userId): array
    {
        $recon=$this->reconcile($warehouseId,$productionId,$userId);
        return $this->post($warehouseId,$productionId,$userId,$recon);
    }

    public function post(string $warehouseId,string $productionId,string $userId,?array $recon=null): array
    {
        $recon ??= $this->reconcile($warehouseId,$productionId,$userId);
        if (($recon['finance_status'] ?? null)==='POSTED') return $recon;
        $source=$this->source($warehouseId,$productionId,false);
        if (($recon['source_fingerprint'] ?? '') !== $source['source_fingerprint']) {
            throw ValidationException::withMessages(['reconciliation'=>['Source berubah setelah reconcile. Jalankan Reconcile ulang sebelum Post Finance.']]);
        }
        $this->finance->dispatch('production_finish',$warehouseId,['id'=>$productionId],$userId);
        $finance=$this->financeSnapshot($warehouseId,$productionId,$source);
        if (! $finance['complete']) throw ValidationException::withMessages(['finance'=>['Finance Production belum lengkap: '.implode('; ',$finance['missing'])]]);
        if (abs((float)$finance['wip_net']-(float)$finance['expected_wip_net'])>0.01) throw ValidationException::withMessages(['finance'=>['Perubahan WIP Finance tidak sama dengan carry-forward reconciliation. Net posting: '.$finance['wip_net'].', expected: '.$finance['expected_wip_net']]]);
        if (abs((float)$finance['cost_pool_posted']-(float)$source['totals']['final_cogs'])>0.02) {
            throw ValidationException::withMessages(['finance'=>['Cost pool Finance tidak sama dengan Final COGS reconciliation.']]);
        }
        DB::transaction(function() use($productionId,$userId,$finance):void{
            $r=DB::table('wh_i07_production_cogs_reconciliations')->where('production_id',$productionId)->lockForUpdate()->first();
            if(!$r) throw ValidationException::withMessages(['reconciliation'=>['Reconciliation tidak ditemukan.']]);
            DB::table('wh_i07_production_cogs_reconciliations')->where('id',$r->id)->update([
                'finance_status'=>'POSTED','reconciliation_status'=>'POSTED','finance_snapshot'=>json_encode($finance),'posted_at'=>$r->posted_at ?: now(),'last_synced_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event((string)$r->id,'finance_posted',['posting_ids'=>$finance['posting_ids'],'cost_pool_posted'=>$finance['cost_pool_posted'],'wip_net'=>$finance['wip_net']],$userId);
        },5);
        return $this->row(DB::table('wh_i07_production_cogs_reconciliations')->where('production_id',$productionId)->first());
    }

    public function backfill(string $warehouseId,array $filters,string $userId): array
    {
        $from=$filters['from']??now('Asia/Jakarta')->startOfMonth()->toDateString();$to=$filters['to']??now('Asia/Jakarta')->toDateString();
        $ids=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->whereIn('status',['completed','approved'])->whereBetween('production_date',[$from,$to])->orderBy('production_date')->limit(100)->pluck('id');
        $ok=[];$failed=[];
        foreach($ids as $id){try{$ok[]=$this->reconcileAndPost($warehouseId,(string)$id,$userId);}catch(\Throwable $e){$failed[]=['production_id'=>(string)$id,'message'=>$e->getMessage()];}}
        return ['processed'=>$ids->count(),'posted'=>count($ok),'failed'=>count($failed),'failures'=>$failed];
    }

    private function source(string $warehouseId,string $productionId,bool $locking): array
    {
        $q=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId);if($locking)$q->lockForUpdate();$p=$q->first();if(!$p)abort(404);
        if(!in_array((string)$p->status,['completed','approved'],true)) throw ValidationException::withMessages(['status'=>['COGS hanya direconcile dari Production yang sudah Finished/Completed.']]);
        if (Schema::hasTable('wh_v7_production_material_opnames') && (int)($p->flow_version??0)>=7 && !DB::table('wh_v7_production_material_opnames')->where('production_id',$productionId)->where('status','finalized')->exists()) {
            throw ValidationException::withMessages(['opname'=>['Production Opname belum finalized.']]);
        }
        if(DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','draft')->exists()) throw ValidationException::withMessages(['results'=>['Masih ada Production Result draft.']]);
        if(!DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->exists()) throw ValidationException::withMessages(['results'=>['Tidak ada Production Result approved.']]);
        $materials=DB::table('wh_production_inputs as i')->join('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.base_uom_id')->where('i.production_id',$productionId)
            ->select('i.id','i.sku_id','s.sku_code','s.name as sku_name','u.code as base_uom_code','i.actual_qty_base','i.actual_material_cost')->orderBy('s.name')->get()->map(fn($x)=>[
                'input_id'=>(string)$x->id,'sku_id'=>(string)$x->sku_id,'sku_code'=>(string)$x->sku_code,'sku_name'=>(string)$x->sku_name,'base_uom_code'=>$x->base_uom_code,
                'actual_qty_base'=>(float)$x->actual_qty_base,'actual_material_cost'=>(float)$x->actual_material_cost,
            ])->values()->all();
        $components=DB::table('wh_production_cost_components')->where('production_id',$productionId)->orderBy('created_at')->get()->map(fn($x)=>[
            'id'=>(string)$x->id,'component_type'=>(string)$x->component_type,'description'=>(string)$x->description,'amount'=>(float)$x->amount,
        ])->values()->all();
        $labor=round((float)collect($components)->where('component_type','labor')->sum('amount'),2);if($labor<=0)$labor=round((float)($p->labor_cost??0),2);
        $over=round((float)collect($components)->whereIn('component_type',['overhead','utility','packaging','other'])->sum('amount'),2);if($over<=0)$over=round((float)($p->overhead_cost??0),2);
        $material=round((float)$p->actual_input_value,2);
        $carryIn=0.0; $carryOut=0.0; $warehouseIssue=0.0;
        if (Schema::hasTable('wh_v7_production_material_allocations')) {
            $carryIn=round((float)DB::table('wh_v7_production_material_allocations')->where('production_id',$productionId)->sum('carry_in_value'),2);
            $carryOut=round((float)DB::table('wh_v7_production_material_allocations')->where('production_id',$productionId)->sum('remaining_value'),2);
            $warehouseIssue=round((float)DB::table('wh_v7_production_material_allocations')->where('production_id',$productionId)->sum('warehouse_issue_value'),2);
        }
        $wastes=DB::table('wh_production_wastes')->where('production_id',$productionId)->get()->map(fn($x)=>['waste_type'=>(string)$x->waste_type,'qty_base'=>(float)$x->qty_base,'total_cost'=>(float)$x->total_cost,'reason'=>$x->reason])->values()->all();
        $wasteMemo=round((float)collect($wastes)->sum('total_cost'),2);
        $hasPrice=Schema::hasColumn('wh_v3_production_result_items','price_line_value_snapshot');
        $select=['r.id as result_id','r.result_number','ri.id as item_id','ri.sku_id','s.sku_code','s.name as sku_name','u.code as base_uom_code','ri.qty_base','ri.unit_cost_snapshot','ri.total_cost_snapshot'];
        if($hasPrice){$select[]='ri.price_band_snapshot';$select[]='ri.price_line_value_snapshot';}
        $outputs=DB::table('wh_v3_production_result_items as ri')->join('wh_v3_production_results as r','r.id','=','ri.result_id')->join('stk_skus as s','s.id','=','ri.sku_id')->leftJoin('stk_uoms as u','u.id','=','ri.uom_id')
            ->where('r.production_id',$productionId)->where('r.status','approved')->where('ri.status','approved')->select($select)->orderBy('r.result_number')->get()->map(function($x)use($hasPrice):array{return [
                'result_id'=>(string)$x->result_id,'result_number'=>(string)$x->result_number,'item_id'=>(string)$x->item_id,'sku_id'=>(string)$x->sku_id,'sku_code'=>(string)$x->sku_code,'sku_name'=>(string)$x->sku_name,
                'base_uom_code'=>$x->base_uom_code,'qty_base'=>(float)$x->qty_base,'inventory_unit_cost'=>(float)$x->unit_cost_snapshot,'inventory_value'=>(float)$x->total_cost_snapshot,
                'price_band'=>$hasPrice?($x->price_band_snapshot??null):null,'selected_output_value'=>$hasPrice?(float)($x->price_line_value_snapshot??0):0,
            ];})->values()->all();
        $outQty=round((float)collect($outputs)->sum('qty_base'),4);$outValue=round((float)collect($outputs)->sum('inventory_value'),2);$selected=round((float)collect($outputs)->sum('selected_output_value'),2);
        $estimated=round((float)DB::table('wh_production_outputs')->where('production_id',$productionId)->sum('estimated_qty_base'),4);$yield=$estimated>0?round($outQty/$estimated*100,4):0;
        $final=round($material+$labor+$over,2);$variance=round($final-$outValue,2);$unit=$outQty>0?round($final/$outQty,6):0;
        $totals=['material_cost'=>$material,'labor_cost'=>$labor,'overhead_cost'=>$over,'waste_cost_memo'=>$wasteMemo,'carry_in_wip_value'=>$carryIn,'warehouse_issue_value'=>$warehouseIssue,'carry_out_wip_value'=>$carryOut,'final_cogs'=>$final,'output_qty_base'=>$outQty,'output_inventory_value'=>$outValue,'selected_output_value'=>$selected,'variance_value'=>$variance,'yield_percent'=>$yield,'unit_cogs'=>$unit];
        $source=['contract_version'=>self::CONTRACT_VERSION,'production'=>['id'=>$productionId,'production_number'=>(string)$p->production_number,'production_date'=>$p->production_date,'status'=>(string)$p->status,'finished_at'=>$p->finished_at??$p->production_done_at??null],'totals'=>$totals,'materials'=>$materials,'conversion_costs'=>$components,'wastes'=>$wastes,'outputs'=>$outputs,'accounting_note'=>'Waste adalah breakdown dari material actual yang telah dikonsumsi; tidak ditambahkan lagi ke final COGS agar tidak double-count.'];
        $source['source_fingerprint']=hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return $source;
    }

    private function financeSnapshot(string $warehouseId,string $productionId,array $source): array
    {
        $missing=[];$ids=[];$materialPosted=0.0;$conversionPosted=0.0;$finishedPosted=0.0;$variancePosted=0.0;
        $mr=DB::table('wh_v3_production_material_requests')->where('production_id',$productionId)->where('warehouse_id',$warehouseId)->first();
        if($mr?->material_ledger_posting_id){$gp=$this->gpBySource('WHV4:LEDGER:'.$mr->material_ledger_posting_id.':PRODUCTION_MATERIAL_OUT');if($gp){$ids[]=(string)$gp->id;$materialPosted=$this->debitFor((string)$gp->id,'1230');}else$missing[]='PRODUCTION_MATERIAL_OUT';}
        $conversionExpected=round((float)$source['totals']['labor_cost']+(float)$source['totals']['overhead_cost'],2);
        if($conversionExpected>0){$gp=$this->gpBySource('WHV4:PRODUCTION:COST:'.$productionId);if($gp){$ids[]=(string)$gp->id;$conversionPosted=$this->debitFor((string)$gp->id,'1230');}else$missing[]='PRODUCTION_COST_ABSORPTION';}
        $results=DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->get(['id','ledger_posting_id']);
        foreach($results as $r){if(!$r->ledger_posting_id){$missing[]='RESULT_LEDGER:'.$r->id;continue;}$gp=$this->gpBySource('WHV4:LEDGER:'.$r->ledger_posting_id.':PRODUCTION_FINISHED_IN');if($gp){$ids[]=(string)$gp->id;$finishedPosted+=$this->debitFor((string)$gp->id,'1210');}else$missing[]='PRODUCTION_FINISHED_IN:'.$r->id;}
        $varianceExpected=round((float)$source['totals']['variance_value'],2);
        if(abs($varianceExpected)>0.009){$gp=$this->gpBySource('WHV4:PRODUCTION:VARIANCE:'.$productionId);if($gp){$ids[]=(string)$gp->id;$variancePosted=$this->signedAccount((string)$gp->id,'5200');}else$missing[]='PRODUCTION_VARIANCE';}
        $ids=array_values(array_unique($ids));$wipDebit=0.0;$wipCredit=0.0;
        if($ids){$lines=DB::table('wh_v4_finance_general_posting_lines')->whereIn('general_posting_id',$ids)->where('account_code','1230')->get();$wipDebit=(float)$lines->sum('debit');$wipCredit=(float)$lines->sum('credit');}
        $carryIn=round((float)($source['totals']['carry_in_wip_value']??0),2);
        $carryOut=round((float)($source['totals']['carry_out_wip_value']??0),2);
        $expectedWipNet=round($carryOut-$carryIn,2);
        $costPoolFromFinance=round($materialPosted+$conversionPosted+$carryIn-$carryOut,2);
        return ['complete'=>count($missing)===0,'missing'=>$missing,'posting_ids'=>$ids,'cost_pool_posted'=>$costPoolFromFinance,'material_wip_posted'=>round($materialPosted,2),'conversion_wip_posted'=>round($conversionPosted,2),'carry_in_wip_value'=>$carryIn,'carry_out_wip_value'=>$carryOut,'finished_inventory_posted'=>round($finishedPosted,2),'variance_posted'=>round($variancePosted,2),'wip_debit'=>round($wipDebit,2),'wip_credit'=>round($wipCredit,2),'wip_net'=>round($wipDebit-$wipCredit,2),'expected_wip_net'=>$expectedWipNet];
    }

    private function gpBySource(string $key): ?object {return DB::table('wh_v4_finance_general_postings')->where('source_key',$key)->where('status','POSTED')->first();}
    private function debitFor(string $postingId,string $account):float{return round((float)DB::table('wh_v4_finance_general_posting_lines')->where('general_posting_id',$postingId)->where('account_code',$account)->sum('debit'),2);}
    private function signedAccount(string $postingId,string $account):float{$x=DB::table('wh_v4_finance_general_posting_lines')->where('general_posting_id',$postingId)->where('account_code',$account)->first();return $x?round((float)$x->debit-(float)$x->credit,2):0;}
    private function row(object $r):array{return ['id'=>(string)$r->id,'production_id'=>(string)$r->production_id,'production_number'=>(string)$r->production_number,'production_date'=>$r->production_date,'reconciliation_status'=>(string)$r->reconciliation_status,'finance_status'=>(string)$r->finance_status,'material_cost'=>(float)$r->material_cost,'labor_cost'=>(float)$r->labor_cost,'overhead_cost'=>(float)$r->overhead_cost,'waste_cost_memo'=>(float)$r->waste_cost_memo,'final_cogs'=>(float)$r->final_cogs,'output_qty_base'=>(float)$r->output_qty_base,'output_inventory_value'=>(float)$r->output_inventory_value,'selected_output_value'=>(float)$r->selected_output_value,'variance_value'=>(float)$r->variance_value,'yield_percent'=>(float)$r->yield_percent,'unit_cogs'=>(float)$r->unit_cogs,'source_fingerprint'=>(string)$r->source_fingerprint,'finance_snapshot'=>$this->json($r->finance_snapshot),'reconciled_at'=>$r->reconciled_at,'posted_at'=>$r->posted_at,'last_synced_at'=>$r->last_synced_at];}
    private function event(string $reconciliationId,string $type,array $payload,?string $userId):void{DB::table('wh_i07_production_cogs_reconciliation_events')->insert(['id'=>(string)Str::ulid(),'reconciliation_id'=>$reconciliationId,'event_type'=>$type,'payload'=>json_encode($payload),'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function json(mixed $v):array{if(is_array($v))return$v;if(is_object($v))return(array)$v;$d=json_decode((string)$v,true);return is_array($d)?$d:[];}
}
