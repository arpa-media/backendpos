<?php

namespace App\Services\Warehouse\SalesTransferV3;

use App\Services\Warehouse\Pricing\WarehouseSalesPriceResolverI06;
use App\Services\Warehouse\Support\WarehouseTransactionUomService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseSalesTransferV3Service
{
    public function __construct(
        private readonly WarehouseTransactionUomService $transactionUoms,
        private readonly WarehouseSalesPriceResolverI06 $salesPricing,
    ) {}

    public function options(string $warehouseId): array
    {
        $customers = DB::table('wh_customers')->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id','code','name','customer_type','credit_term_days'])->map(fn ($r) => [
                'id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'customer_type'=>(string)$r->customer_type,'credit_term_days'=>(int)$r->credit_term_days,
            ])->values()->all();
        $currentWarehouse = DB::table('outlets')->where('id',$warehouseId)->where('is_active',true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->first(['id','code','name','type']);
        if (! $currentWarehouse) {
            throw ValidationException::withMessages(['warehouse_id'=>['Warehouse Origin tidak terhubung ke Master Outlet Warehouse aktif. Pilih Warehouse yang valid.']]);
        }
        $warehouses = DB::table('outlets')->where('is_active', true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->orderBy('name')->get(['id','code','name','type'])->map(fn ($r) => ['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'type'=>(string)$r->type,'is_current'=>(string)$r->id===$warehouseId,'source_master'=>'outlets'])->values()->all();
        $current_warehouse=['id'=>(string)$currentWarehouse->id,'code'=>(string)$currentWarehouse->code,'name'=>(string)$currentWarehouse->name,'type'=>(string)$currentWarehouse->type,'source_master'=>'outlets'];
        $skus = DB::table('stk_skus')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id','sku_code','name'])
            ->map(function ($sku): array {
                $catalog = $this->transactionUoms->catalog((string) $sku->id);
                return [
                    'id'=>(string)$sku->id,'sku_code'=>(string)$sku->sku_code,'name'=>(string)$sku->name,
                    'base_uom_id'=>$catalog['base_uom']['id'],'base_uom'=>$catalog['base_uom'],'purchase_uom'=>$catalog['purchase_uom'],
                    'default_request_uom_id'=>$catalog['default_request_uom_id'],'uoms'=>$catalog['uoms'],
                ];
            })->values()->all();
        return compact('customers','current_warehouse','warehouses','skus');
    }

    public function listSalesOrders(string $warehouseId,array $filters):array
    {
        $this->syncAll('sales_order',$warehouseId);
        $q=DB::table('wh_v3_sales_orders as o')->join('wh_customers as c','c.id','=','o.customer_id')->leftJoin('users as creator','creator.id','=','o.created_by_user_id')
            ->where('o.warehouse_id',$warehouseId)->where('o.sales_channel','legacy')->select('o.*','c.code as customer_code','c.name as customer_name','creator.name as requester_name')
            ->selectSub(fn($s)=>$s->from('wh_v3_sales_order_items as i')->whereColumn('i.sales_order_id','o.id')->selectRaw('COUNT(*)'),'line_count')
            ->selectSub(fn($s)=>$s->from('wh_v3_sales_order_items as i')->whereColumn('i.sales_order_id','o.id')->selectRaw('COALESCE(SUM(i.approved_qty_base),0)'),'approved_qty_base');
        $this->applyListFilters($q,$filters,'o.sales_order_number','c.name');
        return $this->paginated($q->orderByDesc('o.created_at')->paginate((int)($filters['per_page']??30)), fn($r)=>[
            'id'=>(string)$r->id,'number'=>(string)$r->sales_order_number,'sales_order_number'=>(string)$r->sales_order_number,'date'=>$r->order_date,'needed_date'=>$r->needed_date,'status'=>(string)$r->status,
            'destination'=>['id'=>(string)$r->customer_id,'code'=>$r->customer_code,'name'=>$r->customer_name],'requester_name'=>$r->requester_name,'line_count'=>(int)$r->line_count,'approved_qty_base'=>(float)$r->approved_qty_base,'grand_total'=>(float)($r->grand_total??0),'approved_grand_total'=>(float)($r->approved_grand_total??0),'created_at'=>$r->created_at,
        ]);
    }

    public function listTransfers(string $warehouseId,array $filters):array
    {
        $this->syncAll('transfer_stock',$warehouseId);
        $q=DB::table('wh_v3_transfer_orders as o')->join('outlets as d','d.id','=','o.destination_warehouse_id')->leftJoin('users as creator','creator.id','=','o.created_by_user_id')
            ->where('o.origin_warehouse_id',$warehouseId)->select('o.*','d.code as destination_code','d.name as destination_name','creator.name as requester_name')
            ->selectSub(fn($s)=>$s->from('wh_v3_transfer_order_items as i')->whereColumn('i.transfer_order_id','o.id')->selectRaw('COUNT(*)'),'line_count')
            ->selectSub(fn($s)=>$s->from('wh_v3_transfer_order_items as i')->whereColumn('i.transfer_order_id','o.id')->selectRaw('COALESCE(SUM(i.approved_qty_base),0)'),'approved_qty_base');
        $this->applyListFilters($q,$filters,'o.transfer_number','d.name');
        return $this->paginated($q->orderByDesc('o.created_at')->paginate((int)($filters['per_page']??30)), fn($r)=>[
            'id'=>(string)$r->id,'number'=>(string)$r->transfer_number,'transfer_number'=>(string)$r->transfer_number,'date'=>$r->transfer_date,'needed_date'=>$r->needed_date,'status'=>(string)$r->status,
            'destination'=>['id'=>(string)$r->destination_warehouse_id,'code'=>$r->destination_code,'name'=>$r->destination_name],'requester_name'=>$r->requester_name,'line_count'=>(int)$r->line_count,'approved_qty_base'=>(float)$r->approved_qty_base,'created_at'=>$r->created_at,
        ]);
    }

    public function saveSalesOrder(string $warehouseId,array $payload,string $userId,?string $id=null):array
    {
        $id=DB::transaction(function()use($warehouseId,$payload,$userId,$id):string{
            $existing=$id?DB::table('wh_v3_sales_orders')->where('warehouse_id',$warehouseId)->where('sales_channel','legacy')->where('id',$id)->lockForUpdate()->first():null;
            if($id&&!$existing)abort(404);
            if($existing && $existing->status!=='draft')throw ValidationException::withMessages(['status'=>['Hanya Sales Order draft yang dapat diedit.']]);
            $customer=DB::table('wh_customers')->where('id',$payload['customer_id'])->where('is_active',true)->whereNull('deleted_at')->first();
            if(!$customer)throw ValidationException::withMessages(['customer_id'=>['Customer aktif tidak ditemukan.']]);
            $docId=$existing?->id ?: (string)Str::ulid();$now=now();
            $data=['warehouse_id'=>$warehouseId,'customer_id'=>$customer->id,'sales_channel'=>'legacy','order_date'=>$payload['order_date'],'needed_date'=>$payload['needed_date']??null,'status'=>'draft','notes'=>$payload['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>$now,'metadata'=>json_encode(['flow_version'=>3,'price_visible'=>true,'pricing_source'=>WarehouseSalesPriceResolverI06::SOURCE])];
            if($existing)DB::table('wh_v3_sales_orders')->where('id',$docId)->update($data);else DB::table('wh_v3_sales_orders')->insert($data+['id'=>$docId,'sales_order_number'=>$this->number('SO'),'created_by_user_id'=>$userId,'created_at'=>$now]);
            $totals=$this->replaceItems('sales_order',$docId,$payload['items'],$warehouseId,(string)$customer->id,(string)$payload['order_date']);
            DB::table('wh_v3_sales_orders')->where('id',$docId)->update(['subtotal'=>$totals['subtotal'],'grand_total'=>$totals['grand_total'],'updated_at'=>now()]);
            $this->event('sales_order',$docId,$existing?'draft_updated':'draft_created',$existing?'draft':null,'draft',$existing?'Sales Order draft diperbarui.':'Sales Order draft dibuat.',$userId);
            return $docId;
        },5);
        return $this->detailSalesOrder($warehouseId,$id);
    }

    public function saveTransfer(string $warehouseId,array $payload,string $userId,?string $id=null):array
    {
        $id=DB::transaction(function()use($warehouseId,$payload,$userId,$id):string{
            $existing=$id?DB::table('wh_v3_transfer_orders')->where('origin_warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first():null;
            if($id&&!$existing)abort(404);
            if($existing && $existing->status!=='draft')throw ValidationException::withMessages(['status'=>['Hanya Transfer Stock draft yang dapat diedit.']]);
            $origin=DB::table('outlets')->where('id',$warehouseId)->where('is_active',true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->first();
            if(!$origin)throw ValidationException::withMessages(['origin_warehouse_id'=>['Warehouse Origin tidak ditemukan pada existing Master Warehouse/Outlet.']]);
            if((string)$payload['destination_warehouse_id']===$warehouseId)throw ValidationException::withMessages(['destination_warehouse_id'=>['Warehouse tujuan harus berbeda dari Warehouse origin.']]);
            $dest=DB::table('outlets')->where('id',$payload['destination_warehouse_id'])->where('is_active',true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->first();
            if(!$dest)throw ValidationException::withMessages(['destination_warehouse_id'=>['Warehouse tujuan aktif tidak ditemukan.']]);
            $docId=$existing?->id ?: (string)Str::ulid();$now=now();
            $data=['origin_warehouse_id'=>$warehouseId,'destination_warehouse_id'=>$dest->id,'transfer_date'=>$payload['transfer_date'],'needed_date'=>$payload['needed_date']??null,'status'=>'draft','notes'=>$payload['notes']??null,'updated_by_user_id'=>$userId,'updated_at'=>$now,'metadata'=>json_encode(['flow_version'=>3,'internal_transfer'=>true,'revenue_invoice'=>false])];
            if($existing)DB::table('wh_v3_transfer_orders')->where('id',$docId)->update($data);else DB::table('wh_v3_transfer_orders')->insert($data+['id'=>$docId,'transfer_number'=>$this->number('TRF'),'created_by_user_id'=>$userId,'created_at'=>$now]);
            $this->replaceItems('transfer_stock',$docId,$payload['items']);
            $this->event('transfer_stock',$docId,$existing?'draft_updated':'draft_created',$existing?'draft':null,'draft',$existing?'Transfer Stock draft diperbarui.':'Transfer Stock draft dibuat.',$userId);
            return $docId;
        },5);
        return $this->detailTransfer($warehouseId,$id);
    }

    public function submit(string $type,string $warehouseId,string $id,string $userId):array
    {
        $table=$type==='sales_order'?'wh_v3_sales_orders':'wh_v3_transfer_orders';$whCol=$type==='sales_order'?'warehouse_id':'origin_warehouse_id';
        DB::transaction(function()use($type,$table,$whCol,$warehouseId,$id,$userId):void{
            $row=DB::table($table)->where($whCol,$warehouseId)->when($type==='sales_order',fn($q)=>$q->where('sales_channel','legacy'))->where('id',$id)->lockForUpdate()->first();if(!$row)abort(404);
            if($row->status==='submitted'||$row->status==='approved')return;
            if($row->status!=='draft')throw ValidationException::withMessages(['status'=>['Dokumen tidak dapat disubmit pada status saat ini.']]);
            $itemTable=$type==='sales_order'?'wh_v3_sales_order_items':'wh_v3_transfer_order_items';$fk=$type==='sales_order'?'sales_order_id':'transfer_order_id';
            if(!DB::table($itemTable)->where($fk,$id)->where('requested_qty_base','>',0)->exists())throw ValidationException::withMessages(['items'=>['Minimal satu item positif diperlukan.']]);
            if($type==='sales_order')$this->assertStrictPricingSnapshots($id);
            DB::table($table)->where('id',$id)->update(['status'=>'submitted','submitted_by_user_id'=>$userId,'submitted_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            DB::table($itemTable)->where($fk,$id)->update(['status'=>'submitted','updated_at'=>now()]);
            $this->event($type,$id,'submitted','draft','submitted','Dokumen disubmit untuk approval.',$userId);
        },5);
        return $type==='sales_order'?$this->detailSalesOrder($warehouseId,$id):$this->detailTransfer($warehouseId,$id);
    }

    public function approve(string $type,string $warehouseId,string $id,array $payload,string $userId):array
    {
        DB::transaction(function()use($type,$warehouseId,$id,$payload,$userId):void{
            [$table,$itemTable,$fk,$whCol,$destType,$numberCol]=$type==='sales_order'
                ?['wh_v3_sales_orders','wh_v3_sales_order_items','sales_order_id','warehouse_id','customer','sales_order_number']
                :['wh_v3_transfer_orders','wh_v3_transfer_order_items','transfer_order_id','origin_warehouse_id','warehouse','transfer_number'];
            $row=DB::table($table)->where($whCol,$warehouseId)->when($type==='sales_order',fn($q)=>$q->where('sales_channel','legacy'))->where('id',$id)->lockForUpdate()->first();if(!$row)abort(404);
            if($row->status==='approved'&&$row->prepare_request_id)return;
            if($row->status!=='submitted')throw ValidationException::withMessages(['status'=>['Dokumen harus Submitted sebelum Approval.']]);
            if($type==='sales_order')$this->assertStrictPricingSnapshots($id);
            $inputs=collect($payload['items']??[])->keyBy(fn($x)=>(string)($x['item_id']??''));$items=DB::table($itemTable)->where($fk,$id)->lockForUpdate()->get();$positive=0;
            foreach($items as $item){$input=$inputs->get((string)$item->id);if(!$input)throw ValidationException::withMessages(['items'=>['Seluruh item wajib memiliki Approved Qty.']]);$qty=round((float)($input['approved_qty_uom']??0),4);$requested=round((float)$item->requested_qty_uom,4);if($qty<0||$qty>$requested+0.0001)throw ValidationException::withMessages(['items'=>["Approved Qty item {$item->id} harus antara 0 dan Requested Qty."]]);$base=round($qty*(float)$item->conversion_factor_snapshot,4);if($base>0)$positive++;$update=['approved_qty_uom'=>$qty,'approved_qty_base'=>$base,'status'=>$base>0?'approved':'rejected','notes'=>$input['notes']??$item->notes,'updated_at'=>now()];if($type==='sales_order')$update['approved_line_total']=round($qty*(float)$item->unit_price,2);DB::table($itemTable)->where('id',$item->id)->update($update);}
            if($positive===0)throw ValidationException::withMessages(['items'=>['Minimal satu item harus memiliki Approved Qty lebih besar dari nol.']]);
            $destinationId=$type==='sales_order'?(string)$row->customer_id:(string)$row->destination_warehouse_id;
            $prepareId=$this->createPrepare($type,$row,$items,$itemTable,$fk,$warehouseId,$destType,$destinationId,$numberCol,$userId);
            $headerUpdate=['status'=>'approved','prepare_request_id'=>$prepareId,'approved_by_user_id'=>$userId,'approved_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()];
            if($type==='sales_order'){
                $approvedTotal=round((float)DB::table('wh_v3_sales_order_items')->where('sales_order_id',$id)->sum('approved_line_total'),2);
                $headerUpdate += ['approved_subtotal'=>$approvedTotal,'approved_grand_total'=>$approvedTotal];
            }
            DB::table($table)->where('id',$id)->update($headerUpdate);
            $this->event($type,$id,'approved','submitted','approved','Dokumen approved dan langsung masuk Checker Prepare Logistics.',$userId,['prepare_request_id'=>$prepareId]);
        },5);
        return $type==='sales_order'?$this->detailSalesOrder($warehouseId,$id):$this->detailTransfer($warehouseId,$id);
    }

    public function detailSalesOrder(string $warehouseId,string $id):array
    {
        $this->syncOne('sales_order',$id);
        $r=DB::table('wh_v3_sales_orders as o')->join('wh_customers as c','c.id','=','o.customer_id')->leftJoin('users as creator','creator.id','=','o.created_by_user_id')->leftJoin('users as approver','approver.id','=','o.approved_by_user_id')
            ->where('o.warehouse_id',$warehouseId)->where('o.sales_channel','legacy')->where('o.id',$id)->first(['o.*','c.code as destination_code','c.name as destination_name','creator.name as requester_name','approver.name as approver_name']);
        if(!$r)abort(404);return $this->detailRow('sales_order',$r);
    }

    public function detailTransfer(string $warehouseId,string $id):array
    {
        $this->syncOne('transfer_stock',$id);
        $r=DB::table('wh_v3_transfer_orders as o')->join('outlets as d','d.id','=','o.destination_warehouse_id')->leftJoin('users as creator','creator.id','=','o.created_by_user_id')->leftJoin('users as approver','approver.id','=','o.approved_by_user_id')
            ->where('o.origin_warehouse_id',$warehouseId)->where('o.id',$id)->first(['o.*','d.code as destination_code','d.name as destination_name','creator.name as requester_name','approver.name as approver_name']);
        if(!$r)abort(404);return $this->detailRow('transfer_stock',$r);
    }

    private function detailRow(string $type,object $r):array
    {
        [$itemTable,$fk,$number,$date,$destinationId]=$type==='sales_order'
            ?['wh_v3_sales_order_items','sales_order_id',$r->sales_order_number,$r->order_date,$r->customer_id]
            :['wh_v3_transfer_order_items','transfer_order_id',$r->transfer_number,$r->transfer_date,$r->destination_warehouse_id];
        $items=DB::table("{$itemTable} as i")
            ->join('stk_skus as s','s.id','=','i.sku_id')
            ->leftJoin('stk_uoms as u','u.id','=','i.uom_id')
            ->leftJoin('stk_uoms as base','base.id','=','s.base_uom_id')
            ->where("i.{$fk}",$r->id)->orderBy('s.name')
            ->get(['i.*','s.sku_code','s.name as item_name','s.base_uom_id','u.code as live_uom_code','u.name as live_uom_name','base.code as live_base_uom_code','base.name as live_base_uom_name'])
            ->map(fn($i)=>[
                'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,'uom_id'=>(string)$i->uom_id,
                'uom_code'=>(string)($i->uom_code_snapshot ?? $i->live_uom_code ?? 'UNIT'),'uom_name'=>(string)($i->uom_name_snapshot ?? $i->live_uom_name ?? 'Unit'),
                'base_uom_id'=>(string)($i->base_uom_id_snapshot ?? $i->base_uom_id ?? ''),'base_uom_code'=>(string)($i->base_uom_code_snapshot ?? $i->live_base_uom_code ?? 'UNIT'),'base_uom_name'=>(string)($i->base_uom_name_snapshot ?? $i->live_base_uom_name ?? 'Unit'),
                'conversion_factor'=>(float)$i->conversion_factor_snapshot,'requested_qty_uom'=>(float)$i->requested_qty_uom,'requested_qty_base'=>(float)$i->requested_qty_base,
                'approved_qty_uom'=>(float)$i->approved_qty_uom,'approved_qty_base'=>(float)$i->approved_qty_base,
                ...($type==='sales_order'?['unit_price'=>(float)($i->unit_price??0),'line_total'=>(float)($i->line_total??0),'approved_line_total'=>(float)($i->approved_line_total??0),'price_source'=>(string)($i->price_source??''),'price_policy_id_snapshot'=>$i->price_policy_id_snapshot??null,'price_uom_code_snapshot'=>$i->price_uom_code_snapshot??null,'price_master_snapshot'=>$i->price_master_snapshot!==null?(float)$i->price_master_snapshot:null,'price_transaction_unit_snapshot'=>$i->price_transaction_unit_snapshot!==null?(float)$i->price_transaction_unit_snapshot:null]:[]),
                'status'=>(string)$i->status,'notes'=>$i->notes,
            ])->values()->all();
        $logistics=$this->logisticsSnapshot($r->prepare_request_id);
        return ['id'=>(string)$r->id,'type'=>$type,'number'=>(string)$number,'date'=>$date,'needed_date'=>$r->needed_date,'status'=>(string)$r->status,'notes'=>$r->notes,
            'destination'=>['id'=>(string)$destinationId,'code'=>$r->destination_code,'name'=>$r->destination_name],
            'requester'=>['name'=>$r->requester_name,'created_at'=>$r->created_at],'approver'=>$r->approved_by_user_id?['name'=>$r->approver_name,'approved_at'=>$r->approved_at]:null,
            ...($type==='sales_order'?['subtotal'=>(float)($r->subtotal??0),'grand_total'=>(float)($r->grand_total??0),'approved_grand_total'=>(float)($r->approved_grand_total??0)]:[]),
            'prepare_request_id'=>$r->prepare_request_id,'items'=>$items,'logistics'=>$logistics,'timeline'=>$this->timeline($type,(string)$r->id,$r->prepare_request_id)];
    }

    private function replaceItems(string $type,string $docId,array $items,?string $warehouseId=null,?string $targetId=null,?string $businessDate=null):array
    {
        [$table,$fk]=$type==='sales_order'?['wh_v3_sales_order_items','sales_order_id']:['wh_v3_transfer_order_items','transfer_order_id'];
        DB::table($table)->where($fk,$docId)->delete();$seen=[];
        foreach($items as $index=>$line){
            $sku=DB::table('stk_skus')->where('id',$line['sku_id'])->where('is_active',true)->whereNull('deleted_at')->first();
            if(!$sku)throw ValidationException::withMessages(["items.{$index}.sku_id"=>['SKU aktif tidak ditemukan.']]);
            if(in_array((string)$sku->id,$seen,true))throw ValidationException::withMessages(['items'=>['SKU tidak boleh duplikat dalam satu dokumen.']]);
            $seen[]=(string)$sku->id;
            $resolved=$this->transactionUoms->resolve((string)$sku->id,(string)$line['uom_id'],true);
            $factor=(float)$resolved['conversion_factor'];$qty=round((float)$line['requested_qty_uom'],4);
            if($qty<=0)throw ValidationException::withMessages(["items.{$index}.requested_qty_uom"=>['Qty harus lebih besar dari nol.']]);
            $pricing=null;$unitPrice=0.0;$lineTotal=0.0;
            if($type==='sales_order'){
                if(!$warehouseId||!$targetId||!$businessDate)throw ValidationException::withMessages(['warehouse_price'=>['Konteks harga Sales Order tidak lengkap.']]);
                $pricing=$this->salesPricing->resolveForTransaction($warehouseId,'customer',$targetId,(string)$sku->id,(string)$resolved['uom_id'],$qty,$businessDate,true);
                $unitPrice=round((float)$pricing['transaction_unit_price'],6);$lineTotal=round($qty*$unitPrice,2);
            }
            DB::table($table)->insert([
                'id'=>(string)Str::ulid(),$fk=>$docId,'sku_id'=>$sku->id,'uom_id'=>$resolved['uom_id'],
                'uom_code_snapshot'=>$resolved['uom_code'],'uom_name_snapshot'=>$resolved['uom_name'],
                'base_uom_id_snapshot'=>$resolved['base_uom_id'],'base_uom_code_snapshot'=>$resolved['base_uom_code'],'base_uom_name_snapshot'=>$resolved['base_uom_name'],
                'conversion_factor_snapshot'=>round($factor,8),'requested_qty_uom'=>$qty,'requested_qty_base'=>round($qty*$factor,4),
                ...($type==='sales_order' ? [
                    'unit_price'=>$unitPrice,'discount_percent'=>0,'discount_amount'=>0,'line_total'=>$lineTotal,'approved_line_total'=>0,
                    'price_source'=>WarehouseSalesPriceResolverI06::SOURCE,'price_policy_id_snapshot'=>$pricing['policy_id'],
                    'price_target_type_snapshot'=>'customer','price_target_id_snapshot'=>$targetId,'price_business_date_snapshot'=>$pricing['business_date'],
                    'price_uom_id_snapshot'=>$pricing['price_uom_id'],'price_uom_code_snapshot'=>$pricing['price_uom_code'],
                    'price_conversion_factor_snapshot'=>round((float)$pricing['conversion_factor'],8),'price_master_snapshot'=>round((float)$pricing['unit_price'],6),
                    'price_transaction_unit_snapshot'=>$unitPrice,'price_rule_snapshot'=>json_encode($this->salesPricing->snapshot($pricing)),
                ] : []),
                'approved_qty_uom'=>0,'approved_qty_base'=>0,'status'=>'draft','notes'=>$line['notes']??null,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        if(!$seen)throw ValidationException::withMessages(['items'=>['Minimal satu item wajib diisi.']]);
        $subtotal=$type==='sales_order'?(float)DB::table('wh_v3_sales_order_items')->where('sales_order_id',$docId)->sum('line_total'):0.0;
        return ['subtotal'=>round($subtotal,2),'grand_total'=>round($subtotal,2)];
    }

    private function assertStrictPricingSnapshots(string $salesOrderId):void
    {
        $invalid=DB::table('wh_v3_sales_order_items')->where('sales_order_id',$salesOrderId)->where(function($q):void{
            $q->where('price_source','!=',WarehouseSalesPriceResolverI06::SOURCE)->orWhereNull('price_policy_id_snapshot')->orWhereNull('price_rule_snapshot')->orWhere('unit_price','<=',0);
        })->exists();
        if($invalid)throw ValidationException::withMessages(['warehouse_price'=>['Sales Order lama/draft belum memiliki snapshot Harga Outlet & Customer. Edit dan Save ulang sebelum Submit/Approve.']]);
    }

    private function createPrepare(string $type,object $row,$items,string $itemTable,string $fk,string $warehouseId,string $destType,string $destinationId,string $numberCol,string $userId):string
    {
        if($row->prepare_request_id)return (string)$row->prepare_request_id;
        $id=(string)Str::ulid();$number=(string)$row->{$numberCol};$needed=$row->needed_date;
        DB::table('wh_v3_logistics_prepare_requests')->insert(['id'=>$id,'prepare_number'=>$this->number('PREP'),'warehouse_id'=>$warehouseId,'source_type'=>$type,'source_id'=>$row->id,'source_number'=>$number,'destination_type'=>$destType,'destination_id'=>$destinationId,'status'=>'queued','requested_delivery_date'=>$needed,'approved_by_user_id'=>$userId,'approved_at'=>now(),'metadata'=>json_encode(['flow_version'=>3,'source'=>'iterasi_07','barcode_required'=>false]),'created_at'=>now(),'updated_at'=>now()]);
        $freshItems=DB::table($itemTable)->where($fk,$row->id)->where('approved_qty_base','>',0)->get();
        foreach($freshItems as $item)DB::table('wh_v3_logistics_prepare_items')->insert(['id'=>(string)Str::ulid(),'prepare_request_id'=>$id,'source_item_id'=>$item->id,'sku_id'=>$item->sku_id,'uom_id'=>$item->uom_id,'requested_qty_uom'=>$item->requested_qty_uom,'requested_qty_base'=>$item->requested_qty_base,'approved_qty_uom'=>$item->approved_qty_uom,'approved_qty_base'=>$item->approved_qty_base,'ready_qty_base'=>0,'status'=>'queued','notes'=>$item->notes,'metadata'=>json_encode(['flow_version'=>3,'source_type'=>$type]),'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function syncAll(string $type,string $warehouseId):void
    {
        [$table,$whCol]=$type==='sales_order'?['wh_v3_sales_orders','warehouse_id']:['wh_v3_transfer_orders','origin_warehouse_id'];
        DB::table($table)->where($whCol,$warehouseId)->when($type==='sales_order',fn($q)=>$q->where('sales_channel','legacy'))->whereNotNull('prepare_request_id')->whereNotIn('status',['completed','cancelled'])->pluck('id')->each(fn($id)=>$this->syncOne($type,(string)$id));
    }

    private function syncOne(string $type,string $id):void
    {
        [$table,$numberCol]=$type==='sales_order'?['wh_v3_sales_orders','sales_order_number']:['wh_v3_transfer_orders','transfer_number'];$row=DB::table($table)->where('id',$id)->when($type==='sales_order',fn($q)=>$q->where('sales_channel','legacy'))->first();if(!$row||!$row->prepare_request_id)return;
        $p=DB::table('wh_v3_logistics_prepare_requests')->where('id',$row->prepare_request_id)->first();if(!$p)return;$d=DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$p->id)->first();$g=$d?DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$d->id)->first():null;
        $status=(string)$row->status;if($g?->status==='completed')$status='completed';elseif($g?->status==='submitted')$status='goods-receipt';elseif($d)$status='on-delivery';elseif(in_array((string)$p->status,['queued','preparing'],true))$status='approved';
        if($status!==$row->status)DB::table($table)->where('id',$id)->update(['status'=>$status,'completed_at'=>$status==='completed'?now():$row->completed_at,'updated_at'=>now()]);
    }

    private function logisticsSnapshot(?string $prepareId):?array
    {
        if(!$prepareId)return null;$p=DB::table('wh_v3_logistics_prepare_requests')->where('id',$prepareId)->first();if(!$p)return null;$d=DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$prepareId)->first();$g=$d?DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$d->id)->first():null;
        return ['prepare'=>['id'=>(string)$p->id,'number'=>$p->prepare_number,'status'=>$p->status],'delivery_order'=>$d?['id'=>(string)$d->id,'number'=>$d->delivery_number,'status'=>$d->status]:null,'goods_receipt'=>$g?['id'=>(string)$g->id,'number'=>$g->goods_receipt_number,'status'=>$g->status]:null];
    }

    private function timeline(string $type,string $id,?string $prepareId):array
    {
        $events=DB::table('wh_v3_sales_transfer_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where('e.document_type',$type)->where('e.document_id',$id)->get(['e.*','u.name as actor_name'])->map(fn($e)=>['source'=>$type,'event_type'=>$e->event_type,'message'=>$e->message,'from_status'=>$e->from_status,'to_status'=>$e->to_status,'actor'=>$e->actor_name,'created_at'=>$e->occurred_at])->all();
        if($prepareId){
            $deliveryId=DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$prepareId)->value('id');
            $grId=$deliveryId?DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->value('id'):null;
            $log=DB::table('wh_v3_logistics_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where(function($q)use($prepareId,$deliveryId,$grId){
                $q->where(fn($x)=>$x->where('e.document_type','prepare_request')->where('e.document_id',$prepareId));
                if($deliveryId)$q->orWhere(fn($x)=>$x->where('e.document_type','delivery_order')->where('e.document_id',$deliveryId));
                if($grId)$q->orWhere(fn($x)=>$x->where('e.document_type','goods_receipt')->where('e.document_id',$grId));
            })->get(['e.*','u.name as actor_name'])->map(fn($e)=>['source'=>'logistics','event_type'=>$e->event_type,'message'=>$e->message,'from_status'=>$e->from_status,'to_status'=>$e->to_status,'actor'=>$e->actor_name,'created_at'=>$e->occurred_at])->all();
            $events=array_merge($events,$log);
        }
        usort($events,fn($a,$b)=>strcmp((string)$a['created_at'],(string)$b['created_at']));return $events;
    }

    private function applyListFilters($q,array $filters,string $numberCol,string $destCol):void
    {
        if(!empty($filters['q'])){$term='%'.trim((string)$filters['q']).'%';$q->where(fn($x)=>$x->where($numberCol,'like',$term)->orWhere($destCol,'like',$term));}if(!empty($filters['status']))$q->where('o.status',$filters['status']);
    }

    private function event(string $type,string $id,string $event,?string $from,?string $to,string $message,?string $actor,array $meta=[]):void
    {
        DB::table('wh_v3_sales_transfer_events')->insert(['id'=>(string)Str::ulid(),'document_type'=>$type,'document_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>$meta?json_encode($meta):null,'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function number(string $prefix):string{return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));}
    private function paginated(LengthAwarePaginator $p,callable $map):array{return ['items'=>collect($p->items())->map($map)->values()->all(),'pagination'=>['current_page'=>$p->currentPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'last_page'=>$p->lastPage()]];}
}
