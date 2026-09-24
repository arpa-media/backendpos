<?php

namespace App\Services\Cogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WarehouseV3ReceiptCogsRepairService
{
    public function __construct(
        private readonly WarehouseV3ValuationBridgeService $valuationBridge,
        private readonly CogsValuationResolverService $valuationResolver,
        private readonly CogsZeroCostRevaluationService $zeroCostRevaluation,
    ) {
    }

    /**
     * Capture authoritative Warehouse V3 valuation and repair same-day Item Sold COGS.
     * Quantity / Actual Stock is intentionally untouched here.
     *
     * @return array<string,mixed>
     */
    public function repair(string $warehouseGoodsReceiptId, ?string $userId = null, bool $dryRun = false): array
    {
        $this->assertDependencies();

        $gr = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->where('g.id', $warehouseGoodsReceiptId)
            ->first([
                'g.id', 'g.goods_receipt_number', 'g.status', 'g.destination_type',
                'g.destination_id', 'g.receipt_date', 'd.source_type',
            ]);

        if (! $gr) {
            throw new RuntimeException("Warehouse V3 Goods Receipt {$warehouseGoodsReceiptId} tidak ditemukan.");
        }
        if ((string) $gr->destination_type !== 'outlet' || (string) $gr->status !== 'completed') {
            return ['warehouse_gr_id' => $warehouseGoodsReceiptId, 'skipped' => true, 'reason' => 'receipt_not_completed_outlet'];
        }

        $bridge = $this->valuationBridge->captureCompletedReceipt($warehouseGoodsReceiptId, $userId, $dryRun);

        // A receipt can complete after sales were processed on the same business date.
        // Clear an earlier "unavailable" cache, then resolve zero-cost children and
        // reconcile every stale materialized parent total for that outlet/date.
        $this->valuationResolver->clearCache();
        $receiptDate = substr((string) ($gr->receipt_date ?: now('Asia/Jakarta')->toDateString()), 0, 10);
        $revalue = $this->zeroCostRevaluation->revalue(
            (string) $gr->destination_id,
            $receiptDate,
            $receiptDate,
            $dryRun,
        );

        return [
            'warehouse_gr_id' => $warehouseGoodsReceiptId,
            'warehouse_gr_number' => (string) $gr->goods_receipt_number,
            'outlet_id' => (string) $gr->destination_id,
            'valuation_bridge' => $bridge,
            'consumption_revaluation' => $revalue,
        ];
    }

    private function assertDependencies(): void
    {
        foreach ([
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'wh_v3_delivery_orders',
            'cogs_purchasing_cost_snapshots', 'cogs_sale_consumptions', 'cogs_sale_consumption_items',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP V5 I02 COGS membutuhkan tabel {$table}.");
            }
        }
    }
}
