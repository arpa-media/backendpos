<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListItemSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\CashierAlignedSaleScopeService;
use App\Support\FinanceCategorySegment;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ItemSummaryController extends Controller
{
    public function __construct(private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope)
    {
    }

    public function index(ListItemSummaryRequest $request)
    {
        $v = $request->validated();
        $sort = (string) ($v['sort'] ?? 'category_name');
        $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $categorySegment = FinanceCategorySegment::normalize((string) ($v['category_segment'] ?? ''));

        $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        $window = TransactionDate::businessDateWindow(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = [$window['requested_from'], $window['requested_to'], $window['from_utc'], $window['to_utc']];

        $eligibleSaleIds = $this->cashierAlignedSaleScope->eligibleSaleIds($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);

        $rows = $this->buildRows($outletIds, $eligibleSaleIds, $v, $timezone, $sort, $dir, $categorySegment)->get();

        $items = $rows->map(function ($row) {
            $grossSales = (int) round((float) ($row->gross_sales ?? 0));
            $discount = (int) round((float) ($row->discount ?? 0));
            $netSales = max(0, $grossSales - $discount);
            $cogs = (int) round((float) ($row->cogs ?? 0));
            $grossProfit = $netSales - $cogs;
            $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;

            return [
                'row_key' => (string) ($row->row_key ?? ''),
                'item_name' => (string) ($row->item_name ?? '-'),
                'variant_name' => (string) ($row->variant_name ?? '-'),
                'item_name_variant' => (string) ($row->item_name_variant ?? '-'),
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

        return ApiResponse::ok([
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
        ], 'OK');
    }

    private function buildRows(array $outletIds, array $eligibleSaleIds, array $filters, string $timezone, string $sort, string $dir, string $categorySegment): Builder
    {
        $salesTotalsSub = DB::table('sale_items as tsi')
            ->selectRaw('tsi.sale_id, COALESCE(SUM(tsi.line_total), 0) as items_gross_sales')
            ->whereNull('tsi.voided_at')
            ->when(!empty($eligibleSaleIds), fn ($builder) => $builder->whereIn('tsi.sale_id', $eligibleSaleIds), fn ($builder) => $builder->whereRaw('1 = 0'))
            ->groupBy('tsi.sale_id');

        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($salesTotalsSub, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($builder) => $builder->whereIn('s.outlet_id', $outletIds))
            ->when(!empty($eligibleSaleIds), fn ($builder) => $builder->whereIn('s.id', $eligibleSaleIds), fn ($builder) => $builder->whereRaw('1 = 0'));

        FinanceCategorySegment::apply($query, 'c.name', $categorySegment);

        $query
            ->groupBy('si.product_id', 'si.variant_id', 'si.product_name', 'si.variant_name', 'p.category_id', 'c.name')
            ->selectRaw("CONCAT(COALESCE(si.product_id, ''), ':', COALESCE(si.variant_id, '')) as row_key")
            ->selectRaw('si.product_name as item_name')
            ->selectRaw('si.variant_name as variant_name')
            ->selectRaw("CASE WHEN COALESCE(si.variant_name, '') = '' THEN si.product_name ELSE CONCAT(si.product_name, ' - ', si.variant_name) END as item_name_variant")
            ->selectRaw('COALESCE(p.category_id, ?) as category_id', [''])
            ->selectRaw('COALESCE(c.name, ?) as category_name', ['-'])
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0) as discount')
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
            'net_sales' => $query->orderByRaw('(COALESCE(SUM(si.line_total), 0) - COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0)) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'cogs' => $query->orderByRaw('0 ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'gross_profit' => $query->orderByRaw('(COALESCE(SUM(si.line_total), 0) - COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0)) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            'gross_margin' => $query->orderByRaw('(CASE WHEN (COALESCE(SUM(si.line_total), 0) - COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0)) > 0 THEN 100 ELSE 0 END) ' . strtoupper($dir))->orderBy('category_name')->orderBy('item_name_variant'),
            default => $query->orderBy('category_name', $dir)->orderBy('item_name_variant', 'asc'),
        };
    }

}
