<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListItemSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\ReportDailySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\FinanceCategorySegment;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ItemSummaryController extends Controller
{
    public function __construct(
        private readonly ReportDailySummaryService $dailySummaryService,
    ) {
    }

    private function okCached($request, string $namespace, array $params, callable $callback)
    {
        @ini_set('max_execution_time', '240');
        @set_time_limit(240);

        return AnalyticsResponseCache::remember(
            $namespace,
            $params,
            $callback,
            300,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );
    }

    public function index(ListItemSummaryRequest $request)
    {
        $validated = $request->validated();

        return ApiResponse::ok($this->okCached($request, 'finance-item-summary.index', $validated, function () use ($request, $validated) {
            $v = $validated;
            $sort = (string) ($v['sort'] ?? 'category_name');
            $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
            $timezone = $outletFilter['timezone'];
            $outletIds = array_values(array_unique(array_map('strval', $outletFilter['outlet_ids'] ?? [])));
            $categorySegment = FinanceCategorySegment::normalize((string) ($v['category_segment'] ?? ''));

            $window = TransactionDate::businessDateWindow(
                $v['date_from'] ?? null,
                $v['date_to'] ?? null,
                $timezone
            );
            [$fromLocal, $toLocal] = [$window['requested_from'], $window['requested_to']];

            if ($request->boolean('filters_only')) {
                return [
                    'items' => [],
                    'summary' => [
                        'item_sold' => 0,
                        'gross_sales' => 0,
                        'discount' => 0,
                        'net_sales' => 0,
                        'cogs' => 0,
                        'gross_profit' => 0,
                        'gross_margin' => 0.0,
                    ],
                    'filters' => [
                        'date_from' => $fromLocal->format('Y-m-d'),
                        'date_to' => $toLocal->format('Y-m-d'),
                        'outlet_filter' => $outletFilter['value'],
                        'category_segment' => $categorySegment,
                        'sort' => $sort,
                        'dir' => $dir,
                    ],
                    'filter_options' => [
                        'outlet_filters' => $outletFilter['options'],
                        'category_segments' => FinanceCategorySegment::options(),
                    ],
                    'meta' => [
                        'timezone' => $timezone,
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'generated_at' => null,
                        'category_segment_active' => $categorySegment,
                        'category_segment_label' => FinanceCategorySegment::label($categorySegment),
                        'bar_category_names' => FinanceCategorySegment::barCategoryNames(),
                        'cogs_source' => 'not_available',
                    ],
                ];
            }

            if ($outletIds !== []) {
                $this->dailySummaryService->ensureCoverage($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);
            }

            $rows = $this->buildRows($outletIds, $v, $sort, $dir, $categorySegment)->get();
            $modifierMap = $this->buildSelectedModifierMap($rows, $outletIds, $v, $timezone, $categorySegment);

            $items = $rows->map(function ($row) use ($modifierMap) {
                $grossSales = (int) round((float) ($row->gross_sales ?? 0));
                $discount = (int) round((float) ($row->discount ?? 0));
                $netSales = max(0, $grossSales - $discount);
                $cogs = 0;
                $grossProfit = $netSales - $cogs;
                $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;

                return [
                    'row_key' => (string) ($row->row_key ?? ''),
                    'item_name' => (string) ($row->item_name ?? '-'),
                    'variant_name' => (string) ($row->variant_name ?? '-'),
                    'item_name_variant' => (string) ($row->item_name_variant ?? '-'),
                    'category_id' => (string) ($row->category_id ?? ''),
                    'category_name' => (string) ($row->category_name ?? '-'),
                    'modifier' => $modifierMap[(string) ($row->row_key ?? '')] ?? '-',
                    'item_sold' => (int) ($row->item_sold ?? 0),
                    'gross_sales' => $grossSales,
                    'discount' => $discount,
                    'net_sales' => $netSales,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit,
                    'gross_margin' => $grossMargin,
                ];
            })->values();

            $summary = [
                'item_sold' => (int) $items->sum('item_sold'),
                'gross_sales' => (int) $items->sum('gross_sales'),
                'discount' => (int) $items->sum('discount'),
                'net_sales' => (int) $items->sum('net_sales'),
                'cogs' => (int) $items->sum('cogs'),
                'gross_profit' => (int) $items->sum('gross_profit'),
                'gross_margin' => 0.0,
            ];
            $summary['gross_margin'] = $summary['net_sales'] > 0 ? round(($summary['gross_profit'] / $summary['net_sales']) * 100, 2) : 0.0;

            return [
                'items' => $items,
                'summary' => $summary,
                'filters' => [
                    'date_from' => $fromLocal->format('Y-m-d'),
                    'date_to' => $toLocal->format('Y-m-d'),
                    'outlet_filter' => $outletFilter['value'],
                    'category_segment' => $categorySegment,
                    'sort' => $sort,
                    'dir' => $dir,
                ],
                'filter_options' => [
                    'outlet_filters' => $outletFilter['options'],
                    'category_segments' => FinanceCategorySegment::options(),
                ],
                'meta' => [
                    'timezone' => $timezone,
                    'outlet_scope_name' => $outletFilter['label'],
                    'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                    'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                    'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    'category_segment_active' => $categorySegment,
                    'category_segment_label' => FinanceCategorySegment::label($categorySegment),
                    'bar_category_names' => FinanceCategorySegment::barCategoryNames(),
                    'cogs_source' => 'not_available',
                ],
            ];
        }), 'OK');
    }

    private function buildSelectedModifierMap($rows, array $outletIds, array $filters, string $timezone, string $categorySegment): array
    {
        $rowKeys = $rows->pluck('row_key')->filter()->map(fn ($key) => (string) $key)->unique()->values();
        if ($rowKeys->isEmpty() || $outletIds === []) {
            return [];
        }

        $window = TransactionDate::businessDateWindow(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );

        // Backoffice-only fix:
        // Modifier summary must read what was actually saved in checkout history.
        // Existing checkout already stores free-text modifier/item note in sale_items.note,
        // while selected paid/free add-ons are stored in sale_item_addons.addon_name.
        // Start from sale_items and LEFT JOIN addons so note-only items are not dropped.
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('report_sale_business_dates as rsbd', function ($join) {
                $join->on('rsbd.sale_id', '=', 's.id')
                    ->on('rsbd.outlet_id', '=', 's.outlet_id');
            })
            ->leftJoin('sale_item_addons as sia', 'sia.sale_item_id', '=', 'si.id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->whereNull('si.voided_at')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->whereBetween('rsbd.business_date', [
                $window['requested_from']->format('Y-m-d'),
                $window['requested_to']->format('Y-m-d'),
            ])
            ->where(function ($query) use ($rowKeys) {
                foreach ($rowKeys as $rowKey) {
                    [$productId, $variantId] = array_pad(explode(':', (string) $rowKey, 2), 2, '');
                    $query->orWhere(function ($sub) use ($productId, $variantId) {
                        $sub->where('si.product_id', $productId === '' ? null : $productId);
                        if ($variantId === '') {
                            $sub->where(function ($variant) {
                                $variant->whereNull('si.variant_id')->orWhere('si.variant_id', '');
                            });
                        } else {
                            $sub->where('si.variant_id', $variantId);
                        }
                    });
                }
            })
            ->where(function ($query) {
                $query->whereNotNull('sia.addon_name')
                    ->orWhere(function ($noteQuery) {
                        $noteQuery->whereNotNull('si.note')
                            ->whereRaw("TRIM(COALESCE(si.note, '')) <> ''");
                    });
            });

        FinanceCategorySegment::apply($query, 'c.name', $categorySegment);

        $modifierRows = $query
            ->selectRaw("CONCAT(COALESCE(si.product_id, ''), ':', COALESCE(si.variant_id, '')) as row_key")
            ->selectRaw('sia.addon_name as addon_name')
            ->selectRaw('si.note as item_note')
            ->groupBy('row_key', 'sia.addon_name', 'si.note')
            ->orderBy('sia.addon_name')
            ->orderBy('si.note')
            ->get();

        $map = [];
        foreach ($modifierRows as $modifier) {
            $rowKey = (string) ($modifier->row_key ?? '');
            if ($rowKey === '') {
                continue;
            }

            $addonName = $this->normalizeModifierText((string) ($modifier->addon_name ?? ''));
            $itemNote = $this->normalizeModifierText((string) ($modifier->item_note ?? ''));

            foreach ([$addonName, $itemNote] as $name) {
                if ($name === '') {
                    continue;
                }
                $map[$rowKey][$name] = $name;
            }
        }

        foreach ($map as $rowKey => $names) {
            $map[$rowKey] = implode(' | ', array_values($names));
        }

        return $map;
    }

    private function normalizeModifierText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, 255);
    }

    private function buildRows(array $outletIds, array $filters, string $sort, string $dir, string $categorySegment): Builder
    {
        $query = $this->dailySummaryService
            ->variantSummaryQuery($outletIds, $filters['date_from'] ?? null, $filters['date_to'] ?? null, $categorySegment)
            ->groupBy('rdvar.product_id', 'rdvar.variant_id', 'rdvar.product_name', 'rdvar.variant_name', 'rdvar.category_id', 'rdvar.category_name')
            ->selectRaw("CONCAT(COALESCE(rdvar.product_id, ''), ':', COALESCE(rdvar.variant_id, '')) as row_key")
            ->selectRaw('rdvar.product_id as product_id')
            ->selectRaw('rdvar.variant_id as variant_id')
            ->selectRaw('rdvar.product_name as item_name')
            ->selectRaw('rdvar.variant_name as variant_name')
            ->selectRaw("CASE WHEN COALESCE(rdvar.variant_name, '') = '' THEN rdvar.product_name ELSE CONCAT(rdvar.product_name, ' - ', rdvar.variant_name) END as item_name_variant")
            ->selectRaw('COALESCE(rdvar.category_id, ?) as category_id', [''])
            ->selectRaw('COALESCE(rdvar.category_name, ?) as category_name', ['-'])
            ->selectRaw('COALESCE(SUM(rdvar.item_sold), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(rdvar.gross_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(ROUND(SUM(rdvar.discount_basis), 0), 0) as discount')
            ->selectRaw('0 as cogs');

        return $this->applySorting($query, $sort, $dir);
    }

    private function applySorting(Builder $query, string $sort, string $dir): Builder
    {
        return match ($sort) {
            'item_name' => $query->orderBy('item_name_variant', $dir)->orderBy('category_name')->orderBy('item_name'),
            'item_sold' => $query->orderBy('item_sold', $dir)->orderBy('category_name')->orderBy('item_name_variant'),
            'gross_sales' => $query->orderBy('gross_sales', $dir)->orderBy('category_name')->orderBy('item_name_variant'),
            'discount' => $query->orderBy('discount', $dir)->orderBy('category_name')->orderBy('item_name_variant'),
            'net_sales' => $query->orderByRaw('(COALESCE(SUM(rdvar.gross_sales), 0) - COALESCE(ROUND(SUM(rdvar.discount_basis), 0), 0)) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'cogs' => $query->orderByRaw('0 ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'gross_profit' => $query->orderByRaw('(COALESCE(SUM(rdvar.gross_sales), 0) - COALESCE(ROUND(SUM(rdvar.discount_basis), 0), 0)) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'gross_margin' => $query->orderByRaw('(CASE WHEN (COALESCE(SUM(rdvar.gross_sales), 0) - COALESCE(ROUND(SUM(rdvar.discount_basis), 0), 0)) > 0 THEN 100 ELSE 0 END) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            default => $query->orderBy('category_name', $dir)->orderBy('item_name_variant', 'asc'),
        };
    }
}
