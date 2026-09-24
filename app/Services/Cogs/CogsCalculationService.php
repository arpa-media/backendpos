<?php

namespace App\Services\Cogs;

use App\Models\Cogs\CogsCalculationItem;
use App\Models\Cogs\CogsCalculationRun;
use App\Models\Cogs\SaleConsumption;
use App\Models\Cogs\StockVariance;
use App\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CogsCalculationService
{
    private const QTY_SCALE = 8;
    private const VALUE_SCALE = 2;
    private const RECONCILIATION_TOLERANCE = 1.00;
    private const ENGINE_VERSION = 'erp-v5-iter07-stock-opname-absolute-v1';

    public function calculate(
        string $outletId,
        string $periodFrom,
        string $periodTo,
        ?string $userId = null,
        string $reason = 'manual_calculation',
    ): CogsCalculationRun {
        $this->guardPeriod($periodFrom, $periodTo);

        return DB::transaction(function () use ($outletId, $periodFrom, $periodTo, $userId, $reason): CogsCalculationRun {
            $outlet = Outlet::query()->findOrFail($outletId);
            $run = CogsCalculationRun::query()
                ->where('outlet_id', $outletId)
                ->where('period_from', $periodFrom)
                ->where('period_to', $periodTo)
                ->lockForUpdate()
                ->first();

            if ($run?->status === CogsCalculationRun::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'period_to' => ['Periode COGS sudah ditutup dan immutable. Buat periode koreksi terpisah, jangan menghitung ulang periode closed.'],
                ]);
            }

            $openingVariance = StockVariance::query()
                ->where('outlet_id', $outletId)
                ->where('status', StockVariance::STATUS_SUBMITTED)
                ->where('variance_date', '<', $periodFrom)
                ->orderByDesc('variance_date')
                ->orderByDesc('submitted_at')
                ->first();

            $closingVariance = StockVariance::query()
                ->where('outlet_id', $outletId)
                ->where('status', StockVariance::STATUS_SUBMITTED)
                ->where('variance_date', '<=', $periodTo)
                ->orderByDesc('variance_date')
                ->orderByDesc('submitted_at')
                ->first();

            $sales = $this->salesSummary($outletId, $periodFrom, $periodTo);
            $requests = $this->requestSummary($outletId, $periodFrom, $periodTo);
            $receipts = $this->receiptSummary($outletId, $periodFrom, $periodTo);
            $consumption = $this->consumptionSummary($outletId, $periodFrom, $periodTo);
            $variance = $this->varianceSummary($outletId, $periodFrom, $periodTo);
            $exceptions = $this->exceptionSummary($outletId, $periodFrom, $periodTo);

            $openingItems = $this->varianceItems($openingVariance?->id);
            $closingItems = $this->varianceItems($closingVariance?->id);
            $receiptItems = $this->receiptItems($outletId, $periodFrom, $periodTo);
            $consumptionItems = $this->consumptionItems($outletId, $periodFrom, $periodTo, false);
            $reversalItems = $this->consumptionItems($outletId, $periodFrom, $periodTo, true);
            $otherItems = $this->otherMovementItems($outletId, $periodFrom, $periodTo);
            $varianceItems = $this->periodVarianceItems($outletId, $periodFrom, $periodTo);

            $skuIds = collect()
                ->merge($openingItems->keys())
                ->merge($closingItems->keys())
                ->merge($receiptItems->keys())
                ->merge($consumptionItems->keys())
                ->merge($reversalItems->keys())
                ->merge($otherItems->keys())
                ->merge($varianceItems->keys())
                ->filter()
                ->unique()
                ->values();

            $skus = DB::table('stk_skus as sku')
                ->join('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
                ->whereIn('sku.id', $skuIds)
                ->get([
                    'sku.id', 'sku.sku_code', 'sku.name', 'sku.base_uom_id',
                    'uom.code as uom_code', 'uom.symbol as uom_symbol',
                ])
                ->keyBy(fn ($row) => (string) $row->id);

            $rows = [];
            foreach ($skuIds as $skuId) {
                $skuId = (string) $skuId;
                $sku = $skus->get($skuId);
                if (! $sku) {
                    continue;
                }

                $opening = $openingItems->get($skuId);
                $closing = $closingItems->get($skuId);
                $receipt = $receiptItems->get($skuId);
                $grossConsumption = $consumptionItems->get($skuId);
                $reversal = $reversalItems->get($skuId);
                $other = $otherItems->get($skuId);
                $varianceRow = $varianceItems->get($skuId);

                $openingQty = (float) ($opening->actual_qty ?? 0);
                $openingCost = (float) ($opening->unit_cost_snapshot ?? 0);
                $openingValue = $this->roundValue($openingQty * $openingCost);
                $receiptQty = (float) ($receipt->quantity ?? 0);
                $receiptValue = (float) ($receipt->value ?? 0);
                $consumptionQty = (float) ($grossConsumption->quantity ?? 0);
                $consumptionValue = (float) ($grossConsumption->value ?? 0);
                $reversalQty = (float) ($reversal->quantity ?? 0);
                $reversalValue = (float) ($reversal->value ?? 0);
                $netConsumptionQty = $this->roundQty($consumptionQty - $reversalQty);
                $netConsumptionValue = $this->roundValue($consumptionValue - $reversalValue);
                $otherQty = (float) ($other->quantity ?? 0);
                $otherValue = (float) ($other->value ?? 0);
                $closingQty = (float) ($closing->actual_qty ?? 0);
                $closingCost = (float) ($closing->unit_cost_snapshot ?? 0);
                $closingValue = $this->roundValue($closingQty * $closingCost);
                $varianceQty = (float) ($varianceRow->variance_qty ?? 0);
                $varianceValue = (float) ($varianceRow->net_variance_value ?? 0);

                $finalCogs = $this->roundValue($netConsumptionValue - $varianceValue);
                $inventoryBridge = $this->roundValue($openingValue + $receiptValue + $otherValue - $closingValue);
                $difference = $this->roundValue($finalCogs - $inventoryBridge);

                $warnings = [];
                if (! $opening) {
                    $warnings[] = 'missing_opening_snapshot';
                }
                if (! $closing) {
                    $warnings[] = 'missing_closing_snapshot';
                }
                if (($consumptionQty > 0 || $openingQty > 0 || $closingQty > 0) && max($openingCost, $closingCost, (float) ($grossConsumption->max_unit_cost ?? 0)) <= 0) {
                    $warnings[] = 'zero_cost';
                }
                if (abs($difference) > self::RECONCILIATION_TOLERANCE) {
                    $warnings[] = 'reconciliation_difference';
                }

                $rows[] = [
                    'sku_id' => $skuId,
                    'base_uom_id' => (string) $sku->base_uom_id,
                    'sku_code_snapshot' => (string) ($sku->sku_code ?? ''),
                    'sku_name_snapshot' => (string) $sku->name,
                    'base_uom_code_snapshot' => (string) ($sku->uom_code ?? ''),
                    'base_uom_symbol_snapshot' => (string) ($sku->uom_symbol ?? $sku->uom_code ?? ''),
                    'opening_quantity' => $this->decimal($openingQty, self::QTY_SCALE),
                    'opening_unit_cost' => $this->decimal($openingCost, self::QTY_SCALE),
                    'opening_value' => $this->decimal($openingValue, self::VALUE_SCALE),
                    'receipt_quantity' => $this->decimal($receiptQty, self::QTY_SCALE),
                    'receipt_value' => $this->decimal($receiptValue, self::VALUE_SCALE),
                    'consumption_quantity' => $this->decimal($consumptionQty, self::QTY_SCALE),
                    'consumption_value' => $this->decimal($consumptionValue, self::VALUE_SCALE),
                    'reversal_quantity' => $this->decimal($reversalQty, self::QTY_SCALE),
                    'reversal_value' => $this->decimal($reversalValue, self::VALUE_SCALE),
                    'net_consumption_quantity' => $this->decimal($netConsumptionQty, self::QTY_SCALE),
                    'net_consumption_value' => $this->decimal($netConsumptionValue, self::VALUE_SCALE),
                    'other_movement_quantity' => $this->decimal($otherQty, self::QTY_SCALE),
                    'other_movement_value' => $this->decimal($otherValue, self::VALUE_SCALE),
                    'closing_quantity' => $this->decimal($closingQty, self::QTY_SCALE),
                    'closing_unit_cost' => $this->decimal($closingCost, self::QTY_SCALE),
                    'closing_value' => $this->decimal($closingValue, self::VALUE_SCALE),
                    'variance_quantity' => $this->decimal($varianceQty, self::QTY_SCALE),
                    'variance_value' => $this->decimal($varianceValue, self::VALUE_SCALE),
                    'final_cogs_value' => $this->decimal($finalCogs, self::VALUE_SCALE),
                    'inventory_bridge_cogs_value' => $this->decimal($inventoryBridge, self::VALUE_SCALE),
                    'reconciliation_difference' => $this->decimal($difference, self::VALUE_SCALE),
                    'warning_codes' => array_values(array_unique($warnings)),
                    'trace_snapshot' => [
                        'opening_variance_id' => $openingVariance?->id ? (string) $openingVariance->id : null,
                        'closing_variance_id' => $closingVariance?->id ? (string) $closingVariance->id : null,
                        'receipt_line_count' => (int) ($receipt->line_count ?? 0),
                        'consumption_item_count' => (int) ($grossConsumption->line_count ?? 0),
                        'reversal_item_count' => (int) ($reversal->line_count ?? 0),
                        'other_movement_count' => (int) ($other->movement_count ?? 0),
                        'stock_opname_adjustment_excluded_from_other_movement' => true,
                        'variance_document_count' => (int) ($varianceRow->document_count ?? 0),
                    ],
                ];
            }

            $openingInventoryValue = $this->roundValue(array_sum(array_map(fn ($row) => (float) $row['opening_value'], $rows)));
            $closingInventoryValue = $this->roundValue(array_sum(array_map(fn ($row) => (float) $row['closing_value'], $rows)));
            $otherMovementValue = $this->roundValue(array_sum(array_map(fn ($row) => (float) $row['other_movement_value'], $rows)));
            $netRecipeCogs = $this->roundValue((float) $consumption['gross_value'] - (float) $consumption['reversal_value']);
            $varianceAdjustment = $this->roundValue(-1 * (float) $variance['net_variance_value']);
            $finalCogs = $this->roundValue($netRecipeCogs + $varianceAdjustment);
            $inventoryBridge = $this->roundValue($openingInventoryValue + (float) $receipts['value'] + $otherMovementValue - $closingInventoryValue);
            $reconciliationDifference = $this->roundValue($finalCogs - $inventoryBridge);
            $grossProfit = $this->roundValue((float) $sales['net_sales_value'] - $finalCogs);
            $cogsRatio = (float) $sales['net_sales_value'] > 0
                ? round(($finalCogs / (float) $sales['net_sales_value']) * 100, 4)
                : 0.0;

            $checks = $this->buildChecks(
                $periodFrom,
                $periodTo,
                $openingVariance,
                $closingVariance,
                $exceptions,
                $variance,
                $reconciliationDifference,
                $requests,
                $receipts,
            );
            $attentionCount = collect($checks)->whereIn('status', ['warning', 'failed'])->count();
            $failedCount = collect($checks)->where('status', 'failed')->count();
            $warningCount = collect($checks)->where('status', 'warning')->count();
            $qualityScore = max(0, 100 - ($failedCount * 15) - ($warningCount * 5));

            $sourceSnapshot = [
                'calculation_engine' => self::ENGINE_VERSION,
                'other_movement_policy' => [
                    'stock_opname_adjustment' => 'excluded_variance_owns_physical_count_difference',
                ],
                'outlet_id' => $outletId,
                'outlet_timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'opening_variance_id' => $openingVariance?->id ? (string) $openingVariance->id : null,
                'closing_variance_id' => $closingVariance?->id ? (string) $closingVariance->id : null,
                'sales' => $sales,
                'requests' => $requests,
                'receipts' => $receipts,
                'consumption' => $consumption,
                'variance' => $variance,
                'exceptions' => $exceptions,
                'sku_count' => count($rows),
            ];
            $fingerprint = hash('sha256', json_encode($sourceSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $payload = [
                'outlet_id' => $outletId,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'status' => CogsCalculationRun::STATUS_CALCULATED,
                'opening_variance_id' => $openingVariance?->id,
                'closing_variance_id' => $closingVariance?->id,
                'opening_snapshot_date' => $openingVariance?->variance_date?->toDateString(),
                'closing_snapshot_date' => $closingVariance?->variance_date?->toDateString(),
                'source_fingerprint' => $fingerprint,
                'source_snapshot' => $sourceSnapshot,
                'reconciliation_checks' => $checks,
                'sales_transaction_count' => (int) $sales['transactions'],
                'sale_item_line_count' => (int) $sales['item_lines'],
                'item_sold_quantity' => $this->decimal((float) $sales['sold_quantity'], self::QTY_SCALE),
                'net_sales_value' => $this->decimal((float) $sales['net_sales_value'], self::VALUE_SCALE),
                'stock_request_document_count' => (int) $requests['documents'],
                'stock_request_line_count' => (int) $requests['lines'],
                'requested_quantity' => $this->decimal((float) $requests['requested_qty'], self::QTY_SCALE),
                'approved_quantity' => $this->decimal((float) $requests['approved_qty'], self::QTY_SCALE),
                'goods_receipt_document_count' => (int) $receipts['documents'],
                'goods_receipt_line_count' => (int) $receipts['lines'],
                'received_quantity' => $this->decimal((float) $receipts['quantity'], self::QTY_SCALE),
                'purchasing_value' => $this->decimal((float) $receipts['value'], self::VALUE_SCALE),
                'consumption_event_count' => (int) $consumption['gross_events'],
                'consumption_item_count' => (int) $consumption['gross_items'],
                'recipe_consumption_quantity' => $this->decimal((float) $consumption['gross_quantity'], self::QTY_SCALE),
                'recipe_cogs_value' => $this->decimal((float) $consumption['gross_value'], self::VALUE_SCALE),
                'reversal_event_count' => (int) $consumption['reversal_events'],
                'reversal_item_count' => (int) $consumption['reversal_items'],
                'reversal_quantity' => $this->decimal((float) $consumption['reversal_quantity'], self::QTY_SCALE),
                'reversal_value' => $this->decimal((float) $consumption['reversal_value'], self::VALUE_SCALE),
                'net_recipe_cogs_value' => $this->decimal($netRecipeCogs, self::VALUE_SCALE),
                'variance_document_count' => (int) $variance['documents'],
                'shortage_value' => $this->decimal((float) $variance['shortage_value'], self::VALUE_SCALE),
                'surplus_value' => $this->decimal((float) $variance['surplus_value'], self::VALUE_SCALE),
                'net_variance_value' => $this->decimal((float) $variance['net_variance_value'], self::VALUE_SCALE),
                'variance_adjustment_value' => $this->decimal($varianceAdjustment, self::VALUE_SCALE),
                'opening_inventory_value' => $this->decimal($openingInventoryValue, self::VALUE_SCALE),
                'closing_inventory_value' => $this->decimal($closingInventoryValue, self::VALUE_SCALE),
                'other_movement_value' => $this->decimal($otherMovementValue, self::VALUE_SCALE),
                'inventory_bridge_cogs_value' => $this->decimal($inventoryBridge, self::VALUE_SCALE),
                'final_cogs_value' => $this->decimal($finalCogs, self::VALUE_SCALE),
                'reconciliation_difference' => $this->decimal($reconciliationDifference, self::VALUE_SCALE),
                'gross_profit_value' => $this->decimal($grossProfit, self::VALUE_SCALE),
                'cogs_ratio_percent' => $this->decimal($cogsRatio, 4),
                'open_exception_count' => (int) $exceptions['open_count'],
                'zero_cost_consumption_count' => (int) $exceptions['zero_cost_count'],
                'untraced_receipt_count' => (int) $receipts['untraced_lines'],
                'variance_attention_count' => (int) $variance['attention_count'],
                'attention_count' => $attentionCount,
                'data_quality_score' => $qualityScore,
                'calculated_by_user_id' => $userId,
                'calculated_at' => now(),
                'reconciled_by_user_id' => null,
                'reconciled_at' => null,
                'closed_by_user_id' => null,
                'closed_at' => null,
                'close_notes' => null,
                'cancelled_by_user_id' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'metadata' => [
                    'calculation_reason' => $reason,
                    'calculation_engine' => self::ENGINE_VERSION,
                    'opname_semantics' => 'absolute_physical_count_anchor',
                    'stock_opname_adjustment_in_other_movement' => false,
                    'formula' => 'final_cogs = net_recipe_cogs - net_variance_value',
                    'inventory_bridge_formula' => 'opening_inventory + purchasing + other_movement_without_stock_opname_adjustment - closing_inventory',
                    'reconciliation_tolerance' => self::RECONCILIATION_TOLERANCE,
                ],
            ];

            if ($run) {
                $run->update($payload);
            } else {
                $run = CogsCalculationRun::query()->create($payload);
            }

            CogsCalculationItem::query()->where('calculation_run_id', $run->id)->delete();
            foreach ($rows as $row) {
                CogsCalculationItem::query()->create(['calculation_run_id' => (string) $run->id, ...$row]);
            }

            return $run->fresh(['items']);
        }, 3);
    }

    public function reconcile(string $runId, ?string $userId, bool $confirmAttention = false): CogsCalculationRun
    {
        $existing = CogsCalculationRun::query()->findOrFail($runId);
        if ($existing->status === CogsCalculationRun::STATUS_CLOSED) {
            return $existing;
        }

        $run = $this->calculate(
            (string) $existing->outlet_id,
            $existing->period_from->toDateString(),
            $existing->period_to->toDateString(),
            $userId,
            'reconciliation_refresh',
        );

        $blocking = collect($run->reconciliation_checks ?: [])->where('blocking', true)->where('status', 'failed');
        if ($blocking->isNotEmpty() && ! $confirmAttention) {
            throw ValidationException::withMessages([
                'confirm_attention' => ['Rekonsiliasi memiliki blocking check: '.$blocking->pluck('label')->implode(', ').'. Perbaiki sumber data atau konfirmasi perhatian secara eksplisit.'],
            ]);
        }

        $run->update([
            'status' => CogsCalculationRun::STATUS_RECONCILED,
            'reconciled_by_user_id' => $userId,
            'reconciled_at' => now(),
            'metadata' => array_merge((array) ($run->metadata ?? []), [
                'reconciliation_attention_confirmed' => $confirmAttention,
                'reconciliation_confirmed_at' => now()->toIso8601String(),
            ]),
        ]);

        return $run->fresh(['items']);
    }

    public function close(string $runId, ?string $userId, bool $confirmAttention, ?string $notes): CogsCalculationRun
    {
        $run = $this->reconcile($runId, $userId, $confirmAttention);
        if ($run->status === CogsCalculationRun::STATUS_CLOSED) {
            return $run;
        }

        if ((int) $run->attention_count > 0 && trim((string) $notes) === '') {
            throw ValidationException::withMessages([
                'close_notes' => ['Catatan penutupan wajib diisi bila masih ada attention atau override rekonsiliasi.'],
            ]);
        }

        return DB::transaction(function () use ($run, $userId, $confirmAttention, $notes): CogsCalculationRun {
            $locked = CogsCalculationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === CogsCalculationRun::STATUS_CLOSED) {
                return $locked;
            }
            if ($locked->status !== CogsCalculationRun::STATUS_RECONCILED) {
                throw ValidationException::withMessages(['status' => ['COGS harus berstatus reconciled sebelum ditutup.']]);
            }

            $locked->update([
                'status' => CogsCalculationRun::STATUS_CLOSED,
                'closed_by_user_id' => $userId,
                'closed_at' => now(),
                'close_notes' => trim((string) $notes) ?: null,
                'metadata' => array_merge((array) ($locked->metadata ?? []), [
                    'close_attention_confirmed' => $confirmAttention,
                    'closed_source_fingerprint' => (string) $locked->source_fingerprint,
                ]),
            ]);

            return $locked->fresh(['items']);
        }, 3);
    }

    public function cancel(string $runId, ?string $userId, string $reason): CogsCalculationRun
    {
        return DB::transaction(function () use ($runId, $userId, $reason): CogsCalculationRun {
            $run = CogsCalculationRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status === CogsCalculationRun::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'reason' => ['Periode COGS yang sudah closed tidak dapat dibatalkan. Gunakan periode koreksi dengan audit trail terpisah.'],
                ]);
            }
            $run->update([
                'status' => CogsCalculationRun::STATUS_CANCELLED,
                'cancelled_by_user_id' => $userId,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ]);
            return $run->fresh(['items']);
        }, 3);
    }

    private function salesSummary(string $outletId, string $from, string $to): array
    {
        $current = DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where(function ($query): void {
                $query->where(function ($posted): void {
                    $posted->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
                        ->where('c.status', SaleConsumption::STATUS_POSTED);
                })->orWhere(function ($exception): void {
                    $exception->where('c.movement_type', SaleConsumption::TYPE_EXCEPTION)
                        ->where('c.status', SaleConsumption::STATUS_OPEN);
                });
            });

        $row = (clone $current)
            ->selectRaw('COUNT(*) as item_lines')
            ->selectRaw('COUNT(DISTINCT c.sale_id) as transactions')
            ->selectRaw('COALESCE(SUM(c.sold_quantity), 0) as sold_quantity')
            ->first();
        $saleIds = (clone $current)->distinct()->pluck('c.sale_id');
        $netSales = $saleIds->isEmpty() ? 0 : (float) DB::table('sales')
            ->whereIn('id', $saleIds)
            ->where('status', 'PAID')
            ->whereNull('deleted_at')
            ->sum('grand_total');

        return [
            'transactions' => (int) ($row->transactions ?? 0),
            'item_lines' => (int) ($row->item_lines ?? 0),
            'sold_quantity' => (float) ($row->sold_quantity ?? 0),
            'net_sales_value' => $netSales,
        ];
    }

    private function requestSummary(string $outletId, string $from, string $to): array
    {
        $query = DB::table('stk_requests as request')
            ->where('request.outlet_id', $outletId)
            ->whereBetween('request.request_date', [$from, $to])
            ->whereNotIn('request.status', ['draft', 'cancelled', 'rejected']);
        $ids = (clone $query)->pluck('request.id');
        $items = $ids->isEmpty() ? null : DB::table('stk_request_items')
            ->whereIn('stock_request_id', $ids)
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COALESCE(SUM(requested_qty), 0) as requested_qty')
            ->selectRaw('COALESCE(SUM(approved_qty), 0) as approved_qty')
            ->first();

        return [
            'documents' => (clone $query)->count(),
            'lines' => (int) ($items->line_count ?? 0),
            'requested_qty' => (float) ($items->requested_qty ?? 0),
            'approved_qty' => (float) ($items->approved_qty ?? 0),
        ];
    }

    private function receiptSummary(string $outletId, string $from, string $to): array
    {
        $snapshots = DB::table('cogs_purchasing_cost_snapshots')
            ->where('outlet_id', $outletId)
            ->whereBetween('receipt_date', [$from, $to]);
        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $snapshots->where('is_canonical', true);
        }
        $row = (clone $snapshots)
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COUNT(DISTINCT goods_receipt_id) as documents')
            ->selectRaw('COALESCE(SUM(received_qty), 0) as quantity')
            ->selectRaw('COALESCE(SUM(line_total), 0) as value')
            ->first();

        $untraced = 0;
        if (Schema::hasTable('stk_goods_receipts') && Schema::hasTable('stk_goods_receipt_items')) {
            $untraced += DB::table('stk_goods_receipt_items as item')
                ->join('stk_goods_receipts as receipt', 'receipt.id', '=', 'item.goods_receipt_id')
                ->leftJoin('cogs_purchasing_cost_snapshots as snapshot', 'snapshot.goods_receipt_item_id', '=', 'item.id')
                ->where('receipt.outlet_id', $outletId)
                ->where('receipt.status', 'released')
                ->whereBetween('receipt.receipt_date', [$from, $to])
                ->whereNull('snapshot.id')
                ->count();
        }
        if (Schema::hasTable('wh_v3_goods_receipts') && Schema::hasTable('wh_v3_goods_receipt_items')) {
            $untraced += DB::table('wh_v3_goods_receipt_items as item')
                ->join('wh_v3_goods_receipts as receipt', 'receipt.id', '=', 'item.goods_receipt_id')
                ->leftJoin('cogs_purchasing_cost_snapshots as snapshot', 'snapshot.goods_receipt_item_id', '=', 'item.id')
                ->where('receipt.destination_type', 'outlet')
                ->where('receipt.destination_id', $outletId)
                ->where('receipt.status', 'completed')
                ->where('item.received_qty_base', '>', 0)
                ->whereBetween('receipt.receipt_date', [$from, $to])
                ->whereNull('snapshot.id')
                ->count();
        }

        return [
            'documents' => (int) ($row->documents ?? 0),
            'lines' => (int) ($row->line_count ?? 0),
            'quantity' => (float) ($row->quantity ?? 0),
            'value' => (float) ($row->value ?? 0),
            'untraced_lines' => (int) $untraced,
        ];
    }

    private function consumptionSummary(string $outletId, string $from, string $to): array
    {
        $gross = DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereIn('c.status', [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED])
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('COALESCE(SUM(c.total_base_quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(c.total_cost), 0) as value')
            ->first();
        $grossItems = DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereIn('c.status', [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED])
            ->count();
        $reversal = DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', SaleConsumption::TYPE_REVERSAL)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('COALESCE(SUM(c.total_base_quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(c.total_cost), 0) as value')
            ->first();
        $reversalItems = DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', SaleConsumption::TYPE_REVERSAL)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->count();

        return [
            'gross_events' => (int) ($gross->events ?? 0),
            'gross_items' => (int) $grossItems,
            'gross_quantity' => (float) ($gross->quantity ?? 0),
            'gross_value' => (float) ($gross->value ?? 0),
            'reversal_events' => (int) ($reversal->events ?? 0),
            'reversal_items' => (int) $reversalItems,
            'reversal_quantity' => (float) ($reversal->quantity ?? 0),
            'reversal_value' => (float) ($reversal->value ?? 0),
        ];
    }

    private function varianceSummary(string $outletId, string $from, string $to): array
    {
        $row = DB::table('cogs_stock_variances')
            ->where('outlet_id', $outletId)
            ->whereBetween('variance_date', [$from, $to])
            ->where('status', StockVariance::STATUS_SUBMITTED)
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw('COALESCE(SUM(shortage_value), 0) as shortage_value')
            ->selectRaw('COALESCE(SUM(surplus_value), 0) as surplus_value')
            ->selectRaw('COALESCE(SUM(net_variance_value), 0) as net_variance_value')
            ->selectRaw('COALESCE(SUM(zero_cost_sku_count + missing_opening_sku_count + open_exception_count + uncounted_movement_sku_count), 0) as attention_count')
            ->first();

        return [
            'documents' => (int) ($row->documents ?? 0),
            'shortage_value' => (float) ($row->shortage_value ?? 0),
            'surplus_value' => (float) ($row->surplus_value ?? 0),
            'net_variance_value' => (float) ($row->net_variance_value ?? 0),
            'attention_count' => (int) ($row->attention_count ?? 0),
        ];
    }

    private function exceptionSummary(string $outletId, string $from, string $to): array
    {
        $open = DB::table('cogs_sale_consumptions')
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $to])
            ->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
            ->where('status', SaleConsumption::STATUS_OPEN)
            ->count();
        $zeroCost = DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->where('item.unit_cost_snapshot', '<=', 0)
            ->count();
        return ['open_count' => (int) $open, 'zero_cost_count' => (int) $zeroCost];
    }

    private function varianceItems(?string $varianceId): Collection
    {
        if (! $varianceId) {
            return collect();
        }
        return DB::table('cogs_stock_variance_items')
            ->where('stock_variance_id', $varianceId)
            ->get(['sku_id', 'actual_qty', 'unit_cost_snapshot'])
            ->keyBy(fn ($row) => (string) $row->sku_id);
    }

    private function receiptItems(string $outletId, string $from, string $to): Collection
    {
        $query = DB::table('cogs_purchasing_cost_snapshots')
            ->where('outlet_id', $outletId)
            ->whereBetween('receipt_date', [$from, $to]);
        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $query->where('is_canonical', true);
        }

        return $query
            ->groupBy('sku_id')
            ->get([
                'sku_id',
                DB::raw('SUM(received_qty) as quantity'),
                DB::raw('SUM(line_total) as value'),
                DB::raw('COUNT(*) as line_count'),
            ])->keyBy(fn ($row) => (string) $row->sku_id);
    }

    private function consumptionItems(string $outletId, string $from, string $to, bool $reversal): Collection
    {
        $type = $reversal ? SaleConsumption::TYPE_REVERSAL : SaleConsumption::TYPE_CONSUMPTION;
        $statuses = $reversal
            ? [SaleConsumption::STATUS_POSTED]
            : [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED];

        return DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$from, $to])
            ->where('c.movement_type', $type)
            ->whereIn('c.status', $statuses)
            ->groupBy('item.sku_id')
            ->get([
                'item.sku_id',
                DB::raw('SUM(ABS(item.movement_quantity)) as quantity'),
                DB::raw('SUM(item.total_cost) as value'),
                DB::raw('MAX(item.unit_cost_snapshot) as max_unit_cost'),
                DB::raw('COUNT(*) as line_count'),
            ])->keyBy(fn ($row) => (string) $row->sku_id);
    }

    private function otherMovementItems(string $outletId, string $from, string $to): Collection
    {
        return DB::table('stk_inventory_movements')
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $to])
            ->whereNotIn('movement_type', [
                'goods_receipt',
                'stock_opname_adjustment',
                SaleConsumption::TYPE_CONSUMPTION,
                SaleConsumption::TYPE_REVERSAL,
            ])
            ->groupBy('sku_id')
            ->get([
                'sku_id',
                DB::raw('SUM(quantity) as quantity'),
                DB::raw('SUM(total_cost) as value'),
                DB::raw('COUNT(*) as movement_count'),
            ])->keyBy(fn ($row) => (string) $row->sku_id);
    }

    private function periodVarianceItems(string $outletId, string $from, string $to): Collection
    {
        return DB::table('cogs_stock_variance_items as item')
            ->join('cogs_stock_variances as variance', 'variance.id', '=', 'item.stock_variance_id')
            ->where('variance.outlet_id', $outletId)
            ->whereBetween('variance.variance_date', [$from, $to])
            ->where('variance.status', StockVariance::STATUS_SUBMITTED)
            ->groupBy('item.sku_id')
            ->get([
                'item.sku_id',
                DB::raw('SUM(item.variance_qty) as variance_qty'),
                DB::raw('SUM(item.net_variance_value) as net_variance_value'),
                DB::raw('COUNT(DISTINCT variance.id) as document_count'),
            ])->keyBy(fn ($row) => (string) $row->sku_id);
    }

    private function buildChecks(
        string $from,
        string $to,
        ?StockVariance $opening,
        ?StockVariance $closing,
        array $exceptions,
        array $variance,
        float $difference,
        array $requests,
        array $receipts,
    ): array {
        $expectedOpening = CarbonImmutable::parse($from)->subDay()->toDateString();
        $closingExact = $closing?->variance_date?->toDateString() === $to;
        $openingExact = $opening?->variance_date?->toDateString() === $expectedOpening;
        $requestApproved = (float) $requests['approved_qty'];
        $receiptQty = (float) $receipts['quantity'];

        return [
            $this->check('opening_snapshot', 'Opening physical stock tersedia', $opening ? ($openingExact ? 'passed' : 'warning') : 'warning', false,
                $opening ? 'Opening memakai Stock Variance '.$opening->variance_date?->toDateString().($openingExact ? '.' : ", bukan {$expectedOpening}.") : 'Belum ada submitted Stock Variance sebelum periode; opening dianggap 0.'),
            $this->check('closing_snapshot', 'Closing physical stock tepat pada akhir periode', $closingExact ? 'passed' : 'failed', true,
                $closingExact ? "Submitted Stock Variance tersedia pada {$to}." : 'Wajib submit Stock Variance dengan variance_date sama dengan akhir periode.'),
            $this->check('recipe_exceptions', 'Tidak ada recipe exception terbuka', (int) $exceptions['open_count'] === 0 ? 'passed' : 'failed', true,
                (int) $exceptions['open_count'].' exception terbuka.'),
            $this->check('zero_cost', 'Tidak ada consumption dengan harga nol', (int) $exceptions['zero_cost_count'] === 0 ? 'passed' : 'failed', true,
                (int) $exceptions['zero_cost_count'].' ingredient consumption memakai unit cost 0.'),
            $this->check('receipt_traceability', 'Seluruh released GR memiliki purchasing snapshot', (int) $receipts['untraced_lines'] === 0 ? 'passed' : 'failed', true,
                (int) $receipts['untraced_lines'].' GR item belum memiliki purchasing cost snapshot.'),
            $this->check('variance_attention', 'Submitted Stock Variance bebas attention', (int) $variance['attention_count'] === 0 ? 'passed' : 'failed', true,
                (int) $variance['attention_count'].' attention berasal dari variance periode.'),
            $this->check('reconciliation_difference', 'Operational COGS sama dengan inventory bridge', abs($difference) <= self::RECONCILIATION_TOLERANCE ? 'passed' : 'failed', true,
                'Selisih '.number_format($difference, 2, '.', '').'; toleransi '.number_format(self::RECONCILIATION_TOLERANCE, 2, '.', '').'.'),
            $this->check('request_receipt_quantity', 'Stock Request dan receipt dapat direkonsiliasi', $requestApproved <= 0 || $receiptQty >= $requestApproved ? 'passed' : 'warning', false,
                'Approved qty '.number_format($requestApproved, 4, '.', '').' dan received qty '.number_format($receiptQty, 4, '.', '').'. Perbedaan periode pengiriman dapat menyebabkan warning.'),
        ];
    }

    private function check(string $code, string $label, string $status, bool $blocking, string $message): array
    {
        return compact('code', 'label', 'status', 'blocking', 'message');
    }

    private function guardPeriod(string $from, string $to): void
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['period_to' => ['Tanggal akhir tidak boleh lebih kecil dari tanggal awal.']]);
        }
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['period_to' => ['Rentang kalkulasi maksimal 366 hari.']]);
        }
    }

    private function roundQty(float $value): float
    {
        return round($value, self::QTY_SCALE);
    }

    private function roundValue(float $value): float
    {
        return round($value, self::VALUE_SCALE);
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
