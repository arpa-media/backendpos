<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ProductionV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ProductionV3\I06\WarehouseProductionPricingI06Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseProductionPricingI06Controller extends Controller
{
    public function __construct(private readonly WarehouseProductionPricingI06Service $service) {}

    public function priceBands(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->priceBands($this->warehouseId($request),$id));
    }

    public function storeResult(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->saveResult($this->warehouseId($request),$id,null,$this->payload($request),(string)$request->user()->id),'Draft hasil produksi + Price Band disimpan.');
    }

    public function updateResult(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok($this->service->saveResult($this->warehouseId($request),$id,$resultId,$this->payload($request),(string)$request->user()->id),'Draft hasil produksi + Price Band diperbarui.');
    }

    public function approveResult(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok($this->service->approveResult($this->warehouseId($request),$id,$resultId,(string)$request->user()->id),'Hasil produksi approved dengan snapshot Price Band.');
    }

    public function rejectResult(Request $request, string $id, string $resultId): JsonResponse
    {
        $payload=$request->validate(['reason'=>['required','string','min:3','max:2000']]);
        return ApiResponse::ok($this->service->rejectResult($this->warehouseId($request),$id,$resultId,$payload['reason'],(string)$request->user()->id),'Production Result ditandai Not Approved.');
    }

    public function deleteResult(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok($this->service->deleteResult($this->warehouseId($request),$id,$resultId,(string)$request->user()->id),'Draft/Rejected Production Result dihapus.');
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'result_date'=>['required','date'],'notes'=>['nullable','string','max:2000'],'items'=>['required','array','min:1'],
            'items.*.production_output_id'=>['required','string','max:40','distinct'],'items.*.qty_uom'=>['required','numeric','min:0'],
            'items.*.storage_id'=>['nullable','string','max:40'],'items.*.selected_price_band'=>['required','in:MIN,AVG,MAX'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function warehouseId(Request $request): string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');return $id;
    }
}
