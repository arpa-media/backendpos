<?php

namespace App\Services\Cogs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class WarehouseV3ValuationBridgeService
{
    public function __construct(
        private readonly CogsValuationResolverService $valuationResolver,
        private readonly CanonicalReceiptIdentityService $receiptIdentity,
    ) {
    }

    /**
     * Capture one Warehouse V3 outlet GR into the canonical COGS purchasing snapshot table.
     * This method never changes Actual Stock quantity.
     *
     * @return array<string,mixed>
     */
    public function captureCompletedReceipt(string $goodsReceiptId, ?string $userId = null, bool $dryRun = false): array
    {
        $this->assertDependencies();

        $gr = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->leftJoin('outlets as destination', 'destination.id', '=', 'g.destination_id')
            ->leftJoin('outlets as warehouse', 'warehouse.id', '=', 'g.warehouse_id')
            ->where('g.id', $goodsReceiptId)
            ->first([
                'g.*',
                'd.source_type', 'd.source_id', 'd.source_number',
                'destination.code as destination_code', 'destination.name as destination_name',
                'warehouse.code as warehouse_code', 'warehouse.name as warehouse_name',
            ]);

        if (! $gr) {
            throw new RuntimeException("Warehouse V3 Goods Receipt {$goodsReceiptId} tidak ditemukan.");
        }
        if ((string) $gr->destination_type !== 'outlet') {
            return ['receipt_id' => $goodsReceiptId, 'skipped' => true, 'reason' => 'destination_not_outlet'];
        }
        if (! in_array((string) $gr->status, ['submitted', 'completed'], true)) {
            return ['receipt_id' => $goodsReceiptId, 'skipped' => true, 'reason' => 'receipt_not_completed'];
        }

        $receiptDate = (string) ($gr->receipt_date ?: substr((string) ($gr->completed_at ?: $gr->received_at ?: now('Asia/Jakarta')), 0, 10));
        if ($this->isClosedPeriod((string) $gr->destination_id, $receiptDate)) {
            return [
                'receipt_id' => $goodsReceiptId,
                'skipped' => true,
                'reason' => 'closed_cogs_period',
                'receipt_date' => $receiptDate,
            ];
        }

        $items = DB::table('wh_v3_goods_receipt_items as i')
            ->join('stk_skus as sku', 'sku.id', '=', 'i.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->where('i.goods_receipt_id', $goodsReceiptId)
            ->where('i.received_qty_base', '>', 0)
            ->orderBy('i.id')
            ->get([
                'i.*', 'sku.sku_code', 'sku.name as sku_name', 'sku.base_uom_id',
                'uom.code as base_uom_code', 'uom.symbol as base_uom_symbol',
            ]);

        $summary = [
            'receipt_id' => $goodsReceiptId,
            'receipt_number' => (string) $gr->goods_receipt_number,
            'receipt_date' => $receiptDate,
            'lines' => 0,
            'created' => 0,
            'repaired' => 0,
            'unchanged' => 0,
            'unresolved_cost' => 0,
            'movement_cost_repaired' => 0,
        ];

        foreach ($items as $item) {
            $summary['lines']++;
            $movement = DB::table('stk_inventory_movements')
                ->where('movement_type', 'goods_receipt')
                ->where('reference_type', 'wh_v3_goods_receipt')
                ->where('reference_line_id', $item->id)
                ->first();

            $cost = $this->warehouseUnitCost($gr, $item, $movement);
            if ($cost['unit_cost'] <= 0) {
                $summary['unresolved_cost']++;
                continue;
            }

            if ($movement && ((float) $movement->unit_cost <= 0 || (float) $movement->average_cost_after <= 0 || (float) ($movement->inventory_value_after ?? 0) <= 0)) {
                $summary['movement_cost_repaired']++;
                if (! $dryRun) {
                    $qty = (float) $movement->quantity;
                    $afterQty = (float) $movement->balance_qty_after;
                    $afterAverage = (float) $movement->average_cost_after > 0
                        ? (float) $movement->average_cost_after
                        : $cost['unit_cost'];
                    $updates = [];
                    if ((float) $movement->unit_cost <= 0) {
                        $updates['unit_cost'] = $cost['unit_cost'];
                        $updates['total_cost'] = round($qty * $cost['unit_cost'], 2);
                    }
                    if ((float) $movement->average_cost_after <= 0) {
                        $updates['average_cost_after'] = $afterAverage;
                    }
                    if ((float) ($movement->inventory_value_after ?? 0) <= 0 && abs($afterQty) > 0.0001) {
                        $updates['inventory_value_after'] = round($afterQty * $afterAverage, 2);
                    }
                    if ($updates !== []) {
                        $meta = is_string($movement->metadata) ? json_decode($movement->metadata, true) : (array) ($movement->metadata ?? []);
                        $updates['metadata'] = json_encode(array_merge($meta ?: [], [
                            'erp_v5_iteration_15_valuation_repaired' => true,
                            'valuation_cost_source' => $cost['source'],
                        ]));
                        $updates['updated_at'] = now();
                        DB::table('stk_inventory_movements')->where('id', $movement->id)->update($updates);
                        $movement = DB::table('stk_inventory_movements')->where('id', $movement->id)->first();
                    }
                }
            }

            $trace = $this->movementTrace($movement, (float) $item->received_qty_base, $cost['unit_cost']);
            $existing = DB::table('cogs_purchasing_cost_snapshots')
                ->where('goods_receipt_item_id', $item->id)
                ->first();

            $payload = [
                'goods_receipt_id' => (string) $gr->id,
                'goods_receipt_item_id' => (string) $item->id,
                'inventory_movement_id' => $movement?->id ? (string) $movement->id : null,
                'outlet_id' => (string) $gr->destination_id,
                'sku_id' => (string) $item->sku_id,
                'supplier_source_id' => null,
                'purchase_order_id' => null,
                'purchase_order_item_id' => null,
                'stock_request_id' => (string) $gr->source_type === 'stock_request' ? (string) $gr->source_id : null,
                'stock_request_item_id' => null,
                'gr_number_snapshot' => (string) $gr->goods_receipt_number,
                'receipt_type_snapshot' => 'warehouse_v3',
                'receipt_date' => $receiptDate,
                'status_snapshot' => 'completed',
                'outlet_code_snapshot' => (string) ($gr->destination_code ?? ''),
                'outlet_name_snapshot' => (string) ($gr->destination_name ?? ''),
                'supplier_code_snapshot' => (string) ($gr->warehouse_code ?? ''),
                'supplier_name_snapshot' => (string) ($gr->warehouse_name ?? 'Warehouse'),
                'supplier_type_snapshot' => 'warehouse',
                'request_number_snapshot' => (string) $gr->source_type === 'stock_request' ? (string) ($gr->source_number ?? '') : null,
                'po_number_snapshot' => null,
                'shipment_code_snapshot' => null,
                'supplier_document_number_snapshot' => (string) $gr->goods_receipt_number,
                'sku_code_snapshot' => (string) ($item->sku_code ?? ''),
                'sku_name_snapshot' => (string) ($item->sku_name ?? ''),
                'base_uom_code_snapshot' => $item->base_uom_code,
                'base_uom_symbol_snapshot' => $item->base_uom_symbol,
                'ordered_qty' => (float) $item->sent_qty_base,
                'received_qty' => (float) $item->received_qty_base,
                'unit_cost' => $cost['unit_cost'],
                'line_total' => round((float) $item->received_qty_base * $cost['unit_cost'], 2),
                'currency' => 'IDR',
                'price_source_snapshot' => 'warehouse_selling_price',
                'balance_qty_after' => $trace['balance_qty_after'],
                'average_cost_before' => $trace['average_cost_before'],
                'average_cost_after' => $trace['average_cost_after'],
                'inventory_value_after' => $trace['inventory_value_after'],
                'released_at' => $gr->completed_at ?: $gr->received_at ?: now(),
                'released_by_user_id' => $gr->completed_by_user_id ?: $userId,
                'source_snapshot' => json_encode([
                    'source_system' => 'warehouse_v3',
                    'warehouse_goods_receipt_id' => (string) $gr->id,
                    'warehouse_goods_receipt_item_id' => (string) $item->id,
                    'warehouse_delivery_order_id' => (string) $gr->delivery_order_id,
                    'warehouse_ledger_posting_id' => $gr->ledger_posting_id ? (string) $gr->ledger_posting_id : null,
                    'source_type' => (string) $gr->source_type,
                    'source_id' => (string) $gr->source_id,
                    'source_number' => $gr->source_number,
                    'cost_source' => $cost['source'],
                    'cost_source_id' => $cost['source_id'],
                    'cogs_price_policy' => 'warehouse_selling_price',
                    'commercial_price_excluded_from_cogs' => false,
                    'hotfix' => 'warehouse_selling_price_cogs',
                ]),
            ];
            if ($this->receiptIdentity->identityColumnsAvailable()) {
                $payload = array_merge(
                    $payload,
                    $this->receiptIdentity->warehouseSnapshotIdentity((string) $gr->id, (string) $item->id),
                );
            }

            if (! $existing) {
                $summary['created']++;
                if (! $dryRun) {
                    DB::table('cogs_purchasing_cost_snapshots')->insert(array_merge([
                        'id' => (string) Str::ulid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $payload));
                }
                continue;
            }

            $source = is_string($existing->source_snapshot) ? json_decode($existing->source_snapshot, true) : (array) ($existing->source_snapshot ?? []);
            $isWarehouseV3 = ($source['source_system'] ?? null) === 'warehouse_v3'
                || (string) ($existing->receipt_type_snapshot ?? '') === 'warehouse_v3';
            $needsRepair = $isWarehouseV3 && (
                abs((float) $existing->unit_cost - (float) $payload['unit_cost']) > 0.0001
                || abs((float) $existing->line_total - (float) $payload['line_total']) > 0.01
                || (string) ($existing->price_source_snapshot ?? '') !== 'warehouse_selling_price'
                || ! $existing->inventory_movement_id
            );

            if ($needsRepair) {
                $summary['repaired']++;
                if (! $dryRun) {
                    DB::table('cogs_purchasing_cost_snapshots')->where('id', $existing->id)->update(array_merge($payload, [
                        'updated_at' => now(),
                    ]));
                }
            } else {
                $summary['unchanged']++;
            }
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function reconcile(?string $outletId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $dryRun = false): array
    {
        $this->assertDependencies();

        $query = DB::table('wh_v3_goods_receipts')
            ->where('destination_type', 'outlet')
            ->where('status', 'completed');
        if ($outletId) $query->where('destination_id', $outletId);
        if ($dateFrom) $query->where('receipt_date', '>=', $dateFrom);
        if ($dateTo) $query->where('receipt_date', '<=', $dateTo);

        $receiptIds = $query->orderBy('receipt_date')->orderBy('completed_at')->pluck('id');
        $totals = [
            'receipts' => 0, 'lines' => 0, 'created' => 0, 'repaired' => 0,
            'unchanged' => 0, 'unresolved_cost' => 0, 'movement_cost_repaired' => 0,
            'closed_period_skipped' => 0, 'balances_repaired' => 0,
        ];
        $details = [];

        foreach ($receiptIds as $id) {
            $result = DB::transaction(fn (): array => $this->captureCompletedReceipt((string) $id, null, $dryRun), 5);
            $details[] = $result;
            if (($result['reason'] ?? null) === 'closed_cogs_period') {
                $totals['closed_period_skipped']++;
                continue;
            }
            $totals['receipts']++;
            foreach (['lines', 'created', 'repaired', 'unchanged', 'unresolved_cost', 'movement_cost_repaired'] as $key) {
                $totals[$key] += (int) ($result[$key] ?? 0);
            }
        }

        $outlets = $outletId
            ? collect([$outletId])
            : DB::table('wh_v3_goods_receipts')->where('destination_type', 'outlet')->where('status', 'completed')->pluck('destination_id')->unique()->values();
        // Latest policy: outlet COGS follows Warehouse selling price, but Stock Inventory
        // valuation remains a separate ledger concern. Do not rewrite inventory balances here.
        $totals['balances_repaired'] = 0;

        return [
            'mode' => $dryRun ? 'dry-run' : 'repair',
            'summary' => $totals,
            'receipts' => $details,
        ];
    }

    private function repairZeroBalanceValuation(string $outletId, bool $dryRun): int
    {
        $rows = DB::table('stk_inventory_balances')
            ->where('outlet_id', $outletId)
            ->where('on_hand_qty', '!=', 0)
            ->where(function ($query): void {
                $query->where('average_unit_cost', '<=', 0)
                    ->orWhereRaw('ABS(inventory_value) <= 0.01');
            })
            ->get(['id', 'sku_id', 'on_hand_qty', 'average_unit_cost', 'inventory_value', 'lock_version']);

        $changed = 0;
        foreach ($rows as $row) {
            $resolved = $this->valuationResolver->resolve($outletId, (string) $row->sku_id, now('Asia/Jakarta')->toDateString(), (float) $row->average_unit_cost);
            if ($resolved['unit_cost'] <= 0) continue;
            $desiredValue = round((float) $row->on_hand_qty * $resolved['unit_cost'], 2);
            if ((float) $row->average_unit_cost > 0 && abs((float) $row->inventory_value - $desiredValue) <= 0.01) continue;
            $changed++;
            if ($dryRun) continue;

            DB::table('stk_inventory_balances')->where('id', $row->id)->update([
                'average_unit_cost' => (float) $row->average_unit_cost > 0 ? (float) $row->average_unit_cost : $resolved['unit_cost'],
                'inventory_value' => (float) $row->average_unit_cost > 0
                    ? round((float) $row->on_hand_qty * (float) $row->average_unit_cost, 2)
                    : $desiredValue,
                'lock_version' => ((int) $row->lock_version) + 1,
                'last_movement_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $changed;
    }

    /** @return array{unit_cost:float,source:string,source_id:?string} */
    /** @return array{unit_cost:float,source:string,source_id:?string} */
    private function warehouseUnitCost(object $gr, object $item, ?object $movement): array
    {
        // COGS policy: use the exact selling value billed by Warehouse, converted
        // to Base UOM by line_total / billed_qty_base. This is the same commercial
        // value seen by the outlet invoice and is intentionally different from
        // Warehouse internal inventory cost.
        if (Schema::hasTable('wh_v3_outgoing_invoice_items')) {
            $invoiceItem = DB::table('wh_v3_outgoing_invoice_items')
                ->where('goods_receipt_item_id', $item->id)
                ->first(['id', 'billed_qty_base', 'line_total', 'unit_price', 'inventory_unit_cost']);
            if ($invoiceItem) {
                $billedQtyBase = (float) ($invoiceItem->billed_qty_base ?? 0);
                $lineTotal = (float) ($invoiceItem->line_total ?? 0);
                if ($billedQtyBase > 0 && $lineTotal > 0) {
                    return [
                        'unit_cost' => round($lineTotal / $billedQtyBase, 4),
                        'source' => 'warehouse_invoice_selling_price',
                        'source_id' => (string) $invoiceItem->id,
                    ];
                }
            }
        }

        // Fallback only for incomplete legacy evidence. Once an invoice exists,
        // the branch above is authoritative.
        if ($movement && (float) $movement->unit_cost > 0) {
            return ['unit_cost' => round((float) $movement->unit_cost, 4), 'source' => 'destination_inventory_movement_fallback', 'source_id' => (string) $movement->id];
        }

        if ($gr->ledger_posting_id && Schema::hasTable('wh_ledger_entries')) {
            $cost = DB::table('wh_ledger_entries')
                ->where('posting_id', $gr->ledger_posting_id)
                ->where('line_key', 'like', 'DOITEM-'.$item->delivery_order_item_id.'-%')
                ->selectRaw('COALESCE(SUM(total_cost),0) total_cost, COALESCE(SUM(quantity_base),0) qty')
                ->first();
            if ($cost && abs((float) $cost->qty) > 0.0001) {
                $unit = abs((float) $cost->total_cost / (float) $cost->qty);
                if ($unit > 0) {
                    return ['unit_cost' => round($unit, 4), 'source' => 'warehouse_ledger_actual_cost_fallback', 'source_id' => (string) $gr->ledger_posting_id];
                }
            }
        }

        return ['unit_cost' => 0.0, 'source' => 'unavailable', 'source_id' => null];
    }

    /** @return array{balance_qty_after:?float,average_cost_before:?float,average_cost_after:?float,inventory_value_after:?float} */
    private function movementTrace(?object $movement, float $receivedQty, float $unitCost): array
    {
        if (! $movement) {
            return [
                'balance_qty_after' => null,
                'average_cost_before' => null,
                'average_cost_after' => $unitCost,
                'inventory_value_after' => null,
            ];
        }

        $afterQty = (float) $movement->balance_qty_after;
        $afterAverage = (float) $movement->average_cost_after > 0 ? (float) $movement->average_cost_after : $unitCost;
        $afterValue = (float) ($movement->inventory_value_after ?? 0);
        if ($afterValue <= 0 && abs($afterQty) > 0.0001) {
            $afterValue = round($afterQty * $afterAverage, 2);
        }
        $beforeQty = round($afterQty - $receivedQty, 4);
        $beforeValue = round($afterValue - ($receivedQty * $unitCost), 2);
        $beforeAverage = $beforeQty > 0.0001 ? max(0, round($beforeValue / $beforeQty, 4)) : 0.0;

        return [
            'balance_qty_after' => round($afterQty, 4),
            'average_cost_before' => $beforeAverage,
            'average_cost_after' => round($afterAverage, 4),
            'inventory_value_after' => round($afterValue, 2),
        ];
    }

    private function isClosedPeriod(string $outletId, string $businessDate): bool
    {
        if (! Schema::hasTable('cogs_calculation_runs')) return false;
        return DB::table('cogs_calculation_runs')
            ->where('outlet_id', $outletId)
            ->where('status', 'closed')
            ->where('period_from', '<=', $businessDate)
            ->where('period_to', '>=', $businessDate)
            ->exists();
    }

    private function assertDependencies(): void
    {
        foreach ([
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'wh_v3_delivery_orders',
            'stk_inventory_movements', 'stk_inventory_balances', 'stk_skus',
            'cogs_purchasing_cost_snapshots',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP V5 Iteration 15 membutuhkan tabel {$table}.");
            }
        }
    }
}
