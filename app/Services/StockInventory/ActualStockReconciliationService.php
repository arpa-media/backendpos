<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ActualStockReconciliationService
{
    public function __construct(private readonly ActualStockLedgerViewService $ledgerView)
    {
    }

    /**
     * Repair derived destination inventory rows from authoritative Warehouse GR /
     * submitted Stock Opname documents. Safe to run repeatedly.
     *
     * @return array<string,mixed>
     */
    public function reconcile(?string $outletId = null, bool $dryRun = false): array
    {
        $this->assertDependencies();
        $outlets = $this->outletIds($outletId);

        $totals = [
            'outlets' => 0,
            'gr_lines_scanned' => 0,
            'movements_created' => 0,
            'movements_normalized' => 0,
            'balances_changed' => 0,
            'quantity_delta_total' => 0.0,
        ];
        $results = [];

        foreach ($outlets as $id) {
            $result = DB::transaction(fn (): array => $this->reconcileOutlet((string) $id, $dryRun), 5);
            $results[] = $result;
            $totals['outlets']++;
            foreach (['gr_lines_scanned', 'movements_created', 'movements_normalized', 'balances_changed'] as $key) {
                $totals[$key] += (int) ($result['summary'][$key] ?? 0);
            }
            $totals['quantity_delta_total'] += (float) ($result['summary']['quantity_delta_total'] ?? 0);
        }
        $totals['quantity_delta_total'] = round($totals['quantity_delta_total'], 4);

        return [
            'mode' => $dryRun ? 'dry-run' : 'repair',
            'summary' => $totals,
            'outlets' => $results,
        ];
    }

    /** @return array<string,mixed> */
    private function reconcileOutlet(string $outletId, bool $dryRun): array
    {
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name']);
        $hardResetAt = $this->latestHardResetAt($outletId);
        $created = 0;
        $normalized = 0;
        $scanned = 0;

        // Phase 1: ensure every completed Warehouse V3 GR line has exactly one
        // destination movement. The unique movement reference index makes this
        // safe under retries and concurrent repair attempts.
        foreach ($this->warehouseV3ReceiptLines($outletId, $hardResetAt) as $line) {
            $scanned++;
            $movement = InventoryMovement::query()
                ->where('movement_type', 'goods_receipt')
                ->where('reference_type', 'wh_v3_goods_receipt')
                ->where('reference_line_id', $line->line_id)
                ->lockForUpdate()
                ->first();

            $unitCost = $this->warehouseV3UnitCost($line, $movement);
            $businessDate = (string) ($line->receipt_date ?: substr((string) ($line->completed_at ?: $line->received_at), 0, 10));
            $metadata = array_merge((array) ($movement?->metadata ?? []), [
                'warehouse_logistics_v3' => true,
                'warehouse_ledger_posting_id' => $line->ledger_posting_id ? (string) $line->ledger_posting_id : null,
                'erp_v5_iteration_11_reconciled' => true,
                'goods_receipt_number' => (string) $line->goods_receipt_number,
                'goods_receipt_completed_at' => $line->completed_at ? (string) $line->completed_at : null,
            ]);

            if (! $movement) {
                $created++;
                if (! $dryRun) {
                    InventoryMovement::query()->create([
                        'outlet_id' => $outletId,
                        'sku_id' => (string) $line->sku_id,
                        'movement_type' => 'goods_receipt',
                        'reference_type' => 'wh_v3_goods_receipt',
                        'reference_id' => (string) $line->goods_receipt_id,
                        'reference_line_id' => (string) $line->line_id,
                        'business_date' => $businessDate,
                        'quantity' => round((float) $line->received_qty_base, 4),
                        'unit_cost' => $unitCost,
                        'total_cost' => round((float) $line->received_qty_base * $unitCost, 2),
                        'balance_qty_after' => 0,
                        'average_cost_after' => 0,
                        'inventory_value_after' => 0,
                        'metadata' => $metadata,
                        'created_by_user_id' => null,
                    ]);
                }
                continue;
            }

            $needsNormalize = abs((float) $movement->quantity - (float) $line->received_qty_base) > 0.0001
                || (string) $movement->business_date?->format('Y-m-d') !== $businessDate
                || abs((float) $movement->unit_cost - $unitCost) > 0.0001;

            if ($needsNormalize) {
                $normalized++;
                if (! $dryRun) {
                    $movement->forceFill([
                        'business_date' => $businessDate,
                        'quantity' => round((float) $line->received_qty_base, 4),
                        'unit_cost' => $unitCost,
                        'total_cost' => round((float) $line->received_qty_base * $unitCost, 2),
                        'metadata' => $metadata,
                    ])->save();
                }
            } elseif (! $dryRun && empty(($movement->metadata ?? [])['erp_v5_iteration_11_reconciled'])) {
                $movement->forceFill(['metadata' => $metadata])->save();
            }
        }

        // Dry-run must calculate against the same canonical GR documents even when
        // a derived movement is missing. Build a virtual cost map for those lines.
        $virtualCosts = $this->warehouseV3ReceiptLines($outletId, $hardResetAt)
            ->mapWithKeys(function ($line): array {
                $existing = InventoryMovement::query()
                    ->where('movement_type', 'goods_receipt')
                    ->where('reference_type', 'wh_v3_goods_receipt')
                    ->where('reference_line_id', $line->line_id)
                    ->first();
                return [(string) $line->line_id => $this->warehouseV3UnitCost($line, $existing)];
            });

        // Phase 2: rebuild the canonical running quantity/cost chain. COGS sale
        // consumption is deliberately excluded from Actual/Current Stock policy.
        $running = [];
        $timeline = $this->ledgerView->authoritativeTimeline($outletId);
        foreach ($timeline as $event) {
            $skuId = (string) $event['sku_id'];
            $prior = $running[$skuId] ?? ['qty' => 0.0, 'avg' => 0.0, 'value' => 0.0];
            $afterQty = round((float) $event['balance_after'], 4);
            $signedQty = round((float) $event['signed_quantity'], 4);

            $movement = InventoryMovement::query()
                ->where('reference_type', (string) $event['reference_type'])
                ->where('reference_line_id', (string) $event['reference_line_id'])
                ->whereIn('movement_type', ['goods_receipt', 'stock_opname_adjustment'])
                ->lockForUpdate()
                ->first();

            if ($event['kind'] === 'goods_receipt') {
                $unitCost = $movement
                    ? max(0, round((float) $movement->unit_cost, 4))
                    : max(0, round((float) ($virtualCosts[(string) $event['reference_line_id']] ?? 0), 4));
                $lineValue = round(max(0, $signedQty) * $unitCost, 2);
                $afterValue = round((float) $prior['value'] + $lineValue, 2);
                $afterAvg = $afterQty > 0.0001 ? round($afterValue / $afterQty, 4) : 0.0;
            } else {
                // Opname changes authoritative quantity, not the costing policy.
                // Preserve the running cost; when this is the first post-reset
                // anchor, reuse a non-zero cost already stored on its movement.
                $movementCost = max(
                    0,
                    round((float) ($movement?->average_cost_after ?? 0), 4),
                    round((float) ($movement?->unit_cost ?? 0), 4)
                );
                $unitCost = (float) $prior['avg'] > 0.0001
                    ? round((float) $prior['avg'], 4)
                    : $movementCost;
                $lineValue = round($signedQty * $unitCost, 2);
                $afterAvg = $unitCost;
                $afterValue = round($afterQty * $afterAvg, 2);
            }

            $running[$skuId] = ['qty' => $afterQty, 'avg' => $afterAvg, 'value' => $afterValue];

            if ($movement) {
                // Iteration 11 owns quantity synchronization. Existing non-zero
                // costing is intentionally preserved for Iteration 15 COGS/valuation
                // hardening; zero derived cost fields may be safely backfilled.
                $movementNeedsNormalize = abs((float) $movement->quantity - $signedQty) > 0.0001
                    || abs((float) $movement->balance_qty_after - $afterQty) > 0.0001
                    || (string) $movement->business_date?->format('Y-m-d') !== (string) $event['business_date'];

                $isOpname = ($event['kind'] ?? null) === 'stock_opname';
                $costBackfill = abs((float) $movement->unit_cost) <= 0.0001 && $unitCost > 0.0001;
                $avgBackfill = abs((float) $movement->average_cost_after) <= 0.0001 && $afterAvg > 0.0001;
                $valueBackfill = abs((float) ($movement->inventory_value_after ?? 0)) <= 0.01 && abs($afterValue) > 0.01;
                $opnameCostMismatch = $isOpname && (
                    abs((float) $movement->unit_cost - $unitCost) > 0.0001
                    || abs((float) $movement->total_cost - $lineValue) > 0.01
                    || abs((float) $movement->average_cost_after - $afterAvg) > 0.0001
                    || abs((float) ($movement->inventory_value_after ?? 0) - $afterValue) > 0.01
                );

                if ($movementNeedsNormalize || $costBackfill || $avgBackfill || $valueBackfill || $opnameCostMismatch) {
                    $normalized++;
                    if (! $dryRun) {
                        $meta = array_merge((array) ($movement->metadata ?? []), [
                            'erp_v5_iteration_11_chain_normalized' => true,
                            'erp_v5_iteration_07_absolute_opname' => $isOpname,
                            'opname_semantics' => $isOpname ? 'absolute_physical_count_anchor' : (($movement->metadata ?? [])['opname_semantics'] ?? null),
                            'authoritative_balance_before' => (float) $event['balance_before'],
                            'authoritative_balance_after' => $afterQty,
                            'authoritative_quantity_delta' => $signedQty,
                            'existing_nonzero_cost_preserved' => ! $isOpname,
                        ]);
                        $updates = [
                            'business_date' => (string) $event['business_date'],
                            'quantity' => $signedQty,
                            'balance_qty_after' => $afterQty,
                            'metadata' => $meta,
                        ];
                        if ($isOpname) {
                            // Opname quantity is an absolute physical anchor. Once the
                            // authoritative delta changes, all derived movement value fields
                            // must follow the same sign/after balance for History Stock audit.
                            $updates['unit_cost'] = $unitCost;
                            $updates['total_cost'] = $lineValue;
                            $updates['average_cost_after'] = $afterAvg;
                            $updates['inventory_value_after'] = $afterValue;
                        } else {
                            if ($costBackfill) {
                                $updates['unit_cost'] = $unitCost;
                                $updates['total_cost'] = $lineValue;
                            }
                            if ($avgBackfill) $updates['average_cost_after'] = $afterAvg;
                            if ($valueBackfill) $updates['inventory_value_after'] = $afterValue;
                        }
                        $movement->forceFill($updates)->save();
                    }
                }
            }
        }

        // Phase 3: Current Stock aggregate must equal the exact same authoritative
        // state map used by the Actual Stock UI.
        $state = $this->ledgerView->stateMap($outletId);
        $skuIds = collect();
        $skuIds = $skuIds->merge($state->keys());
        $skuIds = $skuIds->merge(DB::table('stk_inventory_balances')->where('outlet_id', $outletId)->pluck('sku_id'));
        if (Schema::hasTable('stk_par_stocks')) {
            $skuIds = $skuIds->merge(DB::table('stk_par_stocks')->where('outlet_id', $outletId)->where('is_active', true)->pluck('sku_id'));
        }
        $skuIds = $skuIds->filter()->map(fn ($id) => (string) $id)->unique()->values();

        $balancesChanged = 0;
        $deltaTotal = 0.0;
        foreach ($skuIds as $skuId) {
            $balance = InventoryBalance::query()
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->lockForUpdate()
                ->first();

            $desiredQty = round((float) ($state->get($skuId)['qty'] ?? 0), 4);
            $costState = $running[$skuId] ?? null;
            $existingAvg = round((float) ($balance?->average_unit_cost ?? 0), 4);
            // Do not replace an existing non-zero valuation policy in Iteration 11.
            // Running authoritative cost is only a fallback for missing/zero cost.
            $desiredAvg = $existingAvg > 0.0001
                ? $existingAvg
                : ($costState ? round((float) $costState['avg'], 4) : 0.0);
            $desiredValue = round($desiredQty * $desiredAvg, 2);
            $currentQty = round((float) ($balance?->on_hand_qty ?? 0), 4);
            $currentAvg = round((float) ($balance?->average_unit_cost ?? 0), 4);
            $currentValue = round((float) ($balance?->inventory_value ?? 0), 2);

            $changed = abs($desiredQty - $currentQty) > 0.0001
                || abs($desiredAvg - $currentAvg) > 0.0001
                || abs($desiredValue - $currentValue) > 0.01;
            if (! $changed) continue;

            $balancesChanged++;
            $deltaTotal += $desiredQty - $currentQty;
            if ($dryRun) continue;

            if (! $balance) {
                DB::table('stk_inventory_balances')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'outlet_id' => $outletId,
                    'sku_id' => $skuId,
                    'on_hand_qty' => $desiredQty,
                    'average_unit_cost' => $desiredAvg,
                    'inventory_value' => $desiredValue,
                    'last_movement_at' => now(),
                    'lock_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $balance->forceFill([
                    'on_hand_qty' => $desiredQty,
                    'average_unit_cost' => $desiredAvg,
                    'inventory_value' => $desiredValue,
                    'last_movement_at' => now(),
                    'lock_version' => ((int) $balance->lock_version) + 1,
                ])->save();
            }
        }

        if (! $dryRun && Schema::hasColumn('wh_v3_goods_receipts', 'outlet_inventory_posted_at')) {
            $completedIds = DB::table('wh_v3_goods_receipts')
                ->where('destination_type', 'outlet')
                ->where('destination_id', $outletId)
                ->where('status', 'completed')
                ->pluck('id');
            foreach ($completedIds as $grId) {
                $lineCount = DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id', $grId)->where('received_qty_base', '>', 0)->count();
                $postedCount = DB::table('stk_inventory_movements')
                    ->where('movement_type', 'goods_receipt')
                    ->where('reference_type', 'wh_v3_goods_receipt')
                    ->where('reference_id', $grId)
                    ->count();
                if ($lineCount > 0 && $postedCount >= $lineCount) {
                    DB::table('wh_v3_goods_receipts')->where('id', $grId)->whereNull('outlet_inventory_posted_at')->update([
                        'outlet_inventory_posted_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return [
            'outlet' => [
                'id' => $outletId,
                'code' => (string) ($outlet?->code ?? ''),
                'name' => (string) ($outlet?->name ?? $outletId),
            ],
            'hard_reset_at' => $hardResetAt,
            'summary' => [
                'gr_lines_scanned' => $scanned,
                'movements_created' => $created,
                'movements_normalized' => $normalized,
                'balances_changed' => $balancesChanged,
                'quantity_delta_total' => round($deltaTotal, 4),
                'authoritative_event_count' => $timeline->count(),
            ],
        ];
    }

    private function warehouseV3ReceiptLines(string $outletId, ?string $hardResetAt): Collection
    {
        $query = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
            ->where('g.destination_type', 'outlet')
            ->where('g.destination_id', $outletId)
            ->where('g.status', 'completed')
            ->where('i.received_qty_base', '>', 0);

        if ($hardResetAt) {
            $query->where('g.completed_at', '>', $hardResetAt);
        }

        return $query
            ->orderBy('g.receipt_date')
            ->orderBy('g.completed_at')
            ->orderBy('i.id')
            ->get([
                'g.id as goods_receipt_id', 'g.goods_receipt_number', 'g.receipt_date', 'g.received_at', 'g.completed_at', 'g.ledger_posting_id',
                'i.id as line_id', 'i.delivery_order_item_id', 'i.sku_id', 'i.received_qty_base',
            ]);
    }

    private function warehouseV3UnitCost(object $line, ?InventoryMovement $movement): float
    {
        if ($line->ledger_posting_id && Schema::hasTable('wh_ledger_entries')) {
            $cost = DB::table('wh_ledger_entries')
                ->where('posting_id', $line->ledger_posting_id)
                ->where('line_key', 'like', 'DOITEM-'.$line->delivery_order_item_id.'-%')
                ->selectRaw('COALESCE(SUM(total_cost),0) total_cost, COALESCE(SUM(quantity_base),0) qty')
                ->first();
            if ($cost && abs((float) $cost->qty) > 0.0001) {
                return max(0, round(abs((float) $cost->total_cost / (float) $cost->qty), 4));
            }
        }

        if (Schema::hasTable('wh_v3_outgoing_invoice_items')) {
            $invoiceCost = DB::table('wh_v3_outgoing_invoice_items')
                ->where('goods_receipt_item_id', $line->line_id)
                ->value('inventory_unit_cost');
            if ($invoiceCost !== null && (float) $invoiceCost > 0) {
                return round((float) $invoiceCost, 4);
            }
        }

        return max(0, round((float) ($movement?->unit_cost ?? 0), 4));
    }

    private function outletIds(?string $outletId): Collection
    {
        if ($outletId) {
            return collect([$outletId]);
        }

        $ids = DB::table('wh_v3_goods_receipts')
            ->where('destination_type', 'outlet')
            ->where('status', 'completed')
            ->pluck('destination_id');

        if (Schema::hasTable('stk_par_stocks')) {
            $ids = $ids->merge(DB::table('stk_par_stocks')->where('is_active', true)->pluck('outlet_id'));
        }

        return $ids->filter()->map(fn ($id) => (string) $id)->unique()->values();
    }

    private function latestHardResetAt(string $outletId): ?string
    {
        if (! Schema::hasTable('stk_actual_stock_reset_runs')) return null;
        $value = DB::table('stk_actual_stock_reset_runs')
            ->where('outlet_id', $outletId)
            ->where('mode', 'reset_opnames_zero')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->value('executed_at');
        return $value ? (string) $value : null;
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'stk_skus', 'stk_inventory_balances', 'stk_inventory_movements',
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items',
        ];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('ERP-V5 Iteration 11 membutuhkan table: '.implode(', ', $missing));
        }
    }
}
