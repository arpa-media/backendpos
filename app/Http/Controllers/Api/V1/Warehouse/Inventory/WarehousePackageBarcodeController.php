<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Inventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\Inventory\WarehousePackageBarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehousePackageBarcodeController extends WarehouseInventoryBaseController
{
    public function __construct(private readonly WarehousePackageBarcodeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'q'=>['nullable','string','max:180'], 'status'=>['nullable','string','max:24'],
            'package_state'=>['nullable',Rule::in(['sealed','opened','repacked','relabeled','relabeled_source','depleted'])],
            'sku_id'=>['nullable','ulid'], 'per_page'=>['nullable','integer','min:1','max:200'],
        ]);
        $query = DB::table('wh_stock_units as u')
            ->leftJoin('stk_skus as s','s.id','=','u.sku_id')
            ->leftJoin('stk_uoms as bu','bu.id','=','s.base_uom_id')
            ->leftJoin('stk_uoms as pu','pu.id','=','u.package_uom_id')
            ->leftJoin('wh_batches as b','b.id','=','u.batch_id')
            ->leftJoin('wh_storages as st','st.id','=','u.storage_id')
            ->where('u.warehouse_id',$warehouseId)
            ->select(['u.*','s.sku_code','s.name as item_name','bu.code as base_uom_code','pu.name as package_uom_name','b.batch_code','b.expiry_date','st.code as storage_code','st.name as storage_name']);
        if(!empty($filters['q'])){$term=trim($filters['q']);$query->where(fn($q)=>$q->where('u.barcode','like',"%{$term}%")->orWhere('s.sku_code','like',"%{$term}%")->orWhere('s.name','like',"%{$term}%")->orWhere('b.batch_code','like',"%{$term}%"));}
        foreach(['status','package_state','sku_id'] as $key) if(!empty($filters[$key])) $query->where('u.'.$key,$filters[$key]);
        $paginator=$query->orderByDesc('u.created_at')->paginate((int)($filters['per_page']??50));
        $items=$paginator->getCollection()->map(fn($row)=>$this->serialize($row));
        $summary=DB::table('wh_stock_units')->where('warehouse_id',$warehouseId)->selectRaw('COUNT(*) total, SUM(CASE WHEN COALESCE(remaining_qty_base,qty_base)>0 THEN 1 ELSE 0 END) active_packages, SUM(COALESCE(remaining_qty_base,qty_base)) remaining_qty_base, SUM(CASE WHEN package_state="opened" THEN 1 ELSE 0 END) opened_packages')->first();
        return ApiResponse::ok(['items'=>$items,'pagination'=>['current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total()],'summary'=>$summary]);
    }

    public function options(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok([
            'skus'=>DB::table('stk_skus')->where('is_active',true)->orderBy('name')->get(['id','sku_code','name','base_uom_id']),
            'uoms'=>DB::table('stk_uoms')->where('is_active',true)->orderBy('name')->get(['id','code','name','symbol']),
            'storages'=>DB::table('wh_storages')->where('warehouse_id',$warehouseId)->where('is_active',true)->orderBy('code')->get(['id','code','name']),
        ]);
    }

    public function configure(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $data=$request->validate(['package_uom_id'=>['nullable','ulid','exists:stk_uoms,id'],'package_conversion_factor'=>['required','numeric','gt:0','max:999999999999'],'expected_lock_version'=>['nullable','integer','min:1']]);
        $updated=DB::transaction(function()use($warehouseId,$id,$data,$request){
            $unit=DB::table('wh_stock_units')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first();
            if(!$unit)abort(404,'Barcode tidak ditemukan pada warehouse aktif.');
            if(isset($data['expected_lock_version'])&&(int)$unit->lock_version!==(int)$data['expected_lock_version'])abort(409,'Barcode sudah berubah. Muat ulang data.');
            $uom=$data['package_uom_id']?DB::table('stk_uoms')->where('id',$data['package_uom_id'])->first():null;
            DB::table('wh_stock_units')->where('id',$id)->update(['package_uom_id'=>$uom?->id,'package_uom_code'=>$uom?->code,'package_conversion_factor'=>$data['package_conversion_factor'],'lock_version'=>DB::raw('lock_version + 1'),'updated_by_user_id'=>$request->user()?->id,'updated_at'=>now()]);
            return DB::table('wh_stock_units')->where('id',$id)->first();
        });
        return ApiResponse::ok(['item'=>$this->serialize($updated)],'Konfigurasi kemasan berhasil disimpan.');
    }

    public function split(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $data=$request->validate(['split_qty_base'=>['required','numeric','gt:0'],'reason'=>['nullable','string','max:500']]);
        return ApiResponse::ok($this->service->split($warehouseId,$id,(float)$data['split_qty_base'],$data['reason']??null,$request->user()?->id),'Barcode berhasil di-split.');
    }

    public function relabel(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $data=$request->validate(['reason'=>['nullable','string','max:500']]);
        return ApiResponse::ok($this->service->relabel($warehouseId,$id,$data['reason']??null,$request->user()?->id),'Barcode pengganti berhasil dibuat.');
    }

    public function consume(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $data=$request->validate(['barcode'=>['required','string','max:120'],'qty_base'=>['required','numeric','gt:0'],'context_type'=>['required','string','max:60'],'context_id'=>['required','string','max:100'],'reason'=>['nullable','string','max:500']]);
        return ApiResponse::ok(['item'=>$this->service->consume($warehouseId,$data['barcode'],(float)$data['qty_base'],$data['context_type'],$data['context_id'],$data['reason']??null,$request->user()?->id)],'Quantity barcode berhasil diproses.');
    }

    public function events(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $exists=DB::table('wh_stock_units')->where('warehouse_id',$warehouseId)->where('id',$id)->exists(); if(!$exists)return ApiResponse::error('Barcode tidak ditemukan.','BARCODE_NOT_FOUND',404);
        $items=DB::table('wh_stock_unit_events as e')->leftJoin('users as u','u.id','=','e.created_by_user_id')->where('e.stock_unit_id',$id)->orderByDesc('e.created_at')->get(['e.*','u.name as created_by_name']);
        return ApiResponse::ok(['items'=>$items]);
    }

    public function countOpname(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $data=$request->validate(['session_key'=>['required','string','max:100'],'barcode'=>['required','string','max:120'],'counted_qty_base'=>['required','numeric','min:0'],'notes'=>['nullable','string','max:500']]);
        return ApiResponse::ok($this->service->countOpname($warehouseId,$data['session_key'],$data['barcode'],(float)$data['counted_qty_base'],$data['notes']??null,$request->user()?->id),'Hasil hitung barcode berhasil disimpan.');
    }

    private function serialize(object|array $row): array
    {
        $r=(array)$row; $remaining=(float)($r['remaining_qty_base']??$r['qty_base']??0); $original=(float)($r['original_qty_base']??$r['qty_base']??0);
        return array_merge($r,['qty_base'=>$remaining,'remaining_qty_base'=>$remaining,'original_qty_base'=>$original,'consumed_qty_base'=>round(max(0,$original-$remaining),4),'is_partial'=>$remaining>0&&$remaining<$original,'lock_version'=>(int)($r['lock_version']??1)]);
    }
}
