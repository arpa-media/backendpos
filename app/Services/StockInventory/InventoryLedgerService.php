<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryLedgerService
{
    public function releaseGoodsReceipt(GoodsReceipt $receipt, ?string $userId): void
    {
        // Stage 02 Actual Stock policy: Manual Stock is document/finance-only.
        if ($receipt->receipt_type === GoodsReceipt::TYPE_MANUAL) {
            return;
        }

        $receipt->loadMissing('items');

        foreach ($receipt->items as $item) {
            $alreadyPosted = InventoryMovement::query()
                ->where('movement_type', 'goods_receipt')
                ->where('reference_type', 'stk_goods_receipt')
                ->where('reference_line_id', $item->id)
                ->exists();

            if ($alreadyPosted) {
                continue;
            }

            $balance = InventoryBalance::query()
                ->where('outlet_id', $receipt->outlet_id)
                ->where('sku_id', $item->sku_id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                DB::table('stk_inventory_balances')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'outlet_id' => $receipt->outlet_id,
                    'sku_id' => $item->sku_id,
                    'on_hand_qty' => 0,
                    'average_unit_cost' => 0,
                    'inventory_value' => 0,
                    'lock_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $balance = InventoryBalance::query()
                    ->where('outlet_id', $receipt->outlet_id)
                    ->where('sku_id', $item->sku_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $incomingQty = round((float) $item->received_qty, 4);
            $incomingUnitCost = round((float) $item->unit_cost, 4);
            $incomingValue = round($incomingQty * $incomingUnitCost, 2);
            $oldQty = (float) $balance->on_hand_qty;
            $oldValue = (float) $balance->inventory_value;
            $newQty = round($oldQty + $incomingQty, 4);
            $newValue = round($oldValue + $incomingValue, 2);
            $newAverage = $newQty > 0 ? round($newValue / $newQty, 4) : 0.0;

            $balance->forceFill([
                'on_hand_qty' => $newQty,
                'average_unit_cost' => $newAverage,
                'inventory_value' => $newValue,
                'last_movement_at' => now(),
                'lock_version' => ((int) $balance->lock_version) + 1,
            ])->save();

            InventoryMovement::query()->create([
                'outlet_id' => $receipt->outlet_id,
                'sku_id' => $item->sku_id,
                'movement_type' => 'goods_receipt',
                'reference_type' => 'stk_goods_receipt',
                'reference_id' => $receipt->id,
                'reference_line_id' => $item->id,
                'business_date' => $receipt->receipt_date,
                'quantity' => $incomingQty,
                'unit_cost' => $incomingUnitCost,
                'total_cost' => $incomingValue,
                'balance_qty_after' => $newQty,
                'average_cost_after' => $newAverage,
                'inventory_value_after' => $newValue,
                'metadata' => [
                    'gr_number' => $receipt->gr_number,
                    'receipt_type' => $receipt->receipt_type,
                    'shipment_code' => $receipt->shipment_code,
                    'supplier_document_number' => $receipt->supplier_document_number,
                    'currency' => $receipt->currency,
                    'inventory_value_after' => $newValue,
                ],
                'created_by_user_id' => $userId,
            ]);
        }
    }
}
