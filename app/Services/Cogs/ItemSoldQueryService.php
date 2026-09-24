<?php

namespace App\Services\Cogs;

use App\Models\Cogs\SaleConsumption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ItemSoldQueryService
{
    public function overview(string $outletId, array $filters): array
    {
        $posted = $this->activeConsumptionQuery($outletId, $filters);
        $currentItemSold = $this->currentItemSoldQuery($outletId, $filters);

        $summary = (clone $currentItemSold)
            ->selectRaw('COUNT(*) as item_line_count')
            ->selectRaw('COUNT(DISTINCT c.sale_id) as transaction_count')
            ->selectRaw('COALESCE(SUM(c.sold_quantity), 0) as sold_quantity')
            ->selectRaw("SUM(CASE WHEN c.movement_type = 'exception' THEN 1 ELSE 0 END) as exception_item_lines")
            ->first();

        $postedSummary = (clone $posted)
            ->selectRaw('COUNT(*) as posted_item_lines')
            ->selectRaw('COALESCE(SUM(c.total_base_quantity), 0) as ingredient_quantity')
            ->selectRaw('COALESCE(SUM(c.total_cost), 0) as estimate_cogs')
            ->first();

        $exceptionCount = (int) ($summary->exception_item_lines ?? 0);

        $reversalSummary = DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_REVERSAL)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->selectRaw('COUNT(*) as item_line_count')
            ->selectRaw('COALESCE(SUM(c.sold_quantity), 0) as sold_quantity')
            ->selectRaw('COALESCE(SUM(c.total_cost), 0) as reversed_cost')
            ->first();

        return [
            'period' => ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']],
            'cards' => [
                'transactions' => (int) ($summary->transaction_count ?? 0),
                'item_lines' => (int) ($summary->item_line_count ?? 0),
                'sold_quantity' => $this->decimal((float) ($summary->sold_quantity ?? 0), 4),
                'ingredient_quantity' => $this->decimal((float) ($postedSummary->ingredient_quantity ?? 0), 4),
                'estimate_cogs' => $this->decimal((float) ($postedSummary->estimate_cogs ?? 0), 2),
                'posted_item_lines' => (int) ($postedSummary->posted_item_lines ?? 0),
                'open_exceptions' => $exceptionCount,
                'reversed_item_lines' => (int) ($reversalSummary->item_line_count ?? 0),
                'reversed_quantity' => $this->decimal((float) ($reversalSummary->sold_quantity ?? 0), 4),
                'reversed_cost' => $this->decimal((float) ($reversalSummary->reversed_cost ?? 0), 2),
            ],
            'top_variants' => (clone $currentItemSold)
                ->select([
                    'c.product_id',
                    'c.product_variant_id',
                    'c.product_name_snapshot',
                    'c.variant_name_snapshot',
                ])
                ->selectRaw('SUM(c.sold_quantity) as sold_quantity')
                ->selectRaw('SUM(c.total_cost) as estimate_cogs')
                ->selectRaw('COUNT(DISTINCT c.sale_id) as transaction_count')
                ->groupBy('c.product_id', 'c.product_variant_id', 'c.product_name_snapshot', 'c.variant_name_snapshot')
                ->orderByDesc('sold_quantity')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'product_id' => (string) $row->product_id,
                    'product_variant_id' => (string) $row->product_variant_id,
                    'product_name' => (string) $row->product_name_snapshot,
                    'variant_name' => (string) $row->variant_name_snapshot,
                    'sold_quantity' => $this->decimal((float) $row->sold_quantity, 4),
                    'estimate_cogs' => $this->decimal((float) $row->estimate_cogs, 2),
                    'transaction_count' => (int) $row->transaction_count,
                ])->all(),
        ];
    }

    public function consumptions(string $outletId, array $filters): array
    {
        $query = DB::table('cogs_sale_consumptions as c')
            ->leftJoin('cogs_recipes as r', 'r.id', '=', 'c.recipe_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->select([
                'c.id', 'c.sale_id', 'c.sale_item_id', 'c.business_date', 'c.sale_number_snapshot',
                'c.product_id', 'c.product_variant_id', 'c.product_name_snapshot', 'c.variant_name_snapshot',
                'c.sold_quantity', 'c.total_base_quantity', 'c.total_cost', 'c.movement_count', 'c.status',
                'c.processed_at', 'c.reversed_at', 'c.event_reason', 'c.metadata',
                'r.version_no as recipe_version_no', 'r.effective_from as recipe_effective_from',
            ]);

        if (! empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('c.sale_number_snapshot', 'like', $search)
                    ->orWhere('c.product_name_snapshot', 'like', $search)
                    ->orWhere('c.variant_name_snapshot', 'like', $search);
            });
        }

        $paginator = $query
            ->orderByDesc('c.business_date')
            ->orderByDesc('c.processed_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->serializeConsumptionRow($row))->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function ingredientSummary(string $outletId, array $filters): array
    {
        $query = DB::table('cogs_sale_consumption_items as ci')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'ci.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']]);

        if (! empty($filters['q'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('ci.sku_code_snapshot', 'like', $search)
                    ->orWhere('ci.sku_name_snapshot', 'like', $search);
            });
        }

        $paginator = $query
            ->select([
                'ci.sku_id', 'ci.sku_code_snapshot', 'ci.sku_name_snapshot',
                'ci.base_uom_code_snapshot', 'ci.base_uom_symbol_snapshot',
            ])
            ->selectRaw('SUM(ci.quantity_base) as quantity_base')
            ->selectRaw('SUM(ci.total_cost) as total_cost')
            ->selectRaw('COUNT(DISTINCT c.sale_id) as transaction_count')
            ->selectRaw('COUNT(DISTINCT c.sale_item_id) as sale_item_count')
            ->selectRaw('MIN(CASE WHEN ci.unit_cost_snapshot > 0 THEN ci.unit_cost_snapshot ELSE NULL END) as minimum_unit_cost')
            ->selectRaw('MAX(CASE WHEN ci.unit_cost_snapshot > 0 THEN ci.unit_cost_snapshot ELSE NULL END) as maximum_unit_cost')
            ->groupBy(
                'ci.sku_id', 'ci.sku_code_snapshot', 'ci.sku_name_snapshot',
                'ci.base_uom_code_snapshot', 'ci.base_uom_symbol_snapshot',
            )
            ->orderByDesc('total_cost')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => [
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code_snapshot,
                'sku_name' => (string) $row->sku_name_snapshot,
                'uom_code' => (string) ($row->base_uom_code_snapshot ?? ''),
                'uom_symbol' => (string) ($row->base_uom_symbol_snapshot ?? $row->base_uom_code_snapshot ?? ''),
                'quantity_base' => $this->decimal((float) $row->quantity_base, 4),
                'total_cost' => $this->decimal((float) $row->total_cost, 2),
                'transaction_count' => (int) $row->transaction_count,
                'sale_item_count' => (int) $row->sale_item_count,
                'minimum_unit_cost' => $this->decimal((float) $row->minimum_unit_cost, 4),
                'maximum_unit_cost' => $this->decimal((float) $row->maximum_unit_cost, 4),
            ])->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function exceptions(string $outletId, array $filters): array
    {
        $query = DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_EXCEPTION)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->select([
                'c.id', 'c.sale_id', 'c.sale_item_id', 'c.business_date', 'c.sale_number_snapshot',
                'c.product_id', 'c.product_variant_id', 'c.product_name_snapshot', 'c.variant_name_snapshot',
                'c.sold_quantity', 'c.status', 'c.exception_code', 'c.exception_message', 'c.processed_at',
                'c.resolved_at', 'c.resolved_by_consumption_id', 'c.event_reason',
            ]);

        if (! empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (! empty($filters['exception_code'])) {
            $query->where('c.exception_code', $filters['exception_code']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('c.sale_number_snapshot', 'like', $search)
                    ->orWhere('c.product_name_snapshot', 'like', $search)
                    ->orWhere('c.variant_name_snapshot', 'like', $search)
                    ->orWhere('c.exception_message', 'like', $search);
            });
        }

        $paginator = $query
            ->orderByRaw("CASE WHEN c.status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('c.business_date')
            ->orderByDesc('c.processed_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => [
                'id' => (string) $row->id,
                'sale_id' => (string) $row->sale_id,
                'sale_item_id' => (string) $row->sale_item_id,
                'business_date' => (string) $row->business_date,
                'sale_number' => (string) ($row->sale_number_snapshot ?? ''),
                'product_id' => (string) $row->product_id,
                'product_variant_id' => (string) $row->product_variant_id,
                'product_name' => (string) $row->product_name_snapshot,
                'variant_name' => (string) $row->variant_name_snapshot,
                'sold_quantity' => $this->decimal((float) $row->sold_quantity, 4),
                'status' => (string) $row->status,
                'exception_code' => (string) ($row->exception_code ?? ''),
                'exception_message' => (string) ($row->exception_message ?? ''),
                'processed_at' => $row->processed_at,
                'resolved_at' => $row->resolved_at,
                'resolved_by_consumption_id' => $row->resolved_by_consumption_id ? (string) $row->resolved_by_consumption_id : null,
                'event_reason' => (string) ($row->event_reason ?? ''),
            ])->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function detail(string $id, string $outletId): ?array
    {
        $event = SaleConsumption::query()
            ->with(['items.movement', 'recipe', 'outlet'])
            ->where('outlet_id', $outletId)
            ->find($id);

        if (! $event) {
            return null;
        }

        return [
            'id' => (string) $event->id,
            'movement_type' => (string) $event->movement_type,
            'status' => (string) $event->status,
            'business_date' => optional($event->business_date)->format('Y-m-d'),
            'business_timezone' => (string) $event->business_timezone,
            'sale' => [
                'id' => (string) $event->sale_id,
                'sale_item_id' => (string) $event->sale_item_id,
                'number' => (string) $event->sale_number_snapshot,
                'status' => (string) $event->sale_status_snapshot,
                'sold_quantity' => (string) $event->sold_quantity,
            ],
            'product' => [
                'id' => (string) $event->product_id,
                'variant_id' => (string) $event->product_variant_id,
                'name' => (string) $event->product_name_snapshot,
                'variant_name' => (string) $event->variant_name_snapshot,
            ],
            'recipe' => $event->recipe ? [
                'id' => (string) $event->recipe->id,
                'version_no' => (int) $event->recipe->version_no,
                'yield_quantity' => (string) $event->recipe_yield_quantity,
                'effective_from' => optional($event->recipe->effective_from)->format('Y-m-d'),
                'effective_to' => optional($event->recipe->effective_to)->format('Y-m-d'),
            ] : null,
            'totals' => [
                'base_quantity' => (string) $event->total_base_quantity,
                'cost' => (string) $event->total_cost,
                'movement_count' => (int) $event->movement_count,
            ],
            'exception' => $event->movement_type === SaleConsumption::TYPE_EXCEPTION ? [
                'code' => (string) $event->exception_code,
                'message' => (string) $event->exception_message,
                'resolved_at' => optional($event->resolved_at)->toIso8601String(),
            ] : null,
            'metadata' => $event->metadata,
            'processed_at' => optional($event->processed_at)->toIso8601String(),
            'reversed_at' => optional($event->reversed_at)->toIso8601String(),
            'items' => $event->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) $item->sku_code_snapshot,
                'sku_name' => (string) $item->sku_name_snapshot,
                'uom_code' => (string) ($item->base_uom_code_snapshot ?? ''),
                'uom_symbol' => (string) ($item->base_uom_symbol_snapshot ?? $item->base_uom_code_snapshot ?? ''),
                'quantity_per_sold_base' => (string) $item->quantity_per_sold_base,
                'quantity_base' => (string) $item->quantity_base,
                'movement_quantity' => (string) $item->movement_quantity,
                'unit_cost' => (string) $item->unit_cost_snapshot,
                'total_cost' => (string) $item->total_cost,
                'balance_qty_before' => (string) $item->balance_qty_before,
                'balance_qty_after' => (string) $item->balance_qty_after,
                'average_cost_before' => (string) $item->average_cost_before,
                'average_cost_after' => (string) $item->average_cost_after,
                'inventory_value_before' => (string) $item->inventory_value_before,
                'inventory_value_after' => (string) $item->inventory_value_after,
                'movement_id' => $item->inventory_movement_id ? (string) $item->inventory_movement_id : null,
                'conversion_snapshot' => $item->conversion_snapshot,
                'metadata' => $item->metadata,
            ])->all(),
        ];
    }

    public function consumptionCsvRows(string $outletId, array $filters): iterable
    {
        $query = DB::table('cogs_sale_consumptions as c')
            ->leftJoin('cogs_recipes as r', 'r.id', '=', 'c.recipe_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->orderBy('c.business_date')
            ->orderBy('c.sale_number_snapshot')
            ->select([
                'c.business_date', 'c.sale_number_snapshot', 'c.sale_id', 'c.sale_item_id',
                'c.product_name_snapshot', 'c.variant_name_snapshot', 'c.sold_quantity',
                'r.version_no as recipe_version_no', 'c.status', 'c.total_base_quantity',
                'c.total_cost', 'c.movement_count', 'c.processed_at', 'c.reversed_at',
            ]);

        foreach ($query->cursor() as $row) {
            yield [
                (string) $row->business_date,
                (string) $row->sale_number_snapshot,
                (string) $row->sale_id,
                (string) $row->sale_item_id,
                (string) $row->product_name_snapshot,
                (string) $row->variant_name_snapshot,
                $this->decimal((float) $row->sold_quantity, 4),
                (string) ($row->recipe_version_no ?? ''),
                (string) $row->status,
                $this->decimal((float) $row->total_base_quantity, 4),
                $this->decimal((float) $row->total_cost, 2),
                (string) $row->movement_count,
                (string) ($row->processed_at ?? ''),
                (string) ($row->reversed_at ?? ''),
            ];
        }
    }

    public function ingredientCsvRows(string $outletId, array $filters): iterable
    {
        $query = DB::table('cogs_sale_consumption_items as ci')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'ci.consumption_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->select([
                'ci.sku_id', 'ci.sku_code_snapshot', 'ci.sku_name_snapshot',
                'ci.base_uom_code_snapshot', 'ci.base_uom_symbol_snapshot',
            ])
            ->selectRaw('SUM(ci.quantity_base) as quantity_base')
            ->selectRaw('SUM(ci.total_cost) as total_cost')
            ->selectRaw('COUNT(DISTINCT c.sale_id) as transaction_count')
            ->selectRaw('COUNT(DISTINCT c.sale_item_id) as sale_item_count')
            ->groupBy(
                'ci.sku_id', 'ci.sku_code_snapshot', 'ci.sku_name_snapshot',
                'ci.base_uom_code_snapshot', 'ci.base_uom_symbol_snapshot',
            )
            ->orderBy('ci.sku_name_snapshot');

        foreach ($query->cursor() as $row) {
            yield [
                (string) $row->sku_id,
                (string) $row->sku_code_snapshot,
                (string) $row->sku_name_snapshot,
                (string) ($row->base_uom_code_snapshot ?? ''),
                (string) ($row->base_uom_symbol_snapshot ?? ''),
                $this->decimal((float) $row->quantity_base, 4),
                $this->decimal((float) $row->total_cost, 2),
                (string) $row->transaction_count,
                (string) $row->sale_item_count,
            ];
        }
    }

    private function currentItemSoldQuery(string $outletId, array $filters): Builder
    {
        return DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']])
            ->where(function (Builder $query): void {
                $query->where(function (Builder $posted): void {
                    $posted->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
                        ->where('c.status', SaleConsumption::STATUS_POSTED);
                })->orWhere(function (Builder $exception): void {
                    $exception->where('c.movement_type', SaleConsumption::TYPE_EXCEPTION)
                        ->where('c.status', SaleConsumption::STATUS_OPEN);
                });
            });
    }

    private function activeConsumptionQuery(string $outletId, array $filters): Builder
    {
        return DB::table('cogs_sale_consumptions as c')
            ->where('c.outlet_id', $outletId)
            ->where('c.movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->where('c.status', SaleConsumption::STATUS_POSTED)
            ->whereBetween('c.business_date', [$filters['date_from'], $filters['date_to']]);
    }

    private function serializeConsumptionRow(object $row): array
    {
        $metadata = is_string($row->metadata ?? null)
            ? json_decode((string) $row->metadata, true)
            : (array) ($row->metadata ?? []);

        return [
            'id' => (string) $row->id,
            'sale_id' => (string) $row->sale_id,
            'sale_item_id' => (string) $row->sale_item_id,
            'business_date' => (string) $row->business_date,
            'sale_number' => (string) ($row->sale_number_snapshot ?? ''),
            'product_id' => (string) $row->product_id,
            'product_variant_id' => (string) $row->product_variant_id,
            'product_name' => (string) $row->product_name_snapshot,
            'variant_name' => (string) $row->variant_name_snapshot,
            'sold_quantity' => $this->decimal((float) $row->sold_quantity, 4),
            'recipe_version_no' => $row->recipe_version_no !== null ? (int) $row->recipe_version_no : null,
            'recipe_effective_from' => $row->recipe_effective_from ? (string) $row->recipe_effective_from : null,
            'total_base_quantity' => $this->decimal((float) $row->total_base_quantity, 4),
            'total_cost' => $this->decimal((float) $row->total_cost, 2),
            'movement_count' => (int) $row->movement_count,
            'status' => (string) $row->status,
            'processed_at' => $row->processed_at,
            'reversed_at' => $row->reversed_at,
            'event_reason' => (string) ($row->event_reason ?? ''),
            'warnings' => array_values((array) ($metadata['warnings'] ?? [])),
        ];
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
