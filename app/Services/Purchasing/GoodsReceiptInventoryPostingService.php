<?php

namespace App\Services\Purchasing;

use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\GoodsReceiptItem;
use App\Services\Cogs\CanonicalReceiptIdentityService;
use App\Services\Cogs\PurchasingCostSnapshotService;
use App\Services\StockInventory\InventoryLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsReceiptInventoryPostingService
{
    public function __construct(
        private InventoryLedgerService $ledger,
        private PurchasingCostSnapshotService $costSnapshots,
        private CanonicalReceiptIdentityService $receiptIdentity,
    ) {}

    public function post(object $execution, iterable $items, ?string $userId): ?string
    {
        if (! $execution->outlet_id) return null;

        // ERP V5 I02: a Purchasing GR created from Warehouse Stock Request is only
        // the procurement/accounting mirror. Physical outlet stock + COGS valuation
        // are owned by the completed Warehouse V3 GR, so never create a second
        // stk_goods_receipt / cost snapshot here.
        if ($this->receiptIdentity->isWarehouseStockRequestExecution($execution)) {
            return null;
        }

        $existing = GoodsReceipt::query()->where('supplier_document_number', 'PUR-EXEC:'.$execution->id)->first();
        if ($existing) {
            $this->ledger->releaseGoodsReceipt($existing, $userId);
            $this->costSnapshots->captureReceipt($existing, $userId);
            return (string) $existing->id;
        }

        $receipt = GoodsReceipt::query()->create([
            'gr_number' => 'GR-PUR-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5)),
            'receipt_type' => GoodsReceipt::TYPE_MANUAL,
            'outlet_id' => $execution->outlet_id,
            'purchase_order_id' => $execution->order_id,
            'supplier_document_number' => 'PUR-EXEC:'.$execution->id,
            'receipt_date' => $execution->document_date,
            'status' => GoodsReceipt::STATUS_RELEASED,
            'currency' => $execution->currency ?: 'IDR',
            'total_amount' => $execution->total_amount,
            'notes' => $execution->notes,
            'received_by_user_id' => $userId,
            'received_at' => now(),
            'released_by_user_id' => $userId,
            'released_at' => now(),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        foreach ($items as $item) {
            if ((float) $item->executed_qty <= 0 || ! $item->sku_id) continue;
            GoodsReceiptItem::query()->create([
                'goods_receipt_id' => $receipt->id,
                'purchase_order_item_id' => $item->order_item_id,
                'sku_id' => $item->sku_id,
                'ordered_qty' => $item->ordered_qty,
                'received_qty' => $item->executed_qty,
                'unit_cost' => $item->unit_price,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
            ]);
        }

        $receipt->load('items');
        $this->ledger->releaseGoodsReceipt($receipt, $userId);
        $this->costSnapshots->captureReceipt($receipt, $userId);
        return (string) $receipt->id;
    }
}
