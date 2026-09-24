<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Procurement;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseCheckerKeeperController extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehouseProcurementService $service) {}
    public function index(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $filters=$request->validate(['status'=>['nullable',Rule::in(['assigned','in_progress','completed'])],'q'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:5','max:100']]);
        return ApiResponse::ok($this->service->listKeeperTasks($warehouseId,(string)$request->user()->id,$filters,$this->canOverrideKeeper($request)));
    }
    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->showKeeperTask($id,$warehouseId,(string)$request->user()->id,$this->canOverrideKeeper($request)));
    }
    public function scan(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['barcode'=>['required','string','max:120'],'idempotency_key'=>['required','string','max:120']]);
        return ApiResponse::ok($this->service->scanKeeperTask($id,$warehouseId,(string)$request->user()->id,$payload,$this->canOverrideKeeper($request)),'Barcode berhasil discan dan storage dikonfirmasi.');
    }
}
