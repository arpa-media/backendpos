<?php

namespace App\Http\Controllers\Api\V1\Warehouse\PettyCash\I08;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\PettyCash\I08\WarehousePettyCashI08Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehousePettyCashI08Controller extends Controller
{
    public function __construct(private readonly WarehousePettyCashI08Service $service) {}

    public function index(Request $r):JsonResponse{$p=$r->validate(['q'=>'nullable|string|max:120','status'=>'nullable|in:DRAFT,AWAITING_APPROVAL,APPROVED,REJECTED,COMPLETED','from'=>'nullable|date','to'=>'nullable|date','per_page'=>'nullable|integer|in:10,25,50,100','page'=>'nullable|integer|min:1']);return ApiResponse::ok($this->service->index($this->wh($r),$p));}
    public function expenseSource(Request $r):JsonResponse{$p=$r->validate(['q'=>'nullable|string|max:120','category'=>'nullable|string|max:60','from'=>'nullable|date','to'=>'nullable|date','per_page'=>'nullable|integer|in:10,25,50,100','page'=>'nullable|integer|min:1']);return ApiResponse::ok($this->service->expenseSource($this->wh($r),$p));}
    public function catalogs(Request $r):JsonResponse{$p=$r->validate(['q'=>'nullable|string|max:120']);return ApiResponse::ok($this->service->catalogs($this->wh($r),(string)($p['q']??'')));}
    public function show(Request $r,string $id):JsonResponse{return ApiResponse::ok($this->service->show($this->wh($r),$id));}
    public function store(Request $r):JsonResponse{$p=$this->payload($r);return ApiResponse::ok($this->service->create($this->wh($r),$p,(string)$r->user()->id),'Draft Petty Cash dibuat.');}
    public function update(Request $r,string $id):JsonResponse{$p=$this->payload($r,true);return ApiResponse::ok($this->service->update($this->wh($r),$id,$p,(string)$r->user()->id),'Draft Petty Cash diperbarui.');}
    public function destroy(Request $r,string $id):JsonResponse{$this->service->delete($this->wh($r),$id,(string)$r->user()->id);return ApiResponse::ok(null,'Draft Petty Cash dihapus.');}
    public function submit(Request $r,string $id):JsonResponse{$p=$r->validate(['lock_version'=>'required|integer|min:1']);return ApiResponse::ok($this->service->submit($this->wh($r),$id,(int)$p['lock_version'],(string)$r->user()->id),'Petty Cash diajukan.');}
    public function approve(Request $r,string $id):JsonResponse{$p=$r->validate(['notes'=>'nullable|string|max:2000']);return ApiResponse::ok($this->service->approve($this->wh($r),$id,$p['notes']??null,(string)$r->user()->id),'Petty Cash disetujui.');}
    public function reject(Request $r,string $id):JsonResponse{$p=$r->validate(['reason'=>'required|string|max:2000']);return ApiResponse::ok($this->service->reject($this->wh($r),$id,(string)$p['reason'],(string)$r->user()->id),'Petty Cash ditolak.');}
    public function receive(Request $r,string $id):JsonResponse{$p=$r->validate(['items'=>'required|array|min:1','items.*.item_id'=>'required|string','items.*.storage_id'=>'required|string','items.*.supplier_batch_code'=>'nullable|string|max:100','items.*.production_date'=>'nullable|date','items.*.expiry_date'=>'nullable|date']);return ApiResponse::ok($this->service->receiveSku($this->wh($r),$id,$p['items'],(string)$r->user()->id),'SKU Petty Cash diterima dan diposting.');}

    private function payload(Request $r,bool $update=false):array
    {
        $rules=['request_date'=>'required|date','notes'=>'nullable|string|max:3000','items'=>'required|array|min:1|max:100','items.*.sku_id'=>'nullable|string','items.*.item_name'=>'nullable|string|max:200','items.*.expense_category'=>'nullable|string|max:60','items.*.uom_id'=>'nullable|string','items.*.uom_text'=>'nullable|string|max:30','items.*.qty'=>'required|numeric|gt:0','items.*.unit_price'=>'required|numeric|gt:0','items.*.tax_mode'=>'required|in:TAX,NO_TAX','items.*.tax_percent'=>'nullable|numeric|min:0|max:100','items.*.tax_amount'=>'nullable|numeric|min:0','items.*.notes'=>'nullable|string|max:1000'];if($update)$rules['lock_version']='required|integer|min:1';return $r->validate($rules);
    }
    private function wh(Request $r):string{$id=trim((string)$r->attributes->get('warehouse_scope_id',''));abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');return$id;}
}
