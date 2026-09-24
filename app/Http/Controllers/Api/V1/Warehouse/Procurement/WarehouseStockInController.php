<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Procurement;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockInController extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehouseProcurementService $service) {}
    public function index(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $filters=$request->validate(['status'=>['nullable',Rule::in(['draft','assigned','barcodes_generated','stock_in_progress','checker_completed','approved'])],'q'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:5','max:100']]);
        return ApiResponse::ok($this->service->listStockIns($warehouseId,$filters));
    }
    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->showStockIn($id,$warehouseId));
    }
    public function plan(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['checker_user_id'=>['required','string',Rule::exists('users','id')->where('is_active',true)],'notes'=>['nullable','string','max:3000'],'items'=>['required','array','min:1','max:500'],'items.*.item_id'=>['required','string','distinct',Rule::exists('wh_stock_in_items','id')],'items.*.storage_id'=>['required','string',Rule::exists('wh_storages','id')->where('is_active',true)],'items.*.batch_code'=>['nullable','string','max:100','regex:/^[A-Za-z0-9._-]+$/'],'items.*.production_date'=>['nullable','date_format:Y-m-d'],'items.*.expiry_date'=>['nullable','date_format:Y-m-d'],'items.*.package_qty_base'=>['required','numeric','gt:0'],'items.*.notes'=>['nullable','string','max:1000']]);
        return ApiResponse::ok($this->service->planStockIn($id,$warehouseId,$payload,(string)$request->user()->id),'Checker Keeper, batch, storage, dan barcode berhasil digenerate.');
    }
    public function markPrinted(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->markLabelsPrinted($id,$warehouseId,(string)$request->user()->id),'Print label tercatat.');
    }
    public function approve(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['idempotency_key'=>['nullable','string','max:160']]);
        return ApiResponse::ok($this->service->approveStockIn($id,$warehouseId,$payload,(string)$request->user()->id),'Stock In disetujui; stok dan average cost berhasil diperbarui.');
    }
}
