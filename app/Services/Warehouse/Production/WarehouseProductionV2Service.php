<?php
namespace App\Services\Warehouse\Production;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseProductionV2Service
{
    public function dashboard(string $warehouseId, array $filters = []): array
    {
        $from=$filters['from']??now()->startOfMonth()->toDateString(); $to=$filters['to']??now()->toDateString();
        $base=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->whereBetween('production_date',[$from,$to]);
        $rows=(clone $base)->selectRaw('status, count(*) total, sum(actual_input_value) material_cost, sum(labor_cost) labor_cost, sum(overhead_cost) overhead_cost, sum(waste_cost) waste_cost, sum(total_production_cost) total_cost, sum(actual_output_value) output_value')->groupBy('status')->get();
        return ['period'=>compact('from','to'),'summary'=>[
            'production_count'=>(clone $base)->count(),
            'open_wip'=>(float)(clone $base)->whereIn('status',['prepare','on_progress','pending_approval'])->sum('wip_value'),
            'material_cost'=>(float)(clone $base)->sum('actual_input_value'),
            'conversion_cost'=>(float)(clone $base)->sum(DB::raw('labor_cost + overhead_cost')),
            'waste_cost'=>(float)(clone $base)->sum('waste_cost'),
            'average_yield'=>(float)(clone $base)->where('yield_percent','>',0)->avg('yield_percent'),
        ],'by_status'=>$rows];
    }

    public function listBoms(string $warehouseId, array $filters): array
    {
        $q=DB::table('wh_bom_headers as h')->join('stk_skus as s','s.id','=','h.output_sku_id')->leftJoin('stk_uoms as u','u.id','=','h.output_uom_id')->where(fn($x)=>$x->whereNull('h.warehouse_id')->orWhere('h.warehouse_id',$warehouseId))->select('h.*','s.sku_code','s.name as output_name','u.code as output_uom_code')->orderByDesc('h.updated_at');
        if($filters['q']??null){$term='%'.trim($filters['q']).'%';$q->where(fn($x)=>$x->where('h.bom_code','like',$term)->orWhere('h.name','like',$term)->orWhere('s.name','like',$term));}
        return $q->get()->map(fn($r)=>$this->bom((string)$r->id))->all();
    }

    public function saveBom(?string $id,string $warehouseId,array $p,string $userId): array
    {
        return DB::transaction(function()use($id,$warehouseId,$p,$userId){
            if(empty($p['items'])) throw ValidationException::withMessages(['items'=>['Minimal satu bahan BOM diperlukan.']]);
            $now=now(); $bomId=$id?: (string)Str::ulid();
            $data=['bom_code'=>trim($p['bom_code']??('BOM-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)))),'name'=>trim($p['name']),'warehouse_id'=>($p['scope']??'warehouse')==='global'?null:$warehouseId,'output_sku_id'=>$p['output_sku_id'],'output_uom_id'=>$p['output_uom_id'],'standard_output_qty'=>$p['standard_output_qty'],'version'=>$p['version']??1,'is_active'=>(bool)($p['is_active']??true),'effective_from'=>$p['effective_from']??null,'effective_to'=>$p['effective_to']??null,'notes'=>$p['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>$now];
            if($id){DB::table('wh_bom_headers')->where('id',$id)->update($data); DB::table('wh_bom_items')->where('bom_header_id',$id)->delete();}
            else {DB::table('wh_bom_headers')->insert($data+['id'=>$bomId,'created_by_user_id'=>$userId,'created_at'=>$now]);}
            foreach($p['items'] as $i) DB::table('wh_bom_items')->insert(['id'=>(string)Str::ulid(),'bom_header_id'=>$bomId,'input_sku_id'=>$i['input_sku_id'],'input_uom_id'=>$i['input_uom_id'],'qty_per_output'=>$i['qty_per_output'],'conversion_factor_snapshot'=>$i['conversion_factor_snapshot']??1,'expected_waste_percent'=>$i['expected_waste_percent']??0,'is_optional'=>(bool)($i['is_optional']??false),'notes'=>$i['notes']??null,'created_at'=>$now,'updated_at'=>$now]);
            return $this->bom($bomId);
        },5);
    }

    public function bom(string $id): array
    {
        $h=DB::table('wh_bom_headers as h')->join('stk_skus as s','s.id','=','h.output_sku_id')->leftJoin('stk_uoms as u','u.id','=','h.output_uom_id')->where('h.id',$id)->select('h.*','s.sku_code','s.name as output_name','u.code as output_uom_code')->first(); if(!$h) abort(404);
        $items=DB::table('wh_bom_items as i')->join('stk_skus as s','s.id','=','i.input_sku_id')->leftJoin('stk_uoms as u','u.id','=','i.input_uom_id')->where('i.bom_header_id',$id)->select('i.*','s.sku_code','s.name as input_name','u.code as input_uom_code')->get();
        return ['id'=>$h->id,'bom_code'=>$h->bom_code,'name'=>$h->name,'warehouse_id'=>$h->warehouse_id,'output_sku_id'=>$h->output_sku_id,'sku_code'=>$h->sku_code,'output_name'=>$h->output_name,'output_uom_id'=>$h->output_uom_id,'output_uom_code'=>$h->output_uom_code,'standard_output_qty'=>(float)$h->standard_output_qty,'version'=>(int)$h->version,'is_active'=>(bool)$h->is_active,'effective_from'=>$h->effective_from,'effective_to'=>$h->effective_to,'notes'=>$h->notes,'items'=>$items];
    }

    public function recordCost(string $productionId,string $warehouseId,array $p,string $userId): array
    {
        return DB::transaction(function()use($productionId,$warehouseId,$p,$userId){$prod=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->lockForUpdate()->find($productionId);if(!$prod)abort(404);if(in_array($prod->status,['approved','cancelled'],true))throw ValidationException::withMessages(['status'=>['Biaya tidak dapat diubah setelah produksi final.']]);
            DB::table('wh_production_cost_components')->insert(['id'=>(string)Str::ulid(),'production_id'=>$productionId,'component_type'=>$p['component_type'],'description'=>$p['description'],'amount'=>$p['amount'],'allocation_method'=>$p['allocation_method']??'output_qty','metadata'=>json_encode($p['metadata']??[]),'recorded_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);$this->recalculate($productionId);return $this->costing($productionId);},5);
    }

    public function recordWaste(string $productionId,string $warehouseId,array $p,string $userId): array
    {
        return DB::transaction(function()use($productionId,$warehouseId,$p,$userId){$prod=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->lockForUpdate()->find($productionId);if(!$prod)abort(404);if($prod->status==='approved')throw ValidationException::withMessages(['status'=>['Waste harus dicatat sebelum approval output.']]);$total=round((float)$p['qty_base']*(float)($p['unit_cost_snapshot']??0),2);
            DB::table('wh_production_wastes')->insert(['id'=>(string)Str::ulid(),'production_id'=>$productionId,'sku_id'=>$p['sku_id']??null,'waste_type'=>$p['waste_type'],'qty_base'=>$p['qty_base'],'unit_cost_snapshot'=>$p['unit_cost_snapshot']??0,'total_cost'=>$total,'disposition'=>$p['disposition']??'discard','reason'=>$p['reason'],'recorded_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);$this->recalculate($productionId);return $this->costing($productionId);},5);
    }

    public function costing(string $productionId): array
    {
        $p=DB::table('wh_productions')->find($productionId);if(!$p)abort(404);return ['production'=>$p,'cost_components'=>DB::table('wh_production_cost_components')->where('production_id',$productionId)->orderBy('created_at')->get(),'wastes'=>DB::table('wh_production_wastes')->where('production_id',$productionId)->orderBy('created_at')->get(),'outputs'=>DB::table('wh_production_outputs')->where('production_id',$productionId)->get()];
    }

    public function reconcile(string $productionId,string $warehouseId,string $userId): array
    {
        return DB::transaction(function()use($productionId,$warehouseId,$userId){$p=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->lockForUpdate()->find($productionId);if(!$p)abort(404);$this->recalculate($productionId);$after=DB::table('wh_productions')->find($productionId);DB::table('wh_production_events')->insert(['id'=>(string)Str::ulid(),'production_id'=>$productionId,'event_type'=>'cost_reconciled','payload'=>json_encode(['total_production_cost'=>$after->total_production_cost,'yield_percent'=>$after->yield_percent]),'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return $this->costing($productionId);},5);
    }

    private function recalculate(string $id): void
    {
        $p=DB::table('wh_productions')->find($id); $labor=(float)DB::table('wh_production_cost_components')->where('production_id',$id)->where('component_type','labor')->sum('amount');$over=(float)DB::table('wh_production_cost_components')->where('production_id',$id)->whereIn('component_type',['overhead','utility','packaging','other'])->sum('amount');$waste=(float)DB::table('wh_production_wastes')->where('production_id',$id)->sum('total_cost');$material=(float)$p->actual_input_value;$total=round($material+$labor+$over+$waste,2);$estimated=(float)DB::table('wh_production_outputs')->where('production_id',$id)->sum('estimated_qty_base');$actual=(float)DB::table('wh_production_outputs')->where('production_id',$id)->sum('actual_qty_base');$yield=$estimated>0?round(($actual/$estimated)*100,4):0;$wip=in_array($p->status,['prepare','on_progress','pending_approval'],true)?$total:0;
        DB::table('wh_productions')->where('id',$id)->update(['labor_cost'=>$labor,'overhead_cost'=>$over,'waste_cost'=>$waste,'wip_value'=>$wip,'total_production_cost'=>$total,'yield_percent'=>$yield,'updated_at'=>now()]);
        $outs=DB::table('wh_production_outputs')->where('production_id',$id)->get();$sum=(float)$outs->sum('actual_qty_base');foreach($outs as $o){$share=$sum>0?(float)$o->actual_qty_base/$sum:0;$allocated=round($total*$share,2);$unit=(float)$o->actual_qty_base>0?$allocated/(float)$o->actual_qty_base:0;DB::table('wh_production_outputs')->where('id',$o->id)->update(['allocated_cost'=>$allocated,'actual_unit_cost'=>$unit,'updated_at'=>now()]);}
    }
}
