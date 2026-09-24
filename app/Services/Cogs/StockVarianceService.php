<?php

namespace App\Services\Cogs;

use App\Models\Cogs\StockVariance;
use App\Models\Cogs\StockVarianceItem;
use App\Models\StockInventory\StockOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockVarianceService
{
    private const QUANTITY_SCALE = 8;
    private const VALUE_SCALE = 2;

    public function __construct(private readonly CanonicalStockVarianceLedgerService $canonicalLedger)
    {
    }

    public function calculateForOpname(
        StockOpname|string $opname,
        ?string $userId = null,
        string $reason = 'manual_calculation',
    ): StockVariance {
        $document = $opname instanceof StockOpname
            ? $opname
            : StockOpname::query()->findOrFail($opname);

        $document->loadMissing(['outlet', 'items.sku.baseUom']);

        if ($document->status !== 'submitted') {
            throw ValidationException::withMessages([
                'stock_opname_id' => ['Stock Variance hanya dapat dihitung dari Stock Opname berstatus submitted.'],
            ]);
        }
        if ($document->items->isEmpty()) {
            throw ValidationException::withMessages([
                'stock_opname_id' => ['Stock Opname tidak memiliki item yang dapat dihitung.'],
            ]);
        }

        if (! $this->canonicalLedger->isCanonicalSubmittedOpname($document)) {
            $boundary = $this->canonicalLedger->resetBoundary((string) $document->outlet_id);
            throw ValidationException::withMessages([
                'stock_opname_id' => [
                    $boundary
                        ? 'Stock Opname tidak valid untuk variance karena berada sebelum reset Aktual Stock terakhir. Submit Stock Opname baru pada/ setelah '.$boundary['business_date'].' dan setelah waktu reset.'
                        : 'Stock Opname belum menjadi dokumen submitted canonical.',
                ],
            ]);
        }

        $existing = StockVariance::query()->where('stock_opname_id', $document->id)->first();
        if ($existing?->status === StockVariance::STATUS_SUBMITTED) {
            if ($this->submittedVarianceMatchesCurrentSource($existing, $document)) {
                return $existing->load('items');
            }

            // A hard reset may preserve the same Stock Opname row for audit, then
            // that row can be reused/submitted again for the same business date.
            // The old submitted variance is no longer valid evidence for the new
            // source submission, so retire it before rebuilding in-place.
            $existing->forceFill([
                'status' => StockVariance::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'erp_v5_i03_source_resubmitted_after_reset',
            ])->save();
        }

        $previous = $this->canonicalLedger->previousSubmittedOpname($document);
        $varianceDate = $document->opname_date->toDateString();
        $openingDate = $previous?->opname_date?->toDateString();

        $skuIds = $document->items->pluck('sku_id')->map(fn ($id) => (string) $id)->values();
        $previousBySku = $previous?->items?->keyBy(fn ($item) => (string) $item->sku_id) ?? collect();
        $movementSet = $this->canonicalLedger->movementSet($document, $previous, $skuIds->all());
        $movementBySku = $movementSet['by_sku'];
        $periodFrom = $movementSet['context']['movement_date_from'] ?? null;

        $rows = [];
        $totals = [
            'opening_qty_total' => 0.0,
            'movement_qty_total' => 0.0,
            'theoretical_qty_total' => 0.0,
            'actual_qty_total' => 0.0,
            'variance_qty_total' => 0.0,
            'shortage_value' => 0.0,
            'surplus_value' => 0.0,
            'net_variance_value' => 0.0,
            'absolute_variance_value' => 0.0,
            'shortage_sku_count' => 0,
            'surplus_sku_count' => 0,
            'zero_cost_sku_count' => 0,
            'missing_opening_sku_count' => 0,
        ];

        foreach ($document->items as $opnameItem) {
            $sku = $opnameItem->sku;
            if (! $sku || ! $sku->baseUom) {
                throw new \RuntimeException('Stock Opname item kehilangan SKU atau base UOM.');
            }

            $skuId = (string) $sku->id;
            $openingItem = $previousBySku->get($skuId);
            $openingQty = $openingItem ? (float) $openingItem->actual_qty : 0.0;
            $movement = $movementBySku->get($skuId) ?? (object) [
                'movement_qty' => 0,
                'goods_receipt_qty' => 0,
                'sale_consumption_qty' => 0,
                'other_movement_qty' => 0,
                'movement_count' => 0,
                'trace' => [
                    'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                    'reset_boundary' => $movementSet['context']['reset_boundary'] ?? null,
                    'opening_opname_id' => $previous ? (string) $previous->id : null,
                    'goods_receipts' => [],
                    'goods_receipt_line_count' => 0,
                    'sale_consumption' => [
                        'event_count' => 0,
                        'net_quantity' => 0,
                        'first_event_at' => null,
                        'last_event_at' => null,
                        'source_fingerprint' => hash('sha256', '[]'),
                    ],
                ],
            ];

            $movementQty = (float) $movement->movement_qty;
            $theoreticalQty = $this->roundQty($openingQty + $movementQty);
            $actualQty = $this->roundQty((float) $opnameItem->actual_qty);
            $varianceQty = $this->roundQty($actualQty - $theoreticalQty);
            $cost = $this->costAtDate((string) $document->outlet_id, $skuId, $varianceDate);
            $unitCost = $this->roundQty($cost['unit_cost']);
            $shortageValue = $varianceQty < 0 ? round(abs($varianceQty) * $unitCost, self::VALUE_SCALE) : 0.0;
            $surplusValue = $varianceQty > 0 ? round($varianceQty * $unitCost, self::VALUE_SCALE) : 0.0;
            $netValue = round($varianceQty * $unitCost, self::VALUE_SCALE);
            $warnings = [];

            if ($previous && ! $openingItem) {
                $warnings[] = 'missing_opening_stock';
                $totals['missing_opening_sku_count']++;
            }
            if ($unitCost <= 0 && (abs($actualQty) > 0.00000001 || abs($theoreticalQty) > 0.00000001)) {
                $warnings[] = 'zero_average_cost';
                $totals['zero_cost_sku_count']++;
            }
            if ($theoreticalQty < 0) {
                $warnings[] = 'negative_theoretical_stock';
            }
            if ($varianceQty < 0) {
                $totals['shortage_sku_count']++;
            } elseif ($varianceQty > 0) {
                $totals['surplus_sku_count']++;
            }

            $rows[] = [
                'stock_opname_item_id' => (string) $opnameItem->id,
                'sku_id' => $skuId,
                'base_uom_id' => (string) $sku->base_uom_id,
                'sku_code_snapshot' => (string) $sku->sku_code,
                'sku_name_snapshot' => (string) $sku->name,
                'base_uom_code_snapshot' => (string) ($sku->baseUom->code ?? ''),
                'base_uom_symbol_snapshot' => (string) ($sku->baseUom->symbol ?? $sku->baseUom->code ?? ''),
                'opening_actual_qty' => $this->decimal($openingQty, self::QUANTITY_SCALE),
                'goods_receipt_qty' => $this->decimal((float) $movement->goods_receipt_qty, self::QUANTITY_SCALE),
                'sale_consumption_qty' => $this->decimal((float) $movement->sale_consumption_qty, self::QUANTITY_SCALE),
                'other_movement_qty' => $this->decimal((float) $movement->other_movement_qty, self::QUANTITY_SCALE),
                'movement_qty' => $this->decimal($movementQty, self::QUANTITY_SCALE),
                'theoretical_qty' => $this->decimal($theoreticalQty, self::QUANTITY_SCALE),
                'actual_qty' => $this->decimal($actualQty, self::QUANTITY_SCALE),
                'variance_qty' => $this->decimal($varianceQty, self::QUANTITY_SCALE),
                'unit_cost_snapshot' => $this->decimal($unitCost, self::QUANTITY_SCALE),
                'shortage_value' => $this->decimal($shortageValue, self::VALUE_SCALE),
                'surplus_value' => $this->decimal($surplusValue, self::VALUE_SCALE),
                'net_variance_value' => $this->decimal($netValue, self::VALUE_SCALE),
                'movement_count' => (int) $movement->movement_count,
                'latest_cost_movement_id' => $cost['movement_id'],
                'warning_codes' => $warnings,
                'trace_snapshot' => [
                    ...($movement->trace ?? []),
                    'opening_opname_id' => $previous ? (string) $previous->id : null,
                    'opening_date' => $openingDate,
                    'movement_date_from' => $periodFrom,
                    'movement_date_to' => $varianceDate,
                    'movement_time_from_exclusive' => $movementSet['context']['movement_time_from_exclusive'] ?? null,
                    'movement_time_to_inclusive' => $movementSet['context']['movement_time_to_inclusive'] ?? null,
                    'canonical_movement_fingerprint' => $movementSet['fingerprint'],
                    'cost_source' => $cost['source'],
                    'cost_source_id' => $cost['source_id'],
                ],
            ];

            $totals['opening_qty_total'] += $openingQty;
            $totals['movement_qty_total'] += $movementQty;
            $totals['theoretical_qty_total'] += $theoreticalQty;
            $totals['actual_qty_total'] += $actualQty;
            $totals['variance_qty_total'] += $varianceQty;
            $totals['shortage_value'] += $shortageValue;
            $totals['surplus_value'] += $surplusValue;
            $totals['net_variance_value'] += $netValue;
            $totals['absolute_variance_value'] += abs($netValue);
        }

        $openExceptionCount = $this->openExceptionCount(
            (string) $document->outlet_id,
            $periodFrom,
            $varianceDate,
        );
        $uncountedMovementSkuCount = collect($movementSet['all_movement_sku_ids'] ?? [])
            ->diff($skuIds->all())
            ->unique()
            ->count();
        $fingerprint = $this->fingerprint(
            $document,
            $previous,
            $rows,
            $openExceptionCount,
            $uncountedMovementSkuCount,
            (string) $movementSet['fingerprint'],
        );

        return DB::transaction(function () use (
            $document,
            $previous,
            $varianceDate,
            $openingDate,
            $periodFrom,
            $movementSet,
            $rows,
            $totals,
            $openExceptionCount,
            $uncountedMovementSkuCount,
            $fingerprint,
            $userId,
            $reason,
        ): StockVariance {
            $variance = StockVariance::query()
                ->where('stock_opname_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($variance?->status === StockVariance::STATUS_SUBMITTED) {
                return $variance->load('items');
            }

            if (! $variance) {
                $variance = new StockVariance();
                $variance->stock_opname_id = $document->id;
            }

            $variance->fill([
                'outlet_id' => $document->outlet_id,
                'previous_stock_opname_id' => $previous?->id,
                'variance_date' => $varianceDate,
                'opening_date' => $openingDate,
                'status' => StockVariance::STATUS_CALCULATED,
                'source_fingerprint' => $fingerprint,
                'sku_count' => count($rows),
                'shortage_sku_count' => $totals['shortage_sku_count'],
                'surplus_sku_count' => $totals['surplus_sku_count'],
                'zero_cost_sku_count' => $totals['zero_cost_sku_count'],
                'missing_opening_sku_count' => $totals['missing_opening_sku_count'],
                'open_exception_count' => $openExceptionCount,
                'uncounted_movement_sku_count' => $uncountedMovementSkuCount,
                'opening_qty_total' => $this->decimal($totals['opening_qty_total'], self::QUANTITY_SCALE),
                'movement_qty_total' => $this->decimal($totals['movement_qty_total'], self::QUANTITY_SCALE),
                'theoretical_qty_total' => $this->decimal($totals['theoretical_qty_total'], self::QUANTITY_SCALE),
                'actual_qty_total' => $this->decimal($totals['actual_qty_total'], self::QUANTITY_SCALE),
                'variance_qty_total' => $this->decimal($totals['variance_qty_total'], self::QUANTITY_SCALE),
                'shortage_value' => $this->decimal($totals['shortage_value'], self::VALUE_SCALE),
                'surplus_value' => $this->decimal($totals['surplus_value'], self::VALUE_SCALE),
                'net_variance_value' => $this->decimal($totals['net_variance_value'], self::VALUE_SCALE),
                'absolute_variance_value' => $this->decimal($totals['absolute_variance_value'], self::VALUE_SCALE),
                'metadata' => [
                    'calculation_reason' => $reason,
                    'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                    'canonical_movement_fingerprint' => $movementSet['fingerprint'],
                    'canonical_context' => $movementSet['context'],
                    'movement_date_from' => $periodFrom,
                    'movement_date_to' => $varianceDate,
                    'formula' => 'actual_qty - (canonical_opening_actual + canonical_goods_receipt + recipe_consumption_net)',
                    'inventory_adjustment_posted' => false,
                ],
                'calculated_by_user_id' => $userId,
                'calculated_at' => now(),
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);
            $variance->save();

            StockVarianceItem::query()->where('stock_variance_id', $variance->id)->delete();
            foreach ($rows as $row) {
                $variance->items()->create($row);
            }

            return $variance->fresh(['items', 'outlet', 'stockOpname', 'previousStockOpname']);
        });
    }

    public function submit(string $varianceId, ?string $userId, bool $confirmAttention = false): StockVariance
    {
        $variance = StockVariance::query()->with('stockOpname')->findOrFail($varianceId);
        if ($variance->status === StockVariance::STATUS_SUBMITTED) {
            return $variance->load('items');
        }

        $variance = $this->calculateForOpname((string) $variance->stock_opname_id, $userId, 'submit_refresh');

        return DB::transaction(function () use ($variance, $userId, $confirmAttention): StockVariance {
            $variance = StockVariance::query()->with('stockOpname')->lockForUpdate()->findOrFail($variance->id);
            if ($variance->status === StockVariance::STATUS_SUBMITTED) {
                return $variance->load('items');
            }
            if ($variance->stockOpname?->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => ['Stock Opname sumber tidak lagi berstatus submitted.'],
                ]);
            }

            $attention = (int) $variance->open_exception_count
                + (int) $variance->zero_cost_sku_count
                + (int) $variance->missing_opening_sku_count
                + (int) $variance->uncounted_movement_sku_count;

            if ($attention > 0 && ! $confirmAttention) {
                throw ValidationException::withMessages([
                    'confirm_attention' => [
                        'Variance memiliki perhatian data. Periksa exception recipe, harga nol, opening stock, dan SKU movement yang tidak dihitung sebelum submit.',
                    ],
                ]);
            }

            $metadata = $variance->metadata ?: [];
            $metadata['attention_confirmed'] = $attention > 0;
            $metadata['attention_confirmed_at'] = $attention > 0 ? now()->toIso8601String() : null;

            $variance->forceFill([
                'status' => StockVariance::STATUS_SUBMITTED,
                'metadata' => $metadata,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
            ])->save();

            return $variance->fresh(['items', 'outlet', 'stockOpname', 'previousStockOpname']);
        });
    }

    public function cancelForOpname(StockOpname|string $opname, string $reason = 'stock_opname_not_submitted'): ?StockVariance
    {
        $opnameId = $opname instanceof StockOpname ? (string) $opname->id : $opname;
        $variance = StockVariance::query()->where('stock_opname_id', $opnameId)->first();
        if (! $variance || $variance->status === StockVariance::STATUS_CANCELLED) {
            return $variance;
        }

        $variance->forceFill([
            'status' => StockVariance::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ])->save();

        StockVariance::query()
            ->where('previous_stock_opname_id', $opnameId)
            ->where('status', '!=', StockVariance::STATUS_CANCELLED)
            ->update([
                'status' => StockVariance::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'opening_stock_opname_cancelled:'.$opnameId,
                'updated_at' => now(),
            ]);

        return $variance;
    }

    private function costAtDate(string $outletId, string $skuId, string $date): array
    {
        $movement = DB::table('stk_inventory_movements')
            ->where('outlet_id', $outletId)
            ->where('sku_id', $skuId)
            ->where('business_date', '<=', $date)
            ->orderByDesc('business_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'average_cost_after']);

        if ($movement && (float) $movement->average_cost_after > 0) {
            return [
                'unit_cost' => (float) $movement->average_cost_after,
                'movement_id' => (string) $movement->id,
                'source' => 'inventory_movement_average',
                'source_id' => (string) $movement->id,
            ];
        }

        if (Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            $snapshotQuery = DB::table('cogs_purchasing_cost_snapshots')
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->where('receipt_date', '<=', $date);
            if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
                $snapshotQuery->where('is_canonical', true);
            }
            $snapshot = $snapshotQuery
                ->orderByDesc('receipt_date')
                ->orderByDesc('released_at')
                ->orderByDesc('id')
                ->first(['id', 'average_cost_after', 'unit_cost']);
            if ($snapshot) {
                $cost = (float) ($snapshot->average_cost_after ?: $snapshot->unit_cost);
                if ($cost > 0) {
                    return [
                        'unit_cost' => $cost,
                        'movement_id' => null,
                        'source' => 'purchasing_cost_snapshot',
                        'source_id' => (string) $snapshot->id,
                    ];
                }
            }
        }

        return [
            'unit_cost' => 0.0,
            'movement_id' => null,
            'source' => 'unavailable',
            'source_id' => null,
        ];
    }

    private function openExceptionCount(string $outletId, ?string $dateFrom, string $dateTo): int
    {
        if (! Schema::hasTable('cogs_sale_consumptions')) {
            return 0;
        }
        $query = DB::table('cogs_sale_consumptions')
            ->where('outlet_id', $outletId)
            ->where('movement_type', 'exception')
            ->where('status', 'open')
            ->where('business_date', '<=', $dateTo);
        if ($dateFrom) {
            $query->where('business_date', '>=', $dateFrom);
        }
        return $query->count();
    }

    private function submittedVarianceMatchesCurrentSource(StockVariance $variance, StockOpname $document): bool
    {
        if (! $document->submitted_at || ! $variance->calculated_at) {
            return false;
        }

        if (! $this->canonicalLedger->isCanonicalSubmittedOpname($document)) {
            return false;
        }

        if ((string) data_get($variance->metadata ?: [], 'engine', '') !== CanonicalStockVarianceLedgerService::ENGINE_VERSION) {
            return false;
        }

        $documentSubmittedAt = $document->submitted_at->getTimestamp();
        $calculatedAt = $variance->calculated_at->getTimestamp();
        if ($calculatedAt < $documentSubmittedAt) {
            return false;
        }

        $boundary = $this->canonicalLedger->resetBoundary((string) $document->outlet_id);
        if (! $boundary) {
            return true;
        }

        return $variance->calculated_at->greaterThan($boundary['executed_at']);
    }

    private function fingerprint(
        StockOpname $document,
        ?StockOpname $previous,
        array $rows,
        int $openExceptionCount,
        int $uncountedMovementSkuCount,
        string $canonicalMovementFingerprint,
    ): string {
        return hash('sha256', json_encode([
            'stock_opname_id' => (string) $document->id,
            'stock_opname_updated_at' => optional($document->updated_at)->toIso8601String(),
            'previous_stock_opname_id' => $previous ? (string) $previous->id : null,
            'previous_stock_opname_updated_at' => optional($previous?->updated_at)->toIso8601String(),
            'rows' => $rows,
            'canonical_movement_fingerprint' => $canonicalMovementFingerprint,
            'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
            'open_exception_count' => $openExceptionCount,
            'uncounted_movement_sku_count' => $uncountedMovementSkuCount,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function roundQty(float $value): float
    {
        return round($value, self::QUANTITY_SCALE);
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
