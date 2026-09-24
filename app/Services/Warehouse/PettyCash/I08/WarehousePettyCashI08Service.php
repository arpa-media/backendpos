<?php

namespace App\Services\Warehouse\PettyCash\I08;

use App\Models\Warehouse\WarehouseBatch;
use App\Services\Warehouse\FinanceV4\WarehouseGeneralPostingEngine;
use App\Services\Warehouse\WarehouseLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehousePettyCashI08Service
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_AWAITING = 'AWAITING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_COMPLETED = 'COMPLETED';

    public function __construct(
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseGeneralPostingEngine $finance,
    ) {}

    public function index(string $warehouseId, array $filters): array
    {
        $perPage = in_array((int)($filters['per_page'] ?? 25), [10,25,50,100], true) ? (int)$filters['per_page'] : 25;
        $query = DB::table('wh_i08_petty_cash as p')
            ->leftJoin('users as c','c.id','=','p.created_by_user_id')
            ->where('p.warehouse_id',$warehouseId)
            ->select(['p.*','c.name as created_by_name']);

        if ($q = trim((string)($filters['q'] ?? ''))) {
            $like = '%'.$q.'%';
            $query->where(fn($x)=>$x->where('p.petty_cash_number','like',$like)->orWhere('p.notes','like',$like));
        }
        if ($status = trim((string)($filters['status'] ?? ''))) $query->where('p.status',strtoupper($status));
        if (!empty($filters['from'])) $query->where('p.request_date','>=',(string)$filters['from']);
        if (!empty($filters['to'])) $query->where('p.request_date','<=',(string)$filters['to']);

        $page = $query->orderByDesc('p.request_date')->orderByDesc('p.created_at')->paginate($perPage);
        return [
            'data'=>collect($page->items())->map(fn($r)=>$this->headerRow($r))->all(),
            'meta'=>['current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'last_page'=>$page->lastPage(),'from'=>$page->firstItem(),'to'=>$page->lastItem()],
            'summary'=>$this->summary($warehouseId,$filters),
        ];
    }


    public function expenseSource(string $warehouseId, array $filters): array
    {
        $perPage=in_array((int)($filters['per_page']??25),[10,25,50,100],true)?(int)$filters['per_page']:25;
        $q=DB::table('wh_i08_petty_cash_items as i')
            ->join('wh_i08_petty_cash as p','p.id','=','i.petty_cash_id')
            ->leftJoin('wh_v4_finance_general_postings as f','f.id','=','p.expense_finance_posting_id')
            ->where('p.warehouse_id',$warehouseId)->whereNull('i.sku_id')->whereNotNull('p.expense_finance_posting_id');
        if(!empty($filters['from']))$q->where('p.request_date','>=',(string)$filters['from']);
        if(!empty($filters['to']))$q->where('p.request_date','<=',(string)$filters['to']);
        if($cat=trim((string)($filters['category']??'')))$q->where('i.expense_category',strtoupper($cat));
        if($search=trim((string)($filters['q']??''))){$like='%'.$search.'%';$q->where(fn($x)=>$x->where('p.petty_cash_number','like',$like)->orWhere('i.item_name_snapshot','like',$like)->orWhere('i.notes','like',$like));}
        $page=$q->orderByDesc('p.request_date')->orderByDesc('p.created_at')->orderBy('i.line_no')->paginate($perPage,['i.id','p.id as petty_cash_id','p.petty_cash_number','p.request_date','p.status as petty_cash_status','i.line_no','i.item_name_snapshot','i.expense_category','i.qty_uom','i.uom_code_snapshot','i.unit_price','i.tax_mode','i.tax_percent','i.subtotal','i.tax_amount','i.line_total','i.notes','p.expense_finance_posting_id','f.posting_no','f.status as finance_posting_status']);
        return ['data'=>collect($page->items())->map(fn($r)=>['id'=>(string)$r->id,'petty_cash_id'=>(string)$r->petty_cash_id,'petty_cash_number'=>(string)$r->petty_cash_number,'request_date'=>(string)$r->request_date,'petty_cash_status'=>(string)$r->petty_cash_status,'line_no'=>(int)$r->line_no,'item_name'=>(string)$r->item_name_snapshot,'category'=>(string)$r->expense_category,'qty'=>(float)$r->qty_uom,'uom'=>(string)($r->uom_code_snapshot??''),'unit_price'=>(float)$r->unit_price,'tax_mode'=>(string)$r->tax_mode,'tax_percent'=>(float)$r->tax_percent,'subtotal'=>(float)$r->subtotal,'tax_amount'=>(float)$r->tax_amount,'line_total'=>(float)$r->line_total,'notes'=>$r->notes,'finance_posting_id'=>$r->expense_finance_posting_id,'finance_posting_no'=>$r->posting_no,'finance_posting_status'=>$r->finance_posting_status])->all(),'meta'=>['current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'last_page'=>$page->lastPage(),'from'=>$page->firstItem(),'to'=>$page->lastItem()]];
    }

    public function catalogs(string $warehouseId, string $search = ''): array
    {
        $skuQuery = DB::table('stk_skus as s')
            ->join('stk_uoms as bu','bu.id','=','s.base_uom_id')
            ->leftJoin('wh_sku_uoms as pu',function($j){$j->on('pu.sku_id','=','s.id')->where('pu.is_purchase_default',true)->where('pu.is_active',true);})
            ->leftJoin('stk_uoms as u','u.id','=','pu.uom_id')
            ->where('s.is_active',true)->whereNull('s.deleted_at')
            ->select(['s.id','s.sku_code','s.name','s.base_uom_id','bu.code as base_uom_code','bu.name as base_uom_name','pu.uom_id as purchase_uom_id','u.code as purchase_uom_code','u.name as purchase_uom_name','pu.conversion_factor']);
        if ($search = trim($search)) {
            $like='%'.$search.'%';$skuQuery->where(fn($q)=>$q->where('s.sku_code','like',$like)->orWhere('s.name','like',$like));
        }
        $skus=$skuQuery->orderBy('s.name')->limit(300)->get()->map(fn($r)=>[
            'id'=>(string)$r->id,'sku_code'=>(string)$r->sku_code,'name'=>(string)$r->name,
            'base_uom'=>['id'=>(string)$r->base_uom_id,'code'=>(string)$r->base_uom_code,'name'=>(string)$r->base_uom_name,'factor'=>1.0],
            'purchase_uom'=>$r->purchase_uom_id?['id'=>(string)$r->purchase_uom_id,'code'=>(string)$r->purchase_uom_code,'name'=>(string)$r->purchase_uom_name,'factor'=>(float)$r->conversion_factor]:null,
        ])->all();
        $storages=DB::table('wh_storages')->where('warehouse_id',$warehouseId)->where('is_active',true)->whereNull('deleted_at')->orderBy('name')->get(['id','code','name'])->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name])->all();
        return ['skus'=>$skus,'storages'=>$storages,'tax_modes'=>[['code'=>'NO_TAX','label'=>'No Tax'],['code'=>'TAX','label'=>'Tax']],'expense_categories'=>['GENERAL','TRANSPORT','MAINTENANCE','SUPPLIES','MEALS','UTILITIES','OTHER']];
    }

    public function show(string $warehouseId, string $id): array
    {
        $row=DB::table('wh_i08_petty_cash as p')
            ->leftJoin('users as c','c.id','=','p.created_by_user_id')
            ->leftJoin('users as s','s.id','=','p.submitted_by_user_id')
            ->leftJoin('users as a','a.id','=','p.approved_by_user_id')
            ->leftJoin('users as r','r.id','=','p.rejected_by_user_id')
            ->where('p.warehouse_id',$warehouseId)->where('p.id',$id)
            ->first(['p.*','c.name as created_by_name','s.name as submitted_by_name','a.name as approved_by_name','r.name as rejected_by_name']);
        if(!$row) abort(404,'Petty Cash Warehouse tidak ditemukan.');
        $items=DB::table('wh_i08_petty_cash_items as i')
            ->leftJoin('stk_skus as sku','sku.id','=','i.sku_id')
            ->leftJoin('wh_storages as st','st.id','=','i.received_storage_id')
            ->where('i.petty_cash_id',$id)->orderBy('i.line_no')
            ->get(['i.*','sku.sku_code','sku.name as sku_name','st.code as storage_code','st.name as storage_name'])
            ->map(fn($i)=>$this->itemRow($i))->all();
        $events=DB::table('wh_i08_petty_cash_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where('e.petty_cash_id',$id)->orderBy('e.occurred_at')->get(['e.*','u.name as actor_name'])->map(fn($e)=>['event_type'=>(string)$e->event_type,'actor_name'=>$e->actor_name,'notes'=>$e->notes,'payload'=>$this->json($e->payload),'occurred_at'=>(string)$e->occurred_at])->all();
        return array_merge($this->headerRow($row),[
            'items'=>$items,'events'=>$events,
            'submitted_by_name'=>$row->submitted_by_name,'approved_by_name'=>$row->approved_by_name,'rejected_by_name'=>$row->rejected_by_name,
            'rejection_reason'=>$row->rejection_reason,
        ]);
    }

    public function create(string $warehouseId, array $payload, string $userId): array
    {
        $id=(string)Str::ulid();$number='PC-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr($id,-6));
        DB::transaction(function()use($id,$number,$warehouseId,$payload,$userId):void{
            DB::table('wh_i08_petty_cash')->insert([
                'id'=>$id,'petty_cash_number'=>$number,'warehouse_id'=>$warehouseId,'request_date'=>(string)$payload['request_date'],'status'=>self::STATUS_DRAFT,
                'currency_code'=>'IDR','subtotal'=>0,'tax_amount'=>0,'grand_total'=>0,'sku_gross_total'=>0,'expense_gross_total'=>0,
                'stock_receipt_status'=>'PENDING','finance_status'=>'NOT_POSTED','notes'=>$this->nullable($payload['notes']??null),'lock_version'=>1,
                'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->replaceItems($id,$warehouseId,(array)$payload['items']);
            $this->event($id,'CREATED',$userId,'Draft Petty Cash dibuat.');
        },5);
        return $this->show($warehouseId,$id);
    }

    public function update(string $warehouseId,string $id,array $payload,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$id,$payload,$userId):void{
            $row=$this->lock($warehouseId,$id);$this->assertStatus($row,self::STATUS_DRAFT);$this->assertLock($row,(int)$payload['lock_version']);
            DB::table('wh_i08_petty_cash')->where('id',$id)->update(['request_date'=>(string)$payload['request_date'],'notes'=>$this->nullable($payload['notes']??null),'lock_version'=>(int)$row->lock_version+1,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->replaceItems($id,$warehouseId,(array)$payload['items']);$this->event($id,'UPDATED',$userId,'Draft Petty Cash diperbarui.');
        },5);
        return $this->show($warehouseId,$id);
    }

    public function delete(string $warehouseId,string $id,string $userId):void
    {
        DB::transaction(function()use($warehouseId,$id,$userId):void{
            $row=$this->lock($warehouseId,$id);$this->assertStatus($row,self::STATUS_DRAFT);
            DB::table('wh_i08_petty_cash_events')->where('petty_cash_id',$id)->delete();DB::table('wh_i08_petty_cash_items')->where('petty_cash_id',$id)->delete();DB::table('wh_i08_petty_cash')->where('id',$id)->delete();
        },5);
    }

    public function submit(string $warehouseId,string $id,int $lockVersion,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$id,$lockVersion,$userId):void{
            $row=$this->lock($warehouseId,$id);if((string)$row->status===self::STATUS_AWAITING)return;$this->assertStatus($row,self::STATUS_DRAFT);$this->assertLock($row,$lockVersion);
            if(!DB::table('wh_i08_petty_cash_items')->where('petty_cash_id',$id)->exists()) throw ValidationException::withMessages(['items'=>['Minimal satu item wajib diisi.']]);
            DB::table('wh_i08_petty_cash')->where('id',$id)->update(['status'=>self::STATUS_AWAITING,'submitted_by_user_id'=>$userId,'submitted_at'=>now(),'lock_version'=>(int)$row->lock_version+1,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'SUBMITTED',$userId,'Petty Cash diajukan untuk approval.');
        },5);return $this->show($warehouseId,$id);
    }

    public function approve(string $warehouseId,string $id,?string $notes,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$id,$notes,$userId):void{
            $row=$this->lock($warehouseId,$id);if(in_array((string)$row->status,[self::STATUS_APPROVED,self::STATUS_COMPLETED],true)){$this->postExpenseFinanceLocked($warehouseId,$id,$userId);$this->refreshFinanceStatus($id);return;}$this->assertStatus($row,self::STATUS_AWAITING);
            if((string)$row->created_by_user_id===$userId) throw ValidationException::withMessages(['approval'=>['Requester tidak dapat meng-approve Petty Cash miliknya sendiri.']]);
            DB::table('wh_i08_petty_cash')->where('id',$id)->update(['status'=>self::STATUS_APPROVED,'approved_by_user_id'=>$userId,'approved_at'=>now(),'approval_notes'=>$this->nullable($notes),'lock_version'=>(int)$row->lock_version+1,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'APPROVED',$userId,$notes ?: 'Petty Cash disetujui.');
            $this->postExpenseFinanceLocked($warehouseId,$id,$userId);
            $hasSku=DB::table('wh_i08_petty_cash_items')->where('petty_cash_id',$id)->whereNotNull('sku_id')->exists();
            if(!$hasSku){DB::table('wh_i08_petty_cash')->where('id',$id)->update(['status'=>self::STATUS_COMPLETED,'stock_receipt_status'=>'NOT_REQUIRED','finance_status'=>'POSTED','completed_at'=>now(),'updated_at'=>now()]);$this->event($id,'COMPLETED',$userId,'Petty Cash non-SKU selesai dan Finance sudah diposting.');}
            else $this->refreshFinanceStatus($id);
        },5);return $this->show($warehouseId,$id);
    }

    public function reject(string $warehouseId,string $id,string $reason,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$id,$reason,$userId):void{
            $row=$this->lock($warehouseId,$id);if((string)$row->status===self::STATUS_REJECTED)return;$this->assertStatus($row,self::STATUS_AWAITING);if(trim($reason)==='')throw ValidationException::withMessages(['reason'=>['Alasan penolakan wajib diisi.']]);
            DB::table('wh_i08_petty_cash')->where('id',$id)->update(['status'=>self::STATUS_REJECTED,'rejected_by_user_id'=>$userId,'rejected_at'=>now(),'rejection_reason'=>trim($reason),'lock_version'=>(int)$row->lock_version+1,'updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'REJECTED',$userId,$reason);
        },5);return $this->show($warehouseId,$id);
    }

    public function receiveSku(string $warehouseId,string $id,array $receiptLines,string $userId):array
    {
        DB::transaction(function()use($warehouseId,$id,$receiptLines,$userId):void{
            $row=$this->lock($warehouseId,$id);if(!in_array((string)$row->status,[self::STATUS_APPROVED,self::STATUS_COMPLETED],true))throw ValidationException::withMessages(['status'=>['SKU Petty Cash hanya dapat diterima setelah approval.']]);
            if($row->stock_ledger_posting_id){$this->refreshFinanceStatus($id);return;}
            $items=DB::table('wh_i08_petty_cash_items')->where('petty_cash_id',$id)->whereNotNull('sku_id')->orderBy('line_no')->lockForUpdate()->get();
            if($items->isEmpty())throw ValidationException::withMessages(['items'=>['Petty Cash ini tidak memiliki item SKU.']]);
            $input=collect($receiptLines)->keyBy(fn($x)=>(string)($x['item_id']??''));$ledgerLines=[];$snapshot=[];
            foreach($items as $item){
                $r=(array)($input[(string)$item->id]??[]);$storageId=trim((string)($r['storage_id']??''));if($storageId==='')throw ValidationException::withMessages(['storage_id'=>["Storage wajib untuk {$item->item_name_snapshot}."]]);
                $storage=DB::table('wh_storages')->where('id',$storageId)->where('warehouse_id',$warehouseId)->where('is_active',true)->whereNull('deleted_at')->first();if(!$storage)throw ValidationException::withMessages(['storage_id'=>['Storage aktif tidak ditemukan pada Warehouse terpilih.']]);
                $productionDate=!empty($r['production_date'])?(string)$r['production_date']:now('Asia/Jakarta')->toDateString();$expiryDate=!empty($r['expiry_date'])?(string)$r['expiry_date']:CarbonImmutable::parse($productionDate,'Asia/Jakarta')->addMonthNoOverflow()->toDateString();if($expiryDate<$productionDate)throw ValidationException::withMessages(['expiry_date'=>['Expiry Date tidak boleh sebelum Production Date.']]);
                $batch=WarehouseBatch::query()->where('warehouse_id',$warehouseId)->where('source_reference_type','wh_i08_petty_cash')->where('source_reference_id',$id)->where('source_reference_line_id',$item->id)->lockForUpdate()->first();
                if(!$batch){$batch=WarehouseBatch::query()->create(['warehouse_id'=>$warehouseId,'sku_id'=>$item->sku_id,'storage_id'=>$storageId,'batch_code'=>'PC-'.strtoupper(substr((string)$id,-8)).'-'.str_pad((string)$item->line_no,2,'0',STR_PAD_LEFT),'supplier_batch_code'=>$this->nullable($r['supplier_batch_code']??null),'source_type'=>'PETTY_CASH_I08','source_reference_type'=>'wh_i08_petty_cash','source_reference_id'=>$id,'source_reference_line_id'=>$item->id,'received_at'=>now(),'production_date'=>$productionDate,'expiry_date'=>$expiryDate,'quantity_received_base'=>0,'actual_unit_cost'=>(float)$item->unit_cost_base_gross,'price_min'=>(float)$item->unit_cost_base_gross,'price_avg'=>(float)$item->unit_cost_base_gross,'price_max'=>(float)$item->unit_cost_base_gross,'status'=>'draft','notes'=>$item->notes,'metadata'=>['petty_cash_number'=>$row->petty_cash_number,'tax_accounting'=>'GROSS_CAPITALIZED_I08'],'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId]);}
                $ledgerLines[]=['line_key'=>'PETTY-CASH-I08:'.$item->id,'sku_id'=>(string)$item->sku_id,'batch_id'=>(string)$batch->id,'storage_id'=>$storageId,'direction'=>'IN','quantity_base'=>(float)$item->qty_base,'unit_cost'=>(float)$item->unit_cost_base_gross,'metadata'=>['petty_cash_id'=>$id,'petty_cash_item_id'=>(string)$item->id,'line_total_gross'=>(float)$item->line_total]];
                $snapshot[]=['item_id'=>(string)$item->id,'batch_id'=>(string)$batch->id,'storage_id'=>$storageId,'qty_base'=>(float)$item->qty_base,'unit_cost_base_gross'=>(float)$item->unit_cost_base_gross];
            }
            $posting=$this->ledger->post(['warehouse_id'=>$warehouseId,'idempotency_key'=>'WHI08:PETTY_CASH:STOCK:'.$id,'movement_type'=>'purchase_in','reference_type'=>'wh_i08_petty_cash','reference_id'=>$id,'business_date'=>(string)$row->request_date,'reason'=>'Petty Cash SKU receiving '.$row->petty_cash_number,'metadata'=>['petty_cash_number'=>$row->petty_cash_number,'source'=>'PETTY_CASH_I08'],'user_id'=>$userId,'lines'=>$ledgerLines]);
            foreach($snapshot as $x){DB::table('wh_i08_petty_cash_items')->where('id',$x['item_id'])->update(['received_storage_id'=>$x['storage_id'],'batch_id'=>$x['batch_id'],'received_qty_base'=>$x['qty_base'],'received_at'=>now(),'updated_at'=>now()]);DB::table('wh_batches')->where('id',$x['batch_id'])->update(['quantity_received_base'=>$x['qty_base'],'status'=>'active','received_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);}
            DB::table('wh_i08_petty_cash')->where('id',$id)->update(['stock_receipt_status'=>'RECEIVED','stock_ledger_posting_id'=>(string)$posting->id,'stock_received_by_user_id'=>$userId,'stock_received_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $this->event($id,'SKU_RECEIVED',$userId,'Item SKU Petty Cash masuk Warehouse.',['ledger_posting_id'=>(string)$posting->id]);
            $this->postStockFinanceLocked($warehouseId,$id,(string)$posting->id,$userId);DB::table('wh_i08_petty_cash')->where('id',$id)->update(['status'=>self::STATUS_COMPLETED,'finance_status'=>'POSTED','completed_at'=>now(),'updated_at'=>now()]);$this->event($id,'COMPLETED',$userId,'Petty Cash selesai; stock dan Finance sudah diposting.');
        },5);return $this->show($warehouseId,$id);
    }

    private function replaceItems(string $pettyCashId,string $warehouseId,array $items):void
    {
        if($items===[])throw ValidationException::withMessages(['items'=>['Minimal satu item wajib diisi.']]);DB::table('wh_i08_petty_cash_items')->where('petty_cash_id',$pettyCashId)->delete();$subtotal=0.0;$tax=0.0;$grand=0.0;$skuGross=0.0;$expenseGross=0.0;
        foreach(array_values($items) as $index=>$row){$skuId=trim((string)($row['sku_id']??''))?:null;$name=trim((string)($row['item_name']??''));$qty=round((float)($row['qty']??0),4);if($qty<=0)throw ValidationException::withMessages(["items.{$index}.qty"=>['Qty harus lebih dari 0.']]);$unit=round((float)($row['unit_price']??0),2);if($unit<=0)throw ValidationException::withMessages(["items.{$index}.unit_price"=>['Harga harus lebih dari 0.']]);
            $uomId=null;$uomCode=$this->nullable($row['uom_text']??null);$factor=1.0;$baseUomId=null;$baseCode=$uomCode;$qtyBase=$qty;
            if($skuId){$sku=DB::table('stk_skus as s')->join('stk_uoms as b','b.id','=','s.base_uom_id')->where('s.id',$skuId)->where('s.is_active',true)->whereNull('s.deleted_at')->first(['s.id','s.name','s.base_uom_id','b.code as base_code']);if(!$sku)throw ValidationException::withMessages(["items.{$index}.sku_id"=>['SKU aktif tidak ditemukan.']]);$name=(string)$sku->name;$uomId=trim((string)($row['uom_id']??''))?:null;if(!$uomId){$p=DB::table('wh_sku_uoms')->where('sku_id',$skuId)->where('is_purchase_default',true)->where('is_active',true)->first();$uomId=$p?->uom_id ?: $sku->base_uom_id;}
                if((string)$uomId===(string)$sku->base_uom_id){$factor=1.0;$uomCode=(string)$sku->base_code;}else{$u=DB::table('wh_sku_uoms as x')->join('stk_uoms as u','u.id','=','x.uom_id')->where('x.sku_id',$skuId)->where('x.uom_id',$uomId)->where('x.is_active',true)->first(['x.conversion_factor','u.code']);if(!$u)throw ValidationException::withMessages(["items.{$index}.uom_id"=>['UOM tidak valid untuk SKU.']]);$factor=(float)$u->conversion_factor;$uomCode=(string)$u->code;}
                if($factor<=0)throw ValidationException::withMessages(["items.{$index}.uom_id"=>['Conversion factor harus lebih dari 0.']]);$baseUomId=(string)$sku->base_uom_id;$baseCode=(string)$sku->base_code;$qtyBase=round($qty*$factor,4);
            }
            if($name==='')throw ValidationException::withMessages(["items.{$index}.item_name"=>['Nama item/kebutuhan wajib diisi untuk item non-SKU.']]);$category=strtoupper(trim((string)($row['expense_category']??'GENERAL')));if(!in_array($category,['GENERAL','TRANSPORT','MAINTENANCE','SUPPLIES','MEALS','UTILITIES','OTHER'],true))throw ValidationException::withMessages(["items.{$index}.expense_category"=>['Kategori pengeluaran tidak valid.']]);$taxMode=strtoupper(trim((string)($row['tax_mode']??'NO_TAX')));if(!in_array($taxMode,['NO_TAX','TAX'],true))throw ValidationException::withMessages(["items.{$index}.tax_mode"=>['Tax mode tidak valid.']]);$taxPercent=$taxMode==='TAX'?round((float)($row['tax_percent']??11),4):0.0;if($taxPercent<0||$taxPercent>100)throw ValidationException::withMessages(["items.{$index}.tax_percent"=>['Tax percent harus 0-100.']]);$lineSub=round($qty*$unit,2);$explicitTax=array_key_exists('tax_amount',$row)&&$row['tax_amount']!==null&&$row['tax_amount']!=='';if($explicitTax&&(float)$row['tax_amount']<0)throw ValidationException::withMessages(["items.{$index}.tax_amount"=>['Tax amount tidak boleh negatif.']]);$lineTax=$taxMode==='TAX'?($explicitTax?round((float)$row['tax_amount'],2):round($lineSub*$taxPercent/100,2)):0.0;$lineTotal=round($lineSub+$lineTax,2);$unitBaseGross=$skuId&&$qtyBase>0?round($lineTotal/$qtyBase,6):0.0;
            DB::table('wh_i08_petty_cash_items')->insert(['id'=>(string)Str::ulid(),'petty_cash_id'=>$pettyCashId,'line_no'=>$index+1,'sku_id'=>$skuId,'item_name_snapshot'=>$name,'expense_category'=>$skuId?'INVENTORY':$category,'uom_id'=>$uomId,'uom_code_snapshot'=>$uomCode,'base_uom_id'=>$baseUomId,'base_uom_code_snapshot'=>$baseCode,'conversion_factor_snapshot'=>$factor,'qty_uom'=>$qty,'qty_base'=>$qtyBase,'unit_price'=>$unit,'tax_mode'=>$taxMode,'tax_percent'=>$taxPercent,'subtotal'=>$lineSub,'tax_amount'=>$lineTax,'line_total'=>$lineTotal,'unit_cost_base_gross'=>$unitBaseGross,'notes'=>$this->nullable($row['notes']??null),'metadata'=>json_encode(['tax_accounting'=>'GROSS_CAPITALIZED_OR_EXPENSED_I08']),'created_at'=>now(),'updated_at'=>now()]);$subtotal+=$lineSub;$tax+=$lineTax;$grand+=$lineTotal;if($skuId)$skuGross+=$lineTotal;else$expenseGross+=$lineTotal;
        }
        DB::table('wh_i08_petty_cash')->where('id',$pettyCashId)->update(['subtotal'=>round($subtotal,2),'tax_amount'=>round($tax,2),'grand_total'=>round($grand,2),'sku_gross_total'=>round($skuGross,2),'expense_gross_total'=>round($expenseGross,2),'stock_receipt_status'=>$skuGross>0?'PENDING':'NOT_REQUIRED','updated_at'=>now()]);
    }

    private function postExpenseFinanceLocked(string $warehouseId,string $id,string $userId):void
    {
        $row=DB::table('wh_i08_petty_cash')->where('id',$id)->lockForUpdate()->first();if(!$row||$row->expense_finance_posting_id||round((float)$row->expense_gross_total,2)<=0)return;$draft=$this->finance->createFromTemplate($warehouseId,'PETTY_CASH_EXPENSE_I08',['source_type'=>'PETTY_CASH_EXPENSE','source_id'=>$id,'source_key'=>'WHI08:PETTY_CASH:EXPENSE:'.$id,'reference_no'=>$row->petty_cash_number,'business_date'=>(string)$row->request_date,'amounts'=>['expense_total'=>(float)$row->expense_gross_total],'description'=>'Petty Cash non-SKU '.$row->petty_cash_number,'metadata'=>['petty_cash_id'=>$id,'tax_accounting'=>'GROSS_EXPENSED_I08']],$userId);$posted=$this->finance->post((string)$draft['id'],[$warehouseId],$userId);DB::table('wh_i08_petty_cash')->where('id',$id)->update(['expense_finance_posting_id'=>(string)$posted['id'],'updated_at'=>now()]);$this->event($id,'EXPENSE_FINANCE_POSTED',$userId,'Pengeluaran non-SKU diposting ke Finance Warehouse.',['general_posting_id'=>(string)$posted['id']]);
    }

    private function postStockFinanceLocked(string $warehouseId,string $id,string $ledgerPostingId,string $userId):void
    {
        $row=DB::table('wh_i08_petty_cash')->where('id',$id)->lockForUpdate()->first();if(!$row||$row->stock_finance_posting_id)return;$value=round((float)DB::table('wh_ledger_entries')->where('posting_id',$ledgerPostingId)->sum('total_cost'),2);if($value<=0)throw ValidationException::withMessages(['finance'=>['Nilai inventory Petty Cash harus lebih dari nol.']]);$draft=$this->finance->createFromTemplate($warehouseId,'PETTY_CASH_STOCK_I08',['source_type'=>'PETTY_CASH_STOCK','source_id'=>$id,'source_key'=>'WHV4:LEDGER:'.$ledgerPostingId.':PURCHASE_STOCK_RECEIPT','reference_no'=>$row->petty_cash_number,'business_date'=>(string)$row->request_date,'amounts'=>['inventory_value'=>$value],'description'=>'Petty Cash SKU '.$row->petty_cash_number,'metadata'=>['petty_cash_id'=>$id,'warehouse_ledger_posting_id'=>$ledgerPostingId,'reconciliation_contract'=>'PURCHASE_STOCK_RECEIPT_KEY_COMPAT_I08']],$userId);$posted=$this->finance->post((string)$draft['id'],[$warehouseId],$userId);DB::table('wh_i08_petty_cash')->where('id',$id)->update(['stock_finance_posting_id'=>(string)$posted['id'],'updated_at'=>now()]);$this->event($id,'STOCK_FINANCE_POSTED',$userId,'Inventory Petty Cash diposting ke Finance Warehouse.',['general_posting_id'=>(string)$posted['id'],'ledger_posting_id'=>$ledgerPostingId]);
    }

    private function refreshFinanceStatus(string $id):void
    {
        $r=DB::table('wh_i08_petty_cash')->where('id',$id)->first();if(!$r)return;$needExpense=(float)$r->expense_gross_total>0;$needStock=(float)$r->sku_gross_total>0;$required=($needExpense?1:0)+($needStock?1:0);$posted=($needExpense&&!empty($r->expense_finance_posting_id)?1:0)+($needStock&&!empty($r->stock_finance_posting_id)?1:0);$status=$required>0&&$posted===$required?'POSTED':($posted>0?'PARTIAL':'NOT_POSTED');DB::table('wh_i08_petty_cash')->where('id',$id)->update(['finance_status'=>$status,'updated_at'=>now()]);
    }

    private function lock(string $warehouseId,string $id):object{$r=DB::table('wh_i08_petty_cash')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first();if(!$r)abort(404,'Petty Cash Warehouse tidak ditemukan.');return$r;}
    private function assertStatus(object $row,string $status):void{if((string)$row->status!==$status)throw ValidationException::withMessages(['status'=>["Petty Cash harus berstatus {$status}."]]);}
    private function assertLock(object $row,int $lock):void{if((int)$row->lock_version!==$lock)throw ValidationException::withMessages(['lock_version'=>['Data sudah berubah. Refresh lalu ulangi aksi.']]);}
    private function event(string $id,string $type,string $userId,?string $notes=null,array $payload=[]):void{DB::table('wh_i08_petty_cash_events')->insert(['id'=>(string)Str::ulid(),'petty_cash_id'=>$id,'event_type'=>$type,'payload'=>$payload?json_encode($payload):null,'notes'=>$notes,'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function headerRow(object $r):array{return ['id'=>(string)$r->id,'petty_cash_number'=>(string)$r->petty_cash_number,'warehouse_id'=>(string)$r->warehouse_id,'request_date'=>(string)$r->request_date,'status'=>(string)$r->status,'currency_code'=>(string)$r->currency_code,'subtotal'=>(float)$r->subtotal,'tax_amount'=>(float)$r->tax_amount,'grand_total'=>(float)$r->grand_total,'sku_gross_total'=>(float)$r->sku_gross_total,'expense_gross_total'=>(float)$r->expense_gross_total,'stock_receipt_status'=>(string)$r->stock_receipt_status,'finance_status'=>(string)$r->finance_status,'notes'=>$r->notes,'lock_version'=>(int)$r->lock_version,'created_by_name'=>$r->created_by_name??null,'stock_ledger_posting_id'=>$r->stock_ledger_posting_id,'expense_finance_posting_id'=>$r->expense_finance_posting_id,'stock_finance_posting_id'=>$r->stock_finance_posting_id,'submitted_at'=>$r->submitted_at,'approved_at'=>$r->approved_at,'rejected_at'=>$r->rejected_at,'stock_received_at'=>$r->stock_received_at,'completed_at'=>$r->completed_at,'created_at'=>$r->created_at,'updated_at'=>$r->updated_at];}
    private function itemRow(object $i):array{return ['id'=>(string)$i->id,'line_no'=>(int)$i->line_no,'sku_id'=>$i->sku_id,'sku_code'=>$i->sku_code,'sku_name'=>$i->sku_name,'item_name'=>(string)$i->item_name_snapshot,'expense_category'=>(string)$i->expense_category,'uom_id'=>$i->uom_id,'uom_code'=>(string)($i->uom_code_snapshot??''),'base_uom_id'=>$i->base_uom_id,'base_uom_code'=>(string)($i->base_uom_code_snapshot??''),'conversion_factor'=>(float)$i->conversion_factor_snapshot,'qty'=>(float)$i->qty_uom,'qty_base'=>(float)$i->qty_base,'unit_price'=>(float)$i->unit_price,'tax_mode'=>(string)$i->tax_mode,'tax_percent'=>(float)$i->tax_percent,'subtotal'=>(float)$i->subtotal,'tax_amount'=>(float)$i->tax_amount,'line_total'=>(float)$i->line_total,'unit_cost_base_gross'=>(float)$i->unit_cost_base_gross,'notes'=>$i->notes,'received_qty_base'=>(float)$i->received_qty_base,'storage'=>$i->received_storage_id?['id'=>(string)$i->received_storage_id,'code'=>$i->storage_code,'name'=>$i->storage_name]:null,'batch_id'=>$i->batch_id,'received_at'=>$i->received_at];}
    private function summary(string $warehouseId,array $filters):array{$q=DB::table('wh_i08_petty_cash')->where('warehouse_id',$warehouseId);if(!empty($filters['from']))$q->where('request_date','>=',$filters['from']);if(!empty($filters['to']))$q->where('request_date','<=',$filters['to']);return ['count'=>(clone $q)->count(),'grand_total'=>(float)(clone $q)->whereNotIn('status',[self::STATUS_DRAFT,self::STATUS_REJECTED])->sum('grand_total'),'expense_total'=>(float)(clone $q)->whereNotIn('status',[self::STATUS_DRAFT,self::STATUS_REJECTED])->sum('expense_gross_total'),'sku_total'=>(float)(clone $q)->whereNotIn('status',[self::STATUS_DRAFT,self::STATUS_REJECTED])->sum('sku_gross_total')];}
    private function nullable(mixed $v):?string{$s=trim((string)$v);return $s===''?null:$s;}
    private function json(mixed $v):array{if(is_array($v))return$v;if(!is_string($v)||trim($v)==='')return[];$d=json_decode($v,true);return is_array($d)?$d:[];}
}
