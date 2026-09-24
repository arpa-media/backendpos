<?php

namespace App\Http\Controllers\Api\V1\Warehouse\LogisticsV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Warehouse\Integration\WarehouseV3CompletionIntegrationService;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseLogisticsFinanceIteration04Controller extends Controller
{
    public function __construct(
        private readonly WarehouseLogisticsV3Service $logistics,
        private readonly FinancePurchasingPostingService $finance,
        private readonly WarehouseV3CompletionIntegrationService $integration,
    ) {
    }

    public function completeGoodsReceipt(Request $request, string $id): JsonResponse
    {
        $payload=$request->validate(['notes'=>'nullable|string|max:1000']);
        $warehouseId=$this->warehouseId($request);
        $userId=(string)$request->user()->id;

        $data=$this->logistics->completeGoodsReceipt($warehouseId,$id,$userId,$payload['notes']??null);
        $data['finance_posting']=$this->finance->autoPostStockReceipt($id,$userId);
        $data['downstream_integration']=$this->integration->afterCompletedReceipt($id,$userId);

        return ApiResponse::ok(
            $data,
            'Goods Receipt complete. Stock, Purchasing GR, Finance valuation, dan COGS bridge diproses idempotent.'
        );
    }

    private function warehouseId(Request $request): string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
