<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\InventoryMovement;
use App\Models\StockInventory\ParStock;
use App\Models\StockInventory\StockOpname;
use App\Models\StockInventory\StockSku;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockSnapshotService
{
    public function __construct(private readonly ActualStockLedgerViewService $actualStock)
    {
    }

    /**
     * Build a normalized stock snapshot for one outlet.
     *
     * Source-of-truth priority for Actual Qty:
     * 1. Current date: authoritative Warehouse GR + submitted Stock Opname documents.
     * 2. Historical date: latest inventory movement balance up to the requested date.
     * 3. Historical compatibility fallback: latest Stock Opname item.
     *
     * Current aggregate balances remain useful for valuation/audit, but they are
     * intentionally not the quantity source because legacy COGS logic may have
     * polluted them.
     *
     * @return array<string, mixed>
     */
    public function build(string $outletId, ?string $asOfDate = null, bool $onlyConfigured = false, bool $submittedOnly = false): array
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $date = CarbonImmutable::parse($asOfDate ?: $today, 'Asia/Jakarta')->toDateString();
        $isCurrentSnapshot = $date >= $today;
        $hardReset = $this->latestHardReset($outletId);
        $isLegacyHistoricalCutoff = $hardReset !== null
            && $date <= CarbonImmutable::parse($hardReset['executed_at'])->toDateString();

        $opnameQuery = StockOpname::query()
            ->with('items')
            ->where('outlet_id', $outletId)
            ->whereDate('opname_date', '<=', $date)
            ->where('status', '<>', 'reset');

        if ($hardReset !== null) {
            $opnameQuery->where(function ($query) use ($hardReset): void {
                $query->whereNull('submitted_at')
                    ->orWhere('submitted_at', '>', $hardReset['executed_at']);
            });
        }

        if ($submittedOnly) {
            $opnameQuery->where('status', 'submitted');
        }

        $latestOpname = $opnameQuery
            ->orderByDesc('opname_date')
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
            ->first();

        $opnameActualBySku = $latestOpname
            ? $latestOpname->items->keyBy(fn ($item) => (string) $item->sku_id)
            : collect();

        $parBySku = ParStock::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn ($item) => (string) $item->sku_id);

        $openingBySku = Schema::hasTable('stk_opening_stocks')
            ? DB::table('stk_opening_stocks')->where('outlet_id', $outletId)->get()->keyBy(fn ($item) => (string) $item->sku_id)
            : collect();

        $skuQuery = StockSku::query()
            ->with(['category', 'baseUom', 'purchaseUom'])
            ->where('is_active', true)
            ->orderBy('name');

        if ($onlyConfigured) {
            $skuQuery->whereIn('id', $parBySku->keys()->all());
        }

        $skus = $skuQuery->get();
        $skuIds = $skus->pluck('id')->map(fn ($id) => (string) $id)->all();

        // Current Actual Stock is rebuilt from authoritative business documents,
        // not from aggregate balances that may contain legacy COGS consumption.
        $currentAuthoritativeBySku = $this->actualStock->stateMap($outletId);
        $authoritativeBySku = $isCurrentSnapshot ? $currentAuthoritativeBySku : collect();

        // Used for historical snapshots and as a compatibility fallback when a
        // current aggregate balance row has not been generated yet.
        $movementBalanceBySku = collect();
        if ($skuIds !== [] && ! $isLegacyHistoricalCutoff) {
            $movementQuery = InventoryMovement::query()
                ->where('outlet_id', $outletId)
                ->whereIn('sku_id', $skuIds)
                ->whereDate('business_date', '<=', $date);

            if ($hardReset !== null) {
                $movementQuery->where('created_at', '>', $hardReset['executed_at']);
            }

            $movementBalanceBySku = $movementQuery
                ->orderBy('sku_id')
                ->orderByDesc('business_date')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'sku_id', 'business_date', 'balance_qty_after', 'created_at'])
                ->unique(fn (InventoryMovement $movement) => (string) $movement->sku_id)
                ->keyBy(fn (InventoryMovement $movement) => (string) $movement->sku_id);
        }

        $rows = $skus->map(function (StockSku $sku) use (
            $parBySku,
            $openingBySku,
            $opnameActualBySku,
            $authoritativeBySku,
            $currentAuthoritativeBySku,
            $movementBalanceBySku,
            $isCurrentSnapshot,
            $isLegacyHistoricalCutoff,
            $hardReset,
            $date
        ) {
            $skuId = (string) $sku->id;
            $par = $parBySku->get($skuId);
            $opening = $openingBySku->get($skuId);
            $opnameItem = $opnameActualBySku->get($skuId);
            $authoritative = $authoritativeBySku->get($skuId);
            $currentAuthoritative = $currentAuthoritativeBySku->get($skuId);
            $movement = $movementBalanceBySku->get($skuId);
            $currentStockQty = round((float) ($currentAuthoritative['qty'] ?? 0), 4);

            $parQty = $par ? (float) $par->par_qty : null;
            $minimumQty = $par ? (float) $par->minimum_qty : null;

            $baseUom = $sku->baseUom;
            $purchaseUom = $sku->purchaseUom ?: $baseUom;
            $baseUomId = (string) $sku->base_uom_id;
            $purchaseUomId = $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : $baseUomId;
            $purchaseFactor = round((float) ($sku->purchase_conversion_factor ?: 1), 8);
            if ($purchaseUomId === $baseUomId || $purchaseFactor <= 0) {
                $purchaseFactor = 1.0;
            }
            $baseUomCode = (string) ($baseUom?->code ?: $baseUom?->symbol ?: 'UNIT');
            $purchaseUomCode = (string) ($purchaseUom?->code ?: $purchaseUom?->symbol ?: $baseUomCode);

            $actualQty = null;
            $actualSource = 'unavailable';
            $actualAsOf = null;

            if ($isLegacyHistoricalCutoff) {
                $actualQty = 0.0;
                $actualSource = 'hard_reset_zero';
                $actualAsOf = $hardReset['executed_at'] ?? $date;
            } elseif ($isCurrentSnapshot) {
                // No authoritative document means opening/current stock is zero.
                // Completed Warehouse GR is visible immediately even when an old
                // aggregate balance row was not posted correctly.
                $actualQty = $currentStockQty;
                $actualSource = 'authoritative_stock_documents';
                $actualAsOf = $authoritative['last_event_at'] ?? $authoritative['last_business_date'] ?? $date;
            } elseif ($movement) {
                $actualQty = (float) $movement->balance_qty_after;
                $actualSource = 'inventory_movement';
                $actualAsOf = $movement->created_at?->toIso8601String()
                    ?: $movement->business_date?->toDateString()
                    ?: $date;
            } elseif ($opnameItem) {
                $actualQty = (float) $opnameItem->actual_qty;
                $actualSource = 'stock_opname';
                $actualAsOf = $opnameItem->opname?->opname_date?->toDateString();
            } elseif ($opening && (string) $opening->effective_date <= $date) {
                $actualQty = (float) $opening->opening_qty;
                $actualSource = 'opening_stock';
                $actualAsOf = (string) $opening->effective_date;
            }

            $recommendedQty = ($parQty !== null && $actualQty !== null)
                ? max(round($parQty - $actualQty, 4), 0)
                : 0;

            return [
                'sku_id' => $skuId,
                'sku_code' => (string) $sku->sku_code,
                'sku_name' => (string) $sku->name,
                'category_id' => (string) $sku->category_id,
                'category_name' => (string) ($sku->category?->name ?? '-'),
                'uom_id' => $baseUomId,
                'uom_name' => (string) ($baseUom?->name ?? '-'),
                'uom_symbol' => (string) ($baseUom?->symbol ?: $baseUomCode),
                'uom_code' => $baseUomCode,
                'decimal_places' => (int) ($baseUom?->decimal_places ?? 2),
                'base_uom' => [
                    'id' => $baseUomId,
                    'code' => $baseUomCode,
                    'name' => (string) ($baseUom?->name ?? '-'),
                    'symbol' => (string) ($baseUom?->symbol ?: $baseUomCode),
                    'decimal_places' => (int) ($baseUom?->decimal_places ?? 2),
                ],
                'purchase_uom' => [
                    'id' => $purchaseUomId,
                    'code' => $purchaseUomCode,
                    'name' => (string) ($purchaseUom?->name ?? '-'),
                    'symbol' => (string) ($purchaseUom?->symbol ?: $purchaseUomCode),
                    'decimal_places' => (int) ($purchaseUom?->decimal_places ?? $baseUom?->decimal_places ?? 2),
                ],
                'purchase_conversion_factor' => $purchaseFactor,
                'uom_conversion_label' => sprintf('1 %s = %s %s', $purchaseUomCode, $this->formatFactor($purchaseFactor), $baseUomCode),
                'par_stock_id' => $par ? (string) $par->id : null,
                'par_qty' => $parQty,
                'par_qty_purchase_uom' => $parQty === null ? null : round($parQty / $purchaseFactor, 4),
                'minimum_qty' => $minimumQty,
                'minimum_qty_purchase_uom' => $minimumQty === null ? null : round($minimumQty / $purchaseFactor, 4),
                'initial_stock_qty' => $opening ? (float) $opening->opening_qty : 0.0,
                'initial_stock_qty_purchase_uom' => $opening ? round((float) $opening->opening_qty / $purchaseFactor, 4) : 0.0,
                'initial_unit_cost' => $opening ? (float) $opening->unit_cost : 0.0,
                'initial_inventory_value' => $opening ? (float) $opening->inventory_value : 0.0,
                'initial_effective_date' => $opening ? (string) $opening->effective_date : $date,
                'opening_stock_locked' => ($currentAuthoritative['last_event_type'] ?? null) !== null
                    && ($currentAuthoritative['last_event_type'] ?? null) !== 'opening_stock',
                'current_stock_qty' => $currentStockQty,
                'current_stock_qty_purchase_uom' => round($currentStockQty / $purchaseFactor, 4),
                'current_stock_source' => 'actual_stock_authoritative',
                'current_stock_as_of' => $currentAuthoritative['last_event_at'] ?? $currentAuthoritative['last_business_date'] ?? $date,
                'actual_qty' => $actualQty,
                'actual_qty_purchase_uom' => $actualQty === null ? null : round($actualQty / $purchaseFactor, 4),
                'actual_source' => $actualSource,
                'actual_as_of' => $actualAsOf,
                'recommended_request_qty' => $recommendedQty,
                'status' => $this->status($parQty, $minimumQty, $actualQty),
            ];
        })->values();

        return [
            'as_of_date' => $date,
            'actual_source_policy' => $isLegacyHistoricalCutoff
                ? 'hard_reset_zero_legacy_cutoff'
                : ($isCurrentSnapshot
                    ? 'opening_stock_plus_warehouse_gr_plus_submitted_opname_authoritative'
                    : 'post_reset_movement_then_opname'),
            'hard_reset' => $hardReset,
            'latest_opname' => $latestOpname ? [
                'id' => (string) $latestOpname->id,
                'date' => $latestOpname->opname_date?->toDateString(),
                'status' => (string) $latestOpname->status,
                'submitted_at' => $latestOpname->submitted_at?->toIso8601String(),
            ] : null,
            'summary' => $this->summary($rows),
            'items' => $rows->all(),
        ];
    }

    /** @return array<string,string>|null */
    private function latestHardReset(string $outletId): ?array
    {
        if (! Schema::hasTable('stk_actual_stock_reset_runs')) {
            return null;
        }

        $row = DB::table('stk_actual_stock_reset_runs')
            ->where('outlet_id', $outletId)
            ->where('mode', 'reset_opnames_zero')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->first(['id', 'executed_at']);

        if (! $row || ! $row->executed_at) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'executed_at' => (string) $row->executed_at,
        ];
    }

    private function formatFactor(float $value): string
    {
        $formatted = number_format($value, 8, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    private function status(?float $parQty, ?float $minimumQty, ?float $actualQty): string
    {
        if ($parQty === null) {
            return 'unconfigured';
        }
        if ($actualQty === null) {
            return 'not_counted';
        }
        if ($minimumQty !== null && $actualQty <= $minimumQty) {
            return 'critical';
        }
        if ($actualQty < $parQty) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function summary(Collection $rows): array
    {
        return [
            'sku_total' => $rows->count(),
            'par_configured' => $rows->whereNotNull('par_stock_id')->count(),
            'counted' => $rows->whereNotNull('actual_qty')->count(),
            'healthy' => $rows->where('status', 'healthy')->count(),
            'below_par' => $rows->whereIn('status', ['warning', 'critical'])->count(),
            'below_minimum' => $rows->where('status', 'critical')->count(),
            'not_counted' => $rows->where('status', 'not_counted')->count(),
            'unconfigured' => $rows->where('status', 'unconfigured')->count(),
            'recommended_request_lines' => $rows->filter(fn ($row) => (float) ($row['recommended_request_qty'] ?? 0) > 0)->count(),
        ];
    }
}
