<?php

namespace App\Services\Warehouse\Integration;

use App\Services\Cogs\WarehouseV3ReceiptCogsRepairService;
use App\Services\Purchasing\WarehouseV3PurchasingGoodsReceiptBridgeService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WarehouseV3CompletionIntegrationService
{
    public function __construct(
        private readonly WarehouseV3PurchasingGoodsReceiptBridgeService $purchasingReceipt,
        private readonly WarehouseV3ReceiptCogsRepairService $cogs,
    ) {
    }

    /** @return array<string,mixed> */
    public function afterCompletedReceipt(string $warehouseGoodsReceiptId, ?string $userId = null): array
    {
        $result = ['ok'=>true,'purchasing_goods_receipt'=>null,'cogs'=>null,'warnings'=>[]];

        try {
            $result['purchasing_goods_receipt']=$this->purchasingReceipt->sync($warehouseGoodsReceiptId,$userId,false);
        } catch (Throwable $e) {
            $result['ok']=false;
            $result['warnings'][]='Purchasing GR bridge: '.$e->getMessage();
            Log::error('Warehouse V3 → Purchasing GR bridge gagal',[
                'warehouse_goods_receipt_id'=>$warehouseGoodsReceiptId,
                'user_id'=>$userId,
                'exception'=>$e,
            ]);
        }

        try {
            $result['cogs']=$this->cogs->repair($warehouseGoodsReceiptId,$userId,false);
        } catch (Throwable $e) {
            $result['ok']=false;
            $result['warnings'][]='COGS valuation/revaluation: '.$e->getMessage();
            Log::error('Warehouse V3 → COGS post receipt repair gagal',[
                'warehouse_goods_receipt_id'=>$warehouseGoodsReceiptId,
                'user_id'=>$userId,
                'exception'=>$e,
            ]);
        }

        return $result;
    }
}
