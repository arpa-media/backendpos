<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Production;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseCheckerProductionController extends WarehouseProductionBaseController
{
    public function __construct(private readonly WarehouseProductionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $filters=$request->validate(['status'=>['nullable',Rule::in(['assigned','in_progress','completed'])],'q'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:5','max:100']]);
        return ApiResponse::ok($this->service->listTasks($warehouseId,$filters,(string)$request->user()->id,$this->canOverrideChecker($request)));
    }

    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->showTask($id,$warehouseId,(string)$request->user()->id,$this->canOverrideChecker($request)));
    }

    public function scan(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['barcode'=>['required','string','max:120'],'idempotency_key'=>['required','string','max:120']]);
        return ApiResponse::ok($this->service->scanTask($id,$warehouseId,$payload,(string)$request->user()->id,$this->canOverrideChecker($request)),'Barcode bahan diterima.');
    }

    public function cancelAllocation(Request $request,string $id,string $allocationId): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->cancelAllocation($id,$allocationId,$warehouseId,(string)$request->user()->id,$this->canOverrideChecker($request)),'Scan bahan dibatalkan.');
    }

    public function confirmShortage(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['shortage_reason'=>['required','string','max:2000']]);
        return ApiResponse::ok($this->service->confirmShortage($id,$warehouseId,(string)$payload['shortage_reason'],(string)$request->user()->id,$this->canOverrideChecker($request)),'Ready material dikonfirmasi dengan shortage.');
    }
}
