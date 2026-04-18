<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListSalesSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleScopeCacheService;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Support\AnalyticsResponseCache;

class SalesSummaryController extends Controller
{
    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
    ) {
    }



    private function okCached($request, string $namespace, array $params, callable $callback)
    {
        @ini_set('max_execution_time', '240');
        @set_time_limit(240);

        $payload = AnalyticsResponseCache::remember(
            $namespace,
            $params,
            $callback,
            300,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        return ApiResponse::ok($payload, 'OK');
    }
    public function index(ListSalesSummaryRequest $request)
    {
        $validated = $request->validated();

        return $this->okCached($request, 'finance-sales-summary.index', $validated, function () use ($request, $validated) {
        $v = $validated;
        $sort = (string) ($v['sort'] ?? 'outlet_name');
        $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $isExport = filter_var($v['export'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

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
                    'gross_sales' => 0,
                    'discount' => 0,
                    'discount_display' => 0,
                    'net_sales' => 0,
                    'tax' => 0,
                    'rounding' => 0,
                    'total_collected' => 0,
                ],
                'filters' => [
                    'date_from' => $fromLocal->format('Y-m-d'),
                    'date_to' => $toLocal->format('Y-m-d'),
                    'outlet_filter' => $outletFilter['value'],
                    'sort' => $sort,
                    'dir' => $dir,
                ],
                'filter_options' => [
                    'outlet_filters' => $outletFilter['options'],
                ],
                'meta' => [
                    'timezone' => $timezone,
                    'outlet_scope_name' => $outletFilter['label'],
                    'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                    'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                    'generated_at' => null,
                ],
            ];
        }

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $v, $timezone);
        $rows = $this->buildRows($outletIds, $saleScope, $sort, $dir)->get();

        $items = $rows->map(function ($row) {
            $grossSales = (int) round((float) ($row->gross_sales ?? 0));
            $discount = (int) round((float) ($row->discount ?? 0));
            $netSales = (int) round((float) ($row->net_sales ?? ($grossSales - $discount)));
            $tax = (int) round((float) ($row->tax ?? 0));
            $rounding = (int) round((float) ($row->rounding ?? 0));
            $totalCollected = (int) round((float) ($row->total_collected ?? ($netSales + $tax + $rounding)));

            return [
                'outlet_id' => (string) ($row->outlet_id ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? '-'),
                'gross_sales' => $grossSales,
                'discount' => $discount,
                'discount_display' => $discount > 0 ? (-1 * $discount) : 0,
                'net_sales' => $netSales,
                'tax' => $tax,
                'rounding' => $rounding,
                'total_collected' => $totalCollected,
            ];
        })->values();

        $discountTotal = (int) $items->sum('discount');
        $summary = [
            'gross_sales' => (int) $items->sum('gross_sales'),
            'discount' => $discountTotal,
            'discount_display' => $discountTotal > 0 ? (-1 * $discountTotal) : 0,
            'net_sales' => (int) $items->sum('net_sales'),
            'tax' => (int) $items->sum('tax'),
            'rounding' => (int) $items->sum('rounding'),
            'total_collected' => (int) $items->sum('total_collected'),
        ];

        $payload = [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'date_from' => $fromLocal->format('Y-m-d'),
                'date_to' => $toLocal->format('Y-m-d'),
                'outlet_filter' => $outletFilter['value'],
                'sort' => $sort,
                'dir' => $dir,
            ],
            'filter_options' => [
                'outlet_filters' => $outletFilter['options'],
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_name' => $outletFilter['label'],
                'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
        ];

        if ($isExport) {
            $payload['export'] = [
                'filename' => $this->buildExportFilename((string) $outletFilter['label'], $fromLocal->format('Y-m-d'), $toLocal->format('Y-m-d')),
                'total_rows' => $items->count(),
                'columns' => ['Outlet Name', 'Gross Sales', 'Discount', 'Net Sales', 'Tax', 'Rounding', 'Total Collected'],
            ];
        }

        return $payload;
    
        });
    }

    private function buildRows(array $outletIds, array $saleScope, string $sort, string $dir): Builder
    {
        $aggSub = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds))
            ->when(!($saleScope['has_rows'] ?? false), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $this->cachedSaleIdSubquery($saleScope)));

        $aggSub
            ->groupBy('s.outlet_id')
            ->selectRaw('s.outlet_id as outlet_id')
            ->selectRaw('COALESCE(SUM(COALESCE(s.subtotal, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.discount_total, 0)), 0) as discount')
            ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(s.subtotal, 0) - COALESCE(s.discount_total, 0), 0)), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as tax')
            ->selectRaw('COALESCE(SUM(COALESCE(s.rounding_total, 0)), 0) as rounding')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as total_collected');

        $query = DB::table('outlets as o')
            ->leftJoinSub($aggSub, 'agg', fn ($join) => $join->on('agg.outlet_id', '=', 'o.id'))
            ->where('o.type', 'outlet')
            ->when(!empty($outletIds), fn ($builder) => $builder->whereIn('o.id', $outletIds))
            ->selectRaw('o.id as outlet_id')
            ->selectRaw('o.name as outlet_name')
            ->selectRaw('COALESCE(agg.gross_sales, 0) as gross_sales')
            ->selectRaw('COALESCE(agg.discount, 0) as discount')
            ->selectRaw('COALESCE(agg.net_sales, 0) as net_sales')
            ->selectRaw('COALESCE(agg.tax, 0) as tax')
            ->selectRaw('COALESCE(agg.rounding, 0) as rounding')
            ->selectRaw('COALESCE(agg.total_collected, 0) as total_collected');

        return $this->applySorting($query, $sort, $dir);
    }

    private function resolveEligibleSalesScope(array $outletIds, array $filters, string $timezone): array
    {
        return $this->reportSaleScopeCache->remember(
            'sales_summary_cashier_aligned',
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
            'gross_sales' => $query->orderBy('gross_sales', $dir)->orderBy('outlet_name'),
            'discount' => $query->orderBy('discount', $dir)->orderBy('outlet_name'),
            'net_sales' => $query->orderBy('net_sales', $dir)->orderBy('outlet_name'),
            'tax' => $query->orderBy('tax', $dir)->orderBy('outlet_name'),
            'rounding' => $query->orderBy('rounding', $dir)->orderBy('outlet_name'),
            'total_collected' => $query->orderBy('total_collected', $dir)->orderBy('outlet_name'),
            default => $query->orderBy('outlet_name', $dir),
        };
    }

    private function buildExportFilename(string $label, string $dateFrom, string $dateTo): string
    {
        $safe = trim(preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($label)), '_');
        return 'sales_summary_' . ($safe !== '' ? $safe : 'all_outlet') . '_' . $dateFrom . '_to_' . $dateTo . '.csv';
    }
}
