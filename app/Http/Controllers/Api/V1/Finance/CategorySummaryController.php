<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListCategorySummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleScopeCacheService;
use App\Support\FinanceCategorySegment;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CategorySummaryController extends Controller
{
    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
    ) {
    }

    public function index(ListCategorySummaryRequest $request)
    {
        $v = $request->validated();
        $sort = (string) ($v['sort'] ?? 'category_name');
        $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];
        $categorySegment = FinanceCategorySegment::normalize((string) ($v['category_segment'] ?? ''));

        $window = TransactionDate::businessDateWindow(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );
        [$fromLocal, $toLocal] = [$window['requested_from'], $window['requested_to']];

        if ($request->boolean('filters_only')) {
            return ApiResponse::ok([
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
                    'category_segment_placeholder' => false,
                    'cogs_source' => 'not_available',
                ],
            ], 'OK');
        }

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $v, $timezone);
        $rows = $this->buildRows($outletIds, $saleScope, $sort, $dir, $categorySegment)->get();

        $items = $rows->map(function ($row) {
            $grossSales = (int) round((float) ($row->gross_sales ?? 0));
            $discount = (int) round((float) ($row->discount ?? 0));
            $netSales = max(0, $grossSales - $discount);
            $cogs = 0;
            $grossProfit = $netSales - $cogs;
            $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;

            return [
                'category_id' => (string) ($row->category_id ?? ''),
                'category_name' => (string) ($row->category_name ?? '-'),
                'item_sold' => (int) ($row->item_sold ?? 0),
                'gross_sales' => $grossSales,
                'discount' => $discount,
                'net_sales' => $netSales,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'gross_margin' => $grossMargin,
            ];
        })->values();

        $totals = [
            'item_sold' => (int) $items->sum('item_sold'),
            'gross_sales' => (int) $items->sum('gross_sales'),
            'discount' => (int) $items->sum('discount'),
            'net_sales' => (int) $items->sum('net_sales'),
            'cogs' => 0,
            'gross_profit' => (int) $items->sum('gross_profit'),
            'gross_margin' => 0.0,
        ];
        $totals['gross_margin'] = $totals['net_sales'] > 0 ? round(($totals['gross_profit'] / $totals['net_sales']) * 100, 2) : 0.0;

        return ApiResponse::ok([
            'items' => $items,
            'summary' => $totals,
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
                'category_segment_placeholder' => false,
                'cogs_source' => 'not_available',
            ],
        ], 'OK');
    }

    private function buildRows(array $outletIds, array $saleScope, string $sort, string $dir, string $categorySegment): Builder
    {
        $salesTotalsSub = DB::table('sale_items as tsi')
            ->selectRaw('tsi.sale_id, COALESCE(SUM(tsi.line_total), 0) as items_gross_sales')
            ->whereNull('tsi.voided_at')
            ->when(!($saleScope['has_rows'] ?? false), fn ($builder) => $builder->whereRaw('1 = 0'), fn ($builder) => $builder->whereIn('tsi.sale_id', $this->cachedSaleIdSubquery($saleScope)))
            ->groupBy('tsi.sale_id');

        $aggSub = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($salesTotalsSub, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds))
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        FinanceCategorySegment::apply($aggSub, 'c.name', $categorySegment);

        $aggSub
            ->groupBy('p.category_id', 'c.name')
            ->selectRaw('COALESCE(p.category_id, ?) as category_id', [''])
            ->selectRaw('COALESCE(c.name, ?) as category_name', ['-'])
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0) as discount');

        $visibleCategories = DB::table('categories as c')
            ->selectRaw('COALESCE(c.id, ?) as category_id', [''])
            ->selectRaw('COALESCE(c.name, ?) as category_name', ['-']);

        FinanceCategorySegment::apply($visibleCategories, 'c.name', $categorySegment);

        $query = DB::query()
            ->fromSub($visibleCategories, 'vc')
            ->leftJoinSub($aggSub, 'agg', fn ($join) => $join->on('agg.category_id', '=', 'vc.category_id'))
            ->selectRaw('vc.category_id as category_id')
            ->selectRaw('COALESCE(vc.category_name, ?) as category_name', ['-'])
            ->selectRaw('COALESCE(agg.item_sold, 0) as item_sold')
            ->selectRaw('COALESCE(agg.gross_sales, 0) as gross_sales')
            ->selectRaw('COALESCE(agg.discount, 0) as discount');

        return $this->applySorting($query, $sort, $dir);
    }

    private function resolveEligibleSalesScope(array $outletIds, array $filters, string $timezone): array
    {
        return $this->reportSaleScopeCache->remember(
            'category_summary_cashier_aligned',
            [
                'outlet_ids' => array_values(array_unique(array_map('strval', $outletIds))),
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'timezone' => $timezone,
            ],
            fn () => $this->cashierAlignedSaleScope->eligibleSaleIds(
                $outletIds,
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null,
                $timezone
            )
        );
    }

    private function cachedSaleIdSubquery(array $saleScope): Builder
    {
        return $this->reportSaleScopeCache->subquery((string) ($saleScope['scope_key'] ?? ''));
    }

    private function applySorting(Builder $query, string $sort, string $dir): Builder
    {
        return match ($sort) {
            'item_sold' => $query->orderBy('item_sold', $dir)->orderBy('category_name'),
            'gross_sales' => $query->orderBy('gross_sales', $dir)->orderBy('category_name'),
            'discount' => $query->orderBy('discount', $dir)->orderBy('category_name'),
            'net_sales' => $query->orderByRaw('(COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) ' . strtoupper($dir))->orderBy('category_name'),
            'cogs' => $query->orderByRaw('0 ' . strtoupper($dir))->orderBy('category_name'),
            'gross_profit' => $query->orderByRaw('(COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) ' . strtoupper($dir))->orderBy('category_name'),
            'gross_margin' => $query->orderByRaw('(CASE WHEN (COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) > 0 THEN 100 ELSE 0 END) ' . strtoupper($dir))->orderBy('category_name'),
            default => $query->orderBy('category_name', $dir),
        };
    }
}
