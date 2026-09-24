<?php

namespace App\Http\Controllers\Api\V1\Warehouse\LogisticsV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Warehouse\Integration\WarehouseV3CompletionIntegrationService;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\SalesTransferV3\WarehouseLogisticsV7ExtensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Compatibility bridge Iterasi 05:
 * - mempertahankan outlet/corporate Finance posting dari Iterasi04 untuk Stock Request outlet,
 * - mempertahankan COGS/Purchasing downstream bridge,
 * - mempertahankan guard Transfer Stock Iterasi03,
 * - Warehouse General Posting v4 dijalankan oleh middleware di luar controller ini.
 */
final class WarehouseLogisticsFinanceV4BridgeController extends Controller
{
    public function __construct(
        private readonly WarehouseLogisticsV3Service $logistics,
        private readonly WarehouseLogisticsV7ExtensionService $transferFlow,
        private readonly FinancePurchasingPostingService $legacyFinance,
        private readonly WarehouseV3CompletionIntegrationService $integration,
    ) {}

    public function completeGoodsReceipt(Request $request,string $id):JsonResponse
    {
        $payload=$request->validate(['notes'=>['nullable','string','max:1000']]);
        $warehouseId=$this->warehouseId($request);$userId=(string)$request->user()->id;
        $sourceType=(string)DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->where('g.id',$id)->value('d.source_type');

        if($sourceType==='transfer_stock'){
            return ApiResponse::ok(
                $this->transferFlow->completeGoodsReceipt($warehouseId,$id,$userId,$payload['notes']??null),
                'Transfer Stock hanya diselesaikan melalui Receiving Stock Warehouse tujuan.'
            );
        }

        $data=$this->logistics->completeGoodsReceipt($warehouseId,$id,$userId,$payload['notes']??null);
        // Legacy/corporate bridge hanya posting bila source = stock_request & destination = outlet; selain itu IGNORED.
        $data['destination_finance_bridge']=$this->legacyFinance->autoPostStockReceipt($id,$userId);
        $data['downstream_integration']=$this->integration->afterCompletedReceipt($id,$userId);
        return ApiResponse::ok($data,'Goods Receipt complete. Warehouse v4 Finance, destination Finance, Purchasing GR, dan COGS diproses idempotent.');
    }

    private function warehouseId(Request $request):string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');return $id;
    }
}
