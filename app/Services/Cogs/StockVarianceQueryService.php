<?php

namespace App\Services\Cogs;

use App\Models\Cogs\StockVariance;
use App\Models\StockInventory\StockOpname;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockVarianceQueryService
{
    public function __construct(private readonly CanonicalStockVarianceLedgerService $canonicalLedger)
    {
    }

    public function overview(string $outletId, array $filters): array
    {
        $query = $this->activeCanonicalVarianceQuery($outletId)
            ->whereBetween('variance_date', [$filters['date_from'], $filters['date_to']]);

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw("SUM(CASE WHEN cogs_stock_variances.status = 'submitted' THEN 1 ELSE 0 END) as submitted_documents")
            ->selectRaw("SUM(CASE WHEN cogs_stock_variances.status = 'calculated' THEN 1 ELSE 0 END) as calculated_documents")
            ->selectRaw('COALESCE(SUM(shortage_value), 0) as shortage_value')
            ->selectRaw('COALESCE(SUM(surplus_value), 0) as surplus_value')
            ->selectRaw('COALESCE(SUM(net_variance_value), 0) as net_variance_value')
            ->selectRaw('COALESCE(SUM(absolute_variance_value), 0) as absolute_variance_value')
            ->selectRaw('COALESCE(SUM(open_exception_count), 0) as open_exception_count')
            ->selectRaw('COALESCE(SUM(zero_cost_sku_count), 0) as zero_cost_sku_count')
            ->first();

        $boundary = $this->canonicalLedger->resetBoundary($outletId);
        $submittedOpnames = $this->canonicalOpnameQuery($outletId)
            ->whereBetween('opname_date', [$filters['date_from'], $filters['date_to']])
            ->count();

        $excludedPreReset = StockOpname::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'submitted')
            ->whereBetween('opname_date', [$filters['date_from'], $filters['date_to']]);
        $this->applyNonCanonicalBoundary($excludedPreReset, $boundary);

        return [
            'period' => $filters,
            'canonical_context' => [
                'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                'reset_boundary' => $boundary,
                'eligible_submitted_opnames' => $submittedOpnames,
                'excluded_pre_reset_submitted_opnames' => $boundary ? $excludedPreReset->count() : 0,
                'physical_gr_source' => 'ActualStockLedgerViewService.authoritativeTimeline',
                'consumption_source' => 'cogs_sale_consumption_items.movement_quantity',
            ],
            'cards' => [
                'submitted_opnames' => $submittedOpnames,
                'variance_documents' => (int) ($summary->documents ?? 0),
                'submitted_documents' => (int) ($summary->submitted_documents ?? 0),
                'calculated_documents' => (int) ($summary->calculated_documents ?? 0),
                'shortage_value' => $this->decimal((float) ($summary->shortage_value ?? 0), 2),
                'surplus_value' => $this->decimal((float) ($summary->surplus_value ?? 0), 2),
                'net_variance_value' => $this->decimal((float) ($summary->net_variance_value ?? 0), 2),
                'absolute_variance_value' => $this->decimal((float) ($summary->absolute_variance_value ?? 0), 2),
                'open_exception_count' => (int) ($summary->open_exception_count ?? 0),
                'zero_cost_sku_count' => (int) ($summary->zero_cost_sku_count ?? 0),
            ],
        ];
    }

    public function candidates(string $outletId, array $filters): array
    {
        $boundary = $this->canonicalLedger->resetBoundary($outletId);

        $rows = $this->canonicalOpnameQuery($outletId)
            ->leftJoin('cogs_stock_variances as variance', 'variance.stock_opname_id', '=', 'stk_stock_opnames.id')
            ->whereBetween('stk_stock_opnames.opname_date', [$filters['date_from'], $filters['date_to']])
            ->orderByDesc('stk_stock_opnames.opname_date')
            ->orderByDesc('stk_stock_opnames.submitted_at')
            ->get([
                'stk_stock_opnames.id',
                'stk_stock_opnames.opname_date',
                'stk_stock_opnames.submitted_at',
                'variance.id as variance_id',
                'variance.status as variance_status',
                'variance.calculated_at',
                'variance.submitted_at as variance_submitted_at',
                'variance.metadata as variance_metadata',
            ]);

        return $rows->map(function ($row) use ($boundary): array {
                $metadata = json_decode((string) ($row->variance_metadata ?? ''), true) ?: [];
                $varianceStatus = (string) ($row->variance_status ?? '');
                $engine = (string) data_get($metadata, 'engine', '');

                // A variance calculated by the old movement engine may be shown as
                // calculated, but it must be rebuilt before it can be submitted.
                $needsRebuild = $varianceStatus !== ''
                    && $varianceStatus !== StockVariance::STATUS_CANCELLED
                    && $engine !== CanonicalStockVarianceLedgerService::ENGINE_VERSION;

                return [
                    'stock_opname_id' => (string) $row->id,
                    'opname_date' => substr((string) $row->opname_date, 0, 10),
                    'opname_submitted_at' => $row->submitted_at,
                    'variance_id' => $row->variance_id ? (string) $row->variance_id : null,
                    'variance_status' => $needsRebuild ? 'needs_rebuild' : ($row->variance_status ?: null),
                    'stored_variance_status' => $row->variance_status ?: null,
                    'calculated_at' => $row->calculated_at,
                    'variance_submitted_at' => $row->variance_submitted_at,
                    'canonical_engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                    'needs_rebuild' => $needsRebuild,
                    'is_post_reset' => true,
                    'reset_boundary_at' => $boundary['executed_at'] ?? null,
                    'reset_boundary_date' => $boundary['business_date'] ?? null,
                ];
            })->all();
    }

    public function paginate(string $outletId, array $filters): array
    {
        $query = StockVariance::query()
            ->with(['stockOpname:id,outlet_id,opname_date,status,submitted_at', 'previousStockOpname:id,opname_date'])
            ->where('outlet_id', $outletId)
            ->whereBetween('variance_date', [$filters['date_from'], $filters['date_to']]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
            if ($filters['status'] !== StockVariance::STATUS_CANCELLED) {
                $this->applyCanonicalSourceRelation($query, $outletId);
            }
        } else {
            $query->where('status', '!=', StockVariance::STATUS_CANCELLED);
            $this->applyCanonicalSourceRelation($query, $outletId);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->whereHas('items', function ($items) use ($term): void {
                $items->where('sku_code_snapshot', 'like', $term)
                    ->orWhere('sku_name_snapshot', 'like', $term);
            });
        }

        $paginator = $query
            ->orderByDesc('variance_date')
            ->orderByDesc('calculated_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => collect($paginator->items())->map(fn (StockVariance $row) => $this->header($row))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'canonical_context' => [
                'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                'reset_boundary' => $this->canonicalLedger->resetBoundary($outletId),
            ],
        ];
    }

    public function detail(string $id, string $outletId): ?array
    {
        $variance = StockVariance::query()
            ->with([
                'outlet:id,code,name,timezone',
                'stockOpname:id,outlet_id,opname_date,status,submitted_at',
                'previousStockOpname:id,outlet_id,opname_date,status,submitted_at',
                'calculatedBy:id,name,nisj',
                'submittedBy:id,name,nisj',
                'items',
            ])
            ->where('outlet_id', $outletId)
            ->find($id);

        if (! $variance) {
            return null;
        }

        $canonicalSourceValid = $variance->stockOpname
            ? $this->canonicalLedger->isCanonicalSubmittedOpname($variance->stockOpname)
            : false;
        $itemTotals = [
            'goods_receipt_qty_total' => $this->decimal((float) $variance->items->sum('goods_receipt_qty'), 8),
            'sale_consumption_qty_total' => $this->decimal((float) $variance->items->sum('sale_consumption_qty'), 8),
            'other_movement_qty_total' => $this->decimal((float) $variance->items->sum('other_movement_qty'), 8),
        ];

        return [
            ...$this->header($variance),
            ...$itemTotals,
            'canonical_source_valid' => $canonicalSourceValid,
            'canonical_context' => [
                'engine' => CanonicalStockVarianceLedgerService::ENGINE_VERSION,
                'stored_engine' => (string) data_get($variance->metadata ?: [], 'engine', ''),
                'reset_boundary' => $this->canonicalLedger->resetBoundary($outletId),
                'source_opname_status' => $variance->stockOpname?->status,
                'source_opname_submitted_at' => optional($variance->stockOpname?->submitted_at)->toIso8601String(),
            ],
            'outlet' => $variance->outlet ? [
                'id' => (string) $variance->outlet->id,
                'code' => (string) ($variance->outlet->code ?? ''),
                'name' => (string) $variance->outlet->name,
                'timezone' => (string) ($variance->outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            ] : null,
            'source_fingerprint' => (string) $variance->source_fingerprint,
            'metadata' => $variance->metadata ?: [],
            'calculated_by' => $this->user($variance->calculatedBy),
            'submitted_by' => $this->user($variance->submittedBy),
            'items' => $variance->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'stock_opname_item_id' => (string) $item->stock_opname_item_id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) $item->sku_code_snapshot,
                'sku_name' => (string) $item->sku_name_snapshot,
                'base_uom_code' => (string) ($item->base_uom_code_snapshot ?? ''),
                'base_uom_symbol' => (string) ($item->base_uom_symbol_snapshot ?? $item->base_uom_code_snapshot ?? ''),
                'opening_actual_qty' => (string) $item->opening_actual_qty,
                'goods_receipt_qty' => (string) $item->goods_receipt_qty,
                'sale_consumption_qty' => (string) $item->sale_consumption_qty,
                'other_movement_qty' => (string) $item->other_movement_qty,
                'movement_qty' => (string) $item->movement_qty,
                'theoretical_qty' => (string) $item->theoretical_qty,
                'actual_qty' => (string) $item->actual_qty,
                'variance_qty' => (string) $item->variance_qty,
                'unit_cost' => (string) $item->unit_cost_snapshot,
                'shortage_value' => (string) $item->shortage_value,
                'surplus_value' => (string) $item->surplus_value,
                'net_variance_value' => (string) $item->net_variance_value,
                'movement_count' => (int) $item->movement_count,
                'warning_codes' => $item->warning_codes ?: [],
                'trace' => $item->trace_snapshot ?: [],
            ])->all(),
        ];
    }

    public function csvRows(string $outletId, array $filters): iterable
    {
        $query = DB::table('cogs_stock_variance_items as item')
            ->join('cogs_stock_variances as variance', 'variance.id', '=', 'item.stock_variance_id')
            ->join('stk_stock_opnames as opname', 'opname.id', '=', 'variance.stock_opname_id')
            ->where('variance.outlet_id', $outletId)
            ->whereBetween('variance.variance_date', [$filters['date_from'], $filters['date_to']])
            ->where('variance.status', '!=', StockVariance::STATUS_CANCELLED)
            ->where('opname.status', 'submitted');

        $boundary = $this->canonicalLedger->resetBoundary($outletId);
        if ($boundary) {
            $query->where('opname.submitted_at', '>', $boundary['executed_at'])
                ->where('opname.opname_date', '>=', $boundary['business_date']);
        }

        return $query
            ->orderBy('variance.variance_date')
            ->orderBy('item.sku_name_snapshot')
            ->cursor()
            ->map(fn ($row) => [
                $row->variance_date,
                $row->status,
                $row->opening_date,
                $row->sku_code_snapshot,
                $row->sku_name_snapshot,
                $row->base_uom_code_snapshot,
                $row->opening_actual_qty,
                $row->goods_receipt_qty,
                $row->sale_consumption_qty,
                $row->other_movement_qty,
                $row->movement_qty,
                $row->theoretical_qty,
                $row->actual_qty,
                $row->variance_qty,
                $row->unit_cost_snapshot,
                $row->shortage_value,
                $row->surplus_value,
                $row->net_variance_value,
                $row->movement_count,
                $row->warning_codes,
            ]);
    }

    /** @return Builder<StockOpname> */
    private function canonicalOpnameQuery(string $outletId): Builder
    {
        $query = StockOpname::query()
            ->where('stk_stock_opnames.outlet_id', $outletId)
            ->where('stk_stock_opnames.status', 'submitted')
            ->whereNotNull('stk_stock_opnames.submitted_at');

        $boundary = $this->canonicalLedger->resetBoundary($outletId);
        if ($boundary) {
            $query->where('stk_stock_opnames.submitted_at', '>', $boundary['executed_at'])
                ->where('stk_stock_opnames.opname_date', '>=', $boundary['business_date']);
        }

        return $query;
    }

    /** @return Builder<StockVariance> */
    private function activeCanonicalVarianceQuery(string $outletId): Builder
    {
        $query = StockVariance::query()
            ->where('cogs_stock_variances.outlet_id', $outletId)
            ->where('cogs_stock_variances.status', '!=', StockVariance::STATUS_CANCELLED);
        $this->applyCanonicalSourceRelation($query, $outletId);
        return $query;
    }

    /** @param Builder<StockVariance> $query */
    private function applyCanonicalSourceRelation(Builder $query, string $outletId): void
    {
        $boundary = $this->canonicalLedger->resetBoundary($outletId);
        $query->whereHas('stockOpname', function (Builder $opname) use ($boundary): void {
            $opname->where('status', 'submitted')->whereNotNull('submitted_at');
            if ($boundary) {
                $opname->where('submitted_at', '>', $boundary['executed_at'])
                    ->where('opname_date', '>=', $boundary['business_date']);
            }
        });
    }

    /** @param Builder<StockOpname> $query */
    private function applyNonCanonicalBoundary(Builder $query, ?array $boundary): void
    {
        if (! $boundary) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $stale) use ($boundary): void {
            $stale->whereNull('submitted_at')
                ->orWhere('submitted_at', '<=', $boundary['executed_at'])
                ->orWhereDate('opname_date', '<', $boundary['business_date']);
        });
    }

    private function header(StockVariance $row): array
    {
        $metadata = $row->metadata ?: [];
        return [
            'id' => (string) $row->id,
            'stock_opname_id' => (string) $row->stock_opname_id,
            'previous_stock_opname_id' => $row->previous_stock_opname_id ? (string) $row->previous_stock_opname_id : null,
            'variance_date' => $row->variance_date?->toDateString(),
            'opening_date' => $row->opening_date?->toDateString(),
            'status' => (string) $row->status,
            'engine' => (string) data_get($metadata, 'engine', ''),
            'is_canonical_engine' => (string) data_get($metadata, 'engine', '') === CanonicalStockVarianceLedgerService::ENGINE_VERSION,
            'source_opname_status' => $row->stockOpname?->status,
            'sku_count' => (int) $row->sku_count,
            'shortage_sku_count' => (int) $row->shortage_sku_count,
            'surplus_sku_count' => (int) $row->surplus_sku_count,
            'zero_cost_sku_count' => (int) $row->zero_cost_sku_count,
            'missing_opening_sku_count' => (int) $row->missing_opening_sku_count,
            'open_exception_count' => (int) $row->open_exception_count,
            'uncounted_movement_sku_count' => (int) $row->uncounted_movement_sku_count,
            'opening_qty_total' => (string) $row->opening_qty_total,
            'movement_qty_total' => (string) $row->movement_qty_total,
            'theoretical_qty_total' => (string) $row->theoretical_qty_total,
            'actual_qty_total' => (string) $row->actual_qty_total,
            'variance_qty_total' => (string) $row->variance_qty_total,
            'shortage_value' => (string) $row->shortage_value,
            'surplus_value' => (string) $row->surplus_value,
            'net_variance_value' => (string) $row->net_variance_value,
            'absolute_variance_value' => (string) $row->absolute_variance_value,
            'attention_count' => (int) $row->zero_cost_sku_count
                + (int) $row->missing_opening_sku_count
                + (int) $row->open_exception_count
                + (int) $row->uncounted_movement_sku_count,
            'calculated_at' => optional($row->calculated_at)->toIso8601String(),
            'submitted_at' => optional($row->submitted_at)->toIso8601String(),
            'cancelled_at' => optional($row->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $row->cancellation_reason,
        ];
    }

    private function user($user): ?array
    {
        if (! $user) {
            return null;
        }
        return [
            'id' => (string) $user->id,
            'name' => (string) ($user->name ?? $user->nisj ?? '-'),
            'nisj' => (string) ($user->nisj ?? ''),
        ];
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
