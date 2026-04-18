<?php

namespace App\Services;

use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Services\CashierAlignedSaleScopeService;
use App\Models\Sale;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class OwnerOverviewService
{
    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
        private readonly ReportSaleBusinessDateIndexService $businessDateIndex,
        private readonly ReportDailySummaryService $dailySummaryService,
    ) {
    }


    public function saleDetail(array $params, string $saleId): array
    {
        $scope = $this->detailScope($params);
        if (empty($scope['allowed_outlet_ids'])) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'User tidak memiliki outlet aktif untuk detail sales Owner Overview.',
                'error_code' => 'OWNER_OVERVIEW_OUTLET_FORBIDDEN',
            ];
        }

        $timezone = $this->resolveTimezone($scope['selected_outlet_id'] ?? null);
        [, , $fromQuery, $toQuery] = TransactionDate::dateRange(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone,
        );

        $saleScope = $this->resolveEligibleSalesScope($scope['allowed_outlet_ids'], $params, $timezone);
        if (! ($saleScope['has_rows'] ?? false)) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada filter Owner Overview ini.',
                'error_code' => 'OWNER_OVERVIEW_SALE_NOT_FOUND',
            ];
        }

        $visible = DB::table('report_sale_scope_cache as rssc')
            ->where('rssc.scope_key', (string) $saleScope['scope_key'])
            ->where('rssc.sale_id', $saleId)
            ->where('rssc.expires_at', '>', now())
            ->exists();

        if (! $visible) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada filter Owner Overview ini.',
                'error_code' => 'OWNER_OVERVIEW_SALE_NOT_FOUND',
            ];
        }

        $sale = Sale::query()
            ->with(['outlet', 'items.product.category', 'items.addons', 'payments', 'customer', 'cancelRequests'])
            ->where('id', $saleId)
            ->whereNull('deleted_at')
            ->where('status', '=', 'PAID')
            ->whereIn('outlet_id', $scope['allowed_outlet_ids']);

        if (! empty($scope['selected_outlet_id'])) {
            $sale->where('outlet_id', '=', $scope['selected_outlet_id']);
        }

        $sale = $sale->first();
        if (! $sale) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada filter Owner Overview ini.',
                'error_code' => 'OWNER_OVERVIEW_SALE_NOT_FOUND',
            ];
        }

        return [
            'ok' => true,
            'scope' => $scope,
            'sale' => $this->normalizeSaleDetailPayload($this->transformSaleDetail($sale)),
        ];
    }

    public function detailScope(array $params): array
    {
        $outletFilter = $this->resolveOutletFilter($params);
        $allowedOutlets = $this->resolveAllowedOutlets($outletFilter['outlet_ids'] ?? []);
        $selectedFilterValue = (string) ($outletFilter['value'] ?? FinanceOutletFilter::FILTER_ALL);
        $selectedOutlet = null;

        if ($this->isSingleOutletFilter($selectedFilterValue)) {
            $selectedOutlet = collect($allowedOutlets)->first(fn (array $outlet) => (string) ($outlet['id'] ?? '') === $selectedFilterValue);
        }

        return [
            'portal_code' => 'owner-overview',
            'portal_name' => 'Owner Overview',
            'mode' => 'owner_overview',
            'marking_rule' => 'ignore_marking',
            'marked_only' => false,
            'filter_value' => $selectedFilterValue,
            'filter_label' => (string) ($outletFilter['label'] ?? 'All Outlet'),
            'allowed_outlets' => $allowedOutlets,
            'allowed_outlet_ids' => array_values(array_map(fn (array $outlet) => (string) ($outlet['id'] ?? ''), $allowedOutlets)),
            'selected_outlet_id' => $selectedOutlet ? (string) ($selectedOutlet['id'] ?? '') : null,
            'selected_outlet_code' => $selectedOutlet ? (string) ($selectedOutlet['code'] ?? '') : $selectedFilterValue,
            'selected_outlet_name' => $selectedOutlet ? (string) ($selectedOutlet['name'] ?? '') : (string) ($outletFilter['label'] ?? 'All Outlet'),
            'uses_all_outlets' => ! $this->isSingleOutletFilter($selectedFilterValue),
        ];
    }

    public function overview(array $params): array
    {
        $outletFilter = $this->resolveOutletFilter($params);
        $timezone = (string) ($outletFilter['timezone'] ?? config('app.timezone', 'Asia/Jakarta'));
        $outletIds = array_values(array_filter(array_map(fn ($id) => (string) $id, $outletFilter['outlet_ids'] ?? [])));
        [$fromLocal, $toLocal] = TransactionDate::dateRange(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone,
        );

        $topLimit = max(1, min(10, (int) ($params['top_limit'] ?? 5)));
        $recentLimit = max(1, min(20, (int) ($params['recent_limit'] ?? 10)));
        $allowedOutlets = $this->resolveAllowedOutlets($outletIds);

        if ($outletIds === []) {
            return $this->emptyOverviewPayload($fromLocal->toDateString(), $toLocal->toDateString(), $outletFilter, $timezone, $allowedOutlets, $recentLimit);
        }

        $this->dailySummaryService->ensureCoverage($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null, $timezone);

        $summaryRow = $this->dailySummaryService
            ->salesSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('COALESCE(SUM(rdss.trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(rdss.grand_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(rdss.marked_grand_sales), 0) as marking_gross_sales')
            ->selectRaw('COALESCE(SUM(rdss.tax_total), 0) as tax_total')
            ->first();

        if ((int) ($summaryRow->trx_count ?? 0) <= 0) {
            return $this->emptyOverviewPayload($fromLocal->toDateString(), $toLocal->toDateString(), $outletFilter, $timezone, $allowedOutlets, $recentLimit);
        }

        $paymentRows = $this->dailySummaryService
            ->paymentSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdps.outlet_id')
            ->selectRaw('rdps.payment_method_name')
            ->selectRaw('rdps.payment_method_type')
            ->selectRaw('COALESCE(SUM(rdps.trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(rdps.gross_sales), 0) as gross_sales')
            ->groupBy('rdps.outlet_id', 'rdps.payment_method_name', 'rdps.payment_method_type')
            ->get();

        $cashSalesByOutlet = [];
        $paymentAccumulator = [];
        foreach ($paymentRows as $row) {
            $outletId = (string) ($row->outlet_id ?? '');
            $grossSales = (int) ($row->gross_sales ?? 0);
            $trxCount = (int) ($row->trx_count ?? 0);
            $paymentName = (string) ($row->payment_method_name ?? '-');
            $paymentType = (string) ($row->payment_method_type ?? '');
            $paymentKey = mb_strtolower($paymentName) . '|' . mb_strtolower($paymentType);

            if (! isset($paymentAccumulator[$paymentKey])) {
                $paymentAccumulator[$paymentKey] = [
                    'payment_method_name' => $paymentName,
                    'payment_method_type' => $paymentType,
                    'trx_count' => 0,
                    'gross_sales' => 0,
                ];
            }
            $paymentAccumulator[$paymentKey]['trx_count'] += $trxCount;
            $paymentAccumulator[$paymentKey]['gross_sales'] += $grossSales;

            if ($this->isCashPaymentLabel($paymentName, $paymentType)) {
                $cashSalesByOutlet[$outletId] = ($cashSalesByOutlet[$outletId] ?? 0) + $grossSales;
            }
        }

        $metrics = [
            'gross_sales' => (int) ($summaryRow->gross_sales ?? 0),
            'marking_gross_sales' => (int) ($summaryRow->marking_gross_sales ?? 0),
            'cash_sales' => array_sum($cashSalesByOutlet),
            'tax_total' => (int) ($summaryRow->tax_total ?? 0),
        ];

        $outletAccumulator = [];
        foreach ($allowedOutlets as $outlet) {
            $outletAccumulator[(string) ($outlet['id'] ?? '')] = [
                'outlet_id' => (string) ($outlet['id'] ?? ''),
                'outlet_code' => (string) ($outlet['code'] ?? ''),
                'outlet_name' => (string) ($outlet['name'] ?? '-'),
                'trx_count' => 0,
                'gross_sales' => 0,
                'marking_gross_sales' => 0,
                'cash_sales' => 0,
                'tax_total' => 0,
            ];
        }

        $outletRows = $this->dailySummaryService
            ->salesSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->join('outlets as o', 'o.id', '=', 'rdss.outlet_id')
            ->selectRaw('rdss.outlet_id as outlet_id')
            ->selectRaw('o.code as outlet_code')
            ->selectRaw('o.name as outlet_name')
            ->selectRaw('COALESCE(SUM(rdss.trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(rdss.grand_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(rdss.marked_grand_sales), 0) as marking_gross_sales')
            ->selectRaw('COALESCE(SUM(rdss.tax_total), 0) as tax_total')
            ->groupBy('rdss.outlet_id', 'o.code', 'o.name')
            ->get();

        foreach ($outletRows as $row) {
            $outletKey = (string) ($row->outlet_id ?? '');
            $outletAccumulator[$outletKey] = [
                'outlet_id' => $outletKey,
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
                'marking_gross_sales' => (int) ($row->marking_gross_sales ?? 0),
                'cash_sales' => (int) ($cashSalesByOutlet[$outletKey] ?? 0),
                'tax_total' => (int) ($row->tax_total ?? 0),
            ];
        }

        $outletSalesSummary = collect(array_values($outletAccumulator))
            ->sortBy(fn (array $row) => mb_strtolower($row['outlet_name']))
            ->values()
            ->all();

        $sortGrossThenLabel = function (array $left, array $right, string $labelKey): int {
            return ($right['gross_sales'] <=> $left['gross_sales'])
                ?: (($right['trx_count'] ?? 0) <=> ($left['trx_count'] ?? 0))
                ?: strcmp((string) ($left[$labelKey] ?? ''), (string) ($right[$labelKey] ?? ''));
        };

        $byChannel = $this->dailySummaryService
            ->channelSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdcs.display_channel as channel')
            ->selectRaw('COALESCE(SUM(rdcs.trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(rdcs.gross_sales), 0) as gross_sales')
            ->groupBy('rdcs.display_channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => (string) ($row->channel ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();
        usort($byChannel, fn (array $left, array $right) => $sortGrossThenLabel($left, $right, 'channel'));

        $byPaymentMethod = array_values($paymentAccumulator);
        usort($byPaymentMethod, fn (array $left, array $right) => $sortGrossThenLabel($left, $right, 'payment_method_name'));

        $categorySummary = $this->dailySummaryService
            ->categorySummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdcat.category_name as category_name')
            ->selectRaw('COALESCE(SUM(rdcat.item_sold), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(rdcat.gross_sales), 0) as gross_sales')
            ->groupBy('rdcat.category_name')
            ->get()
            ->map(fn ($row) => [
                'category_name' => (string) ($row->category_name ?? 'Uncategorized'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();
        usort($categorySummary, function (array $left, array $right) {
            return ($right['gross_sales'] <=> $left['gross_sales'])
                ?: (($right['qty_sold'] ?? 0) <=> ($left['qty_sold'] ?? 0))
                ?: strcmp((string) ($left['category_name'] ?? ''), (string) ($right['category_name'] ?? ''));
        });

        $topVariants = $this->dailySummaryService
            ->variantSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdvar.product_name')
            ->selectRaw('rdvar.variant_name')
            ->selectRaw('COALESCE(SUM(rdvar.item_sold), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(rdvar.gross_sales), 0) as revenue')
            ->groupBy('rdvar.product_name', 'rdvar.variant_name')
            ->orderByDesc('qty_sold')
            ->orderByDesc('revenue')
            ->orderBy('rdvar.product_name')
            ->orderBy('rdvar.variant_name')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? '-'),
                'variant_name' => (string) ($row->variant_name ?? '-'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $topProducts = $this->dailySummaryService
            ->productSummaryQuery($outletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdprod.product_name')
            ->selectRaw('COALESCE(SUM(rdprod.item_sold), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(rdprod.gross_sales), 0) as revenue')
            ->groupBy('rdprod.product_name')
            ->orderByDesc('qty_sold')
            ->orderByDesc('revenue')
            ->orderBy('rdprod.product_name')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? '-'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $params, $timezone);
        $recentSales = $this->ownerOverviewRecentSalesQuery((string) ($saleScope['scope_key'] ?? ''), $outletIds, $recentLimit)
            ->get()
            ->map(fn ($row) => $this->mapSaleListRow($row))
            ->values()
            ->all();

        return [
            'filters' => [
                'date_from' => $fromLocal->toDateString(),
                'date_to' => $toLocal->toDateString(),
                'outlet_id' => (string) ($outletFilter['value'] ?? FinanceOutletFilter::FILTER_ALL),
                'outlet_filter' => (string) ($outletFilter['value'] ?? FinanceOutletFilter::FILTER_ALL),
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_name' => (string) ($outletFilter['label'] ?? 'All Outlet'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
            'metrics' => [
                'gross_sales' => (int) ($metrics['gross_sales'] ?? 0),
                'marking_gross_sales' => (int) ($metrics['marking_gross_sales'] ?? 0),
                'cash_sales' => (int) ($metrics['cash_sales'] ?? 0),
                'tax_total' => (int) ($metrics['tax_total'] ?? 0),
            ],
            'breakdowns' => [
                'by_channel' => $byChannel,
                'by_payment_method' => $byPaymentMethod,
            ],
            'summaries' => [
                'outlet_sales' => $outletSalesSummary,
                'category_summary' => $categorySummary,
            ],
            'top_items' => [
                'variants' => $topVariants,
                'products' => $topProducts,
            ],
            'recent_sales' => [
                'items' => $recentSales,
                'meta' => [
                    'limit' => $recentLimit,
                    'total' => count($recentSales),
                ],
            ],
        ];
    }

    private function ownerOverviewMetricsQuery(string $scopeKey, array $outletIds): Builder
    {
        $query = DB::table('sales as s')
            ->join('report_sale_scope_cache as rssc', function ($join) use ($scopeKey) {
                $join->on('rssc.sale_id', '=', 's.id')
                    ->where('rssc.scope_key', '=', $scopeKey)
                    ->where('rssc.expires_at', '>', now());
            })
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletScope($query, $outletIds, 's');

        return $query
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(CAST(s.marking AS SIGNED), 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as marking_gross_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as tax_total');
    }

    private function ownerOverviewOutletSummaryQuery(string $scopeKey, array $outletIds): Builder
    {
        $query = DB::table('sales as s')
            ->join('report_sale_scope_cache as rssc', function ($join) use ($scopeKey) {
                $join->on('rssc.sale_id', '=', 's.id')
                    ->where('rssc.scope_key', '=', $scopeKey)
                    ->where('rssc.expires_at', '>', now());
            })
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletScope($query, $outletIds, 's');

        return $query
            ->selectRaw('s.outlet_id as outlet_id')
            ->selectRaw('MAX(o.code) as outlet_code')
            ->selectRaw('MAX(o.name) as outlet_name')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(CAST(s.marking AS SIGNED), 0) = 1 THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as marking_gross_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as tax_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN COALESCE(payments.has_payment_rows, 0) > 0 THEN COALESCE(payments.cash_amount, 0) WHEN LOWER(TRIM(COALESCE(s.payment_method_name, ''))) IN ('cash', 'tunai') OR LOWER(TRIM(COALESCE(s.payment_method_type, ''))) IN ('cash', 'tunai') THEN COALESCE(s.grand_total, 0) ELSE 0 END), 0) as cash_sales")
            ->groupBy('s.outlet_id');
    }

    private function ownerOverviewChannelRowsQuery(string $scopeKey, array $outletIds): Builder
    {
        $query = DB::table('sales as s')
            ->join('report_sale_scope_cache as rssc', function ($join) use ($scopeKey) {
                $join->on('rssc.sale_id', '=', 's.id')
                    ->where('rssc.scope_key', '=', $scopeKey)
                    ->where('rssc.expires_at', '>', now());
            })
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletScope($query, $outletIds, 's');

        return $query
            ->selectRaw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) as display_channel")
            ->selectRaw('COALESCE(s.grand_total, 0) as grand_total');
    }

    private function ownerOverviewRecentSalesQuery(string $scopeKey, array $outletIds, int $recentLimit): Builder
    {
        $query = DB::table('sales as s')
            ->join('report_sale_scope_cache as rssc', function ($join) use ($scopeKey) {
                $join->on('rssc.sale_id', '=', 's.id')
                    ->where('rssc.scope_key', '=', $scopeKey)
                    ->where('rssc.expires_at', '>', now());
            })
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletScope($query, $outletIds, 's');

        return $query
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id')
            ->limit($recentLimit)
            ->select([
                's.id as sale_id',
                's.outlet_id as outlet_id',
                's.sale_number',
                's.channel',
                's.online_order_source',
                's.payment_method_name',
                's.payment_method_type',
                's.subtotal',
                's.discount_total',
                's.service_charge_total',
                DB::raw('COALESCE(s.grand_total, 0) as grand_total'),
                DB::raw('COALESCE(s.tax_total, 0) as tax_total'),
                DB::raw('COALESCE(s.rounding_total, 0) as rounding_total'),
                's.paid_total',
                's.change_total',
                's.marking',
                's.created_at',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.timezone as outlet_timezone',
                DB::raw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) as display_channel"),
                DB::raw("COALESCE(NULLIF(payments.payment_method_display, ''), NULLIF(payments.payment_method_names, ''), NULLIF(s.payment_method_name, ''), '-') as payment_method_display"),
                DB::raw('COALESCE(payments.cash_amount, 0) as payment_cash_amount'),
                DB::raw('COALESCE(payments.has_payment_rows, 0) as has_payment_rows'),
            ]);
    }

    private function emptyOverviewPayload(string $dateFrom, string $dateTo, array $outletFilter, string $timezone, array $allowedOutlets, int $recentLimit): array
    {
        $outletSalesSummary = collect($allowedOutlets)
            ->map(fn (array $outlet) => [
                'outlet_id' => (string) ($outlet['id'] ?? ''),
                'outlet_code' => (string) ($outlet['code'] ?? ''),
                'outlet_name' => (string) ($outlet['name'] ?? '-'),
                'trx_count' => 0,
                'gross_sales' => 0,
                'marking_gross_sales' => 0,
                'cash_sales' => 0,
                'tax_total' => 0,
            ])
            ->sortBy(fn (array $row) => mb_strtolower($row['outlet_name']))
            ->values()
            ->all();

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'outlet_id' => (string) ($outletFilter['value'] ?? FinanceOutletFilter::FILTER_ALL),
                'outlet_filter' => (string) ($outletFilter['value'] ?? FinanceOutletFilter::FILTER_ALL),
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_name' => (string) ($outletFilter['label'] ?? 'All Outlet'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
            'metrics' => [
                'gross_sales' => 0,
                'marking_gross_sales' => 0,
                'cash_sales' => 0,
                'tax_total' => 0,
            ],
            'breakdowns' => [
                'by_channel' => [],
                'by_payment_method' => [],
            ],
            'summaries' => [
                'outlet_sales' => $outletSalesSummary,
                'category_summary' => [],
            ],
            'top_items' => [
                'variants' => [],
                'products' => [],
            ],
            'recent_sales' => [
                'items' => [],
                'meta' => [
                    'limit' => $recentLimit,
                    'total' => 0,
                ],
            ],
        ];
    }


    private function resolveEligibleSalesScope(array $outletIds, array $params, string $timezone): array
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return [
                'scope_key' => 'owner-overview:empty',
                'has_rows' => false,
            ];
        }

        return $this->reportSaleScopeCache->rememberSubquery(
            'owner-overview.sales-scope',
            [
                'outlets' => $normalizedOutletIds,
                'date_from' => (string) ($params['date_from'] ?? ''),
                'date_to' => (string) ($params['date_to'] ?? ''),
                'timezone' => $timezone,
            ],
            $this->businessDateIndex->saleIdsSubquery(
                $normalizedOutletIds,
                $params['date_from'] ?? null,
                $params['date_to'] ?? null,
                false,
                $timezone,
            ),
            20,
        );
    }

    /**
     * @return array<int, array{id:string,code:string,name:string,type:string,timezone:string}>
     */
    private function resolveAllowedOutlets(array $outletIds): array
    {
        $query = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet']);

        if (! empty($outletIds)) {
            $query->whereIn('id', $outletIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'timezone'])
            ->map(fn ($outlet) => [
                'id' => (string) ($outlet->id ?? ''),
                'code' => (string) ($outlet->code ?? ''),
                'name' => (string) ($outlet->name ?? '-'),
                'type' => (string) ($outlet->type ?? 'outlet'),
                'timezone' => TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta'))),
            ])
            ->values()
            ->all();
    }

    private function resolveOutletFilter(array $params): array
    {
        $rawFilter = $params['outlet_filter'] ?? $params['outlet_id'] ?? FinanceOutletFilter::FILTER_ALL;
        $normalized = $this->normalizeOutletFilter($rawFilter);

        return FinanceOutletFilter::resolve($normalized);
    }

    private function normalizeOutletFilter($value): string
    {
        $raw = strtoupper(trim((string) ($value ?? '')));
        if ($raw === '' || $raw === 'ALL') {
            return FinanceOutletFilter::FILTER_ALL;
        }

        if ($raw === 'PT_BKJB' || $raw === FinanceOutletFilter::FILTER_GROUP_BKJB) {
            return FinanceOutletFilter::FILTER_GROUP_BKJB;
        }

        if ($raw === 'PT_MDMF' || $raw === FinanceOutletFilter::FILTER_GROUP_MDMF) {
            return FinanceOutletFilter::FILTER_GROUP_MDMF;
        }

        return trim((string) ($value ?? ''));
    }

    private function isSingleOutletFilter(?string $value): bool
    {
        $raw = trim((string) ($value ?? ''));

        return $raw !== ''
            && strtoupper($raw) !== FinanceOutletFilter::FILTER_ALL
            && strtoupper($raw) !== FinanceOutletFilter::FILTER_GROUP_BKJB
            && strtoupper($raw) !== FinanceOutletFilter::FILTER_GROUP_MDMF;
    }


    private function resolveTimezone(?string $outletId): string
    {
        if (! $outletId) {
            return TransactionDate::normalizeTimezone(config('app.timezone', 'Asia/Jakarta'));
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone((string) ($timezone ?: config('app.timezone', 'Asia/Jakarta')));
    }

    private function applyOutletScope(Builder $query, array $outletIds, string $saleAlias = 's'): void
    {
        if (empty($outletIds)) {
            return;
        }

        $query->whereIn($saleAlias . '.outlet_id', $outletIds);
    }

    private function applyOutletRowScope(Builder $query, array $outletIds, string $outletColumn = 'o.id'): void
    {
        if (empty($outletIds)) {
            return;
        }

        $query->whereIn($outletColumn, $outletIds);
    }


    private function applyBusinessDateScopeToEloquent($query, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, ?string $timezone = null, string $saleNumberColumn = 'sale_number', string $createdAtColumn = 'created_at'): void
    {
        TransactionDate::applyExactBusinessDateScope(
            $query,
            $createdAtColumn,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone,
            $saleNumberColumn
        );
    }

    private function applyBusinessDateScope(Builder $query, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, ?string $timezone = null, string $saleNumberColumn = 's.sale_number', string $createdAtColumn = 's.created_at'): void
    {
        TransactionDate::applyExactBusinessDateScope(
            $query,
            $createdAtColumn,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone,
            $saleNumberColumn
        );
    }


    private function resolvePaymentBreakdown(array $saleScope, array $outletIds): array
    {
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');
        if ($scopeKey === '' || ! ($saleScope['has_rows'] ?? false)) {
            return [];
        }

        $normalizedPaymentRows = DB::table('sale_payments as sp')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'sp.sale_id'))
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->when(! empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds))
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.name), ''), NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw("CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE COALESCE(sp.amount, 0) END as gross_sales");

        $paymentRows = DB::query()
            ->fromSub($normalizedPaymentRows, 'payment_rows')
            ->selectRaw('payment_method_name')
            ->selectRaw('payment_method_type')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross_sales')
            ->groupBy('payment_method_name', 'payment_method_type')
            ->get();

        $normalizedFallbackRows = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->when(! empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds))
            ->whereNotExists(function ($exists) {
                $exists->selectRaw('1')
                    ->from('sale_payments as sp_check')
                    ->whereColumn('sp_check.sale_id', 's.id');
            })
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_name), ''), '-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type), ''), '') as payment_method_type")
            ->selectRaw('COALESCE(s.grand_total, 0) as gross_sales');

        $fallbackRows = DB::query()
            ->fromSub($normalizedFallbackRows, 'fallback_rows')
            ->selectRaw('payment_method_name')
            ->selectRaw('payment_method_type')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross_sales')
            ->groupBy('payment_method_name', 'payment_method_type')
            ->get();

        $rows = [];
        foreach ($paymentRows->concat($fallbackRows) as $row) {
            $key = mb_strtolower((string) ($row->payment_method_name ?? '-')) . '|' . mb_strtolower((string) ($row->payment_method_type ?? ''));
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'payment_method_name' => (string) ($row->payment_method_name ?? '-'),
                    'payment_method_type' => (string) ($row->payment_method_type ?? ''),
                    'trx_count' => 0,
                    'gross_sales' => 0,
                ];
            }
            $rows[$key]['trx_count'] += (int) ($row->trx_count ?? 0);
            $rows[$key]['gross_sales'] += (int) ($row->gross_sales ?? 0);
        }

        return array_values($rows);
    }

    private function paymentSummarySubquery(string $scopeKey): Builder
    {
        return DB::table('sale_payments as sp')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'sp.sale_id'))
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(pm.name), '') ORDER BY pm.name SEPARATOR ', ') as payment_method_names")
            ->selectRaw("GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(pm.name), ''), NULLIF(TRIM(s.payment_method_name), ''), 'Payment'), CASE WHEN COALESCE(sp.amount, 0) > 0 THEN CONCAT(' (', sp.amount, ')') ELSE '' END) ORDER BY sp.created_at, sp.id SEPARATOR ', ') as payment_method_display")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') AND COALESCE(sp.amount, 0) > 0 THEN GREATEST(COALESCE(sp.amount, 0) - COALESCE(s.change_total, 0), 0) ELSE CASE WHEN LOWER(TRIM(COALESCE(pm.name, ''))) IN ('cash', 'tunai') THEN COALESCE(sp.amount, 0) ELSE 0 END END), 0) as cash_amount")
            ->selectRaw('COUNT(*) as has_payment_rows')
            ->groupBy('sp.sale_id');
    }

    private function channelMapSubquery(string $scopeKey): Builder
    {
        return DB::table('sales as s1')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's1.id'))
            ->leftJoinSub($this->saleItemChannelsSubquery($scopeKey), 'item_channels', fn ($join) => $join->on('item_channels.sale_id', '=', 's1.id'))
            ->selectRaw('s1.id as sale_id')
            ->selectRaw("CASE
                WHEN UPPER(COALESCE(s1.channel, '')) = 'DELIVERY' AND NULLIF(TRIM(COALESCE(s1.online_order_source, '')), '') IS NOT NULL THEN LOWER(TRIM(s1.online_order_source))
                WHEN UPPER(COALESCE(s1.channel, '')) = 'MIXED' AND NULLIF(TRIM(COALESCE(item_channels.channel_display, '')), '') IS NOT NULL THEN item_channels.channel_display
                ELSE UPPER(COALESCE(s1.channel, ''))
            END as display_channel");
    }

    private function saleItemChannelsSubquery(string $scopeKey): Builder
    {
        return DB::table('sale_items as si')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'si.sale_id'))
            ->selectRaw('si.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->whereNull('si.voided_at')
            ->groupBy('si.sale_id');
    }

    private function scopeSalesSubquery(string $scopeKey): Builder
    {
        return $this->reportSaleScopeCache->subquery($scopeKey);
    }

    private function isCashPaymentLabel(?string $name, ?string $type = null): bool
    {
        $normalizedName = mb_strtolower(trim((string) ($name ?? '')));
        $normalizedType = mb_strtolower(trim((string) ($type ?? '')));

        return in_array($normalizedName, ['cash', 'tunai'], true)
            || in_array($normalizedType, ['cash', 'tunai'], true);
    }

    private function mapSaleListRow(object $row): array
    {
        $timezone = (string) ($row->outlet_timezone ?? config('app.timezone', 'Asia/Jakarta'));
        $createdAtText = TransactionDate::formatSaleLocal($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? ''));

        $payload = [
            'sale_id' => (string) ($row->sale_id ?? ''),
            'outlet_id' => (string) ($row->outlet_id ?? ''),
            'sale_number' => (string) ($row->sale_number ?? ''),
            'outlet_code' => (string) ($row->outlet_code ?? ''),
            'outlet_name' => (string) ($row->outlet_name ?? ''),
            'outlet_timezone' => $timezone,
            'channel' => (string) ($row->display_channel ?? $row->channel ?? '-'),
            'payment_method_name' => (string) ($row->payment_method_display ?? $row->payment_method_name ?? '-'),
            'payment_method_type' => (string) ($row->payment_method_type ?? ''),
            'subtotal' => (int) ($row->subtotal ?? 0),
            'discount_total' => (int) ($row->discount_total ?? 0),
            'service_charge_total' => (int) ($row->service_charge_total ?? 0),
            'grand_total' => (int) ($row->grand_total ?? 0),
            'paid_total' => (int) ($row->paid_total ?? 0),
            'change_total' => (int) ($row->change_total ?? 0),
            'marking' => (int) ($row->marking ?? 0),
            'created_at' => TransactionDate::toSaleIso($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? '')),
            'created_at_text' => $createdAtText,
            'created_at_time' => $createdAtText ? substr($createdAtText, 11, 5) : '-',
        ];

        $payload['total'] = (int) ($payload['grand_total'] ?? 0);
        $payload['paid'] = (int) ($payload['paid_total'] ?? 0);
        $payload['change'] = (int) ($payload['change_total'] ?? 0);

        return $payload;
    }

    private function transformSaleDetail(Sale $sale): array
    {
        try {
            $payload = (new SaleDetailResource($sale))->toArray(request());

            $payload['subtotal'] = (int) ($sale->subtotal ?? ($payload['subtotal'] ?? 0));
            $payload['discount_total'] = (int) ($sale->discount_total ?? ($payload['discount_total'] ?? 0));
            $payload['tax_total'] = (int) ($sale->tax_total ?? ($payload['tax_total'] ?? 0));
            $payload['service_charge_total'] = (int) ($sale->service_charge_total ?? ($payload['service_charge_total'] ?? 0));
            $payload['rounding_total'] = (int) ($sale->rounding_total ?? ($payload['rounding_total'] ?? 0));
            $payload['grand_total'] = (int) ($sale->grand_total ?? ($payload['grand_total'] ?? 0));
            $payload['paid_total'] = (int) ($sale->paid_total ?? ($payload['paid_total'] ?? 0));
            $payload['change_total'] = (int) ($sale->change_total ?? ($payload['change_total'] ?? 0));
            $payload['total_before_rounding'] = max(0, $payload['grand_total'] - $payload['rounding_total']);

            return $payload;
        } catch (Throwable $e) {
            report($e);

            $payload = [
                'id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'sale_number' => (string) ($sale->sale_number ?? ''),
                'queue_no' => $sale->queue_no ? (string) $sale->queue_no : null,
                'channel' => (string) ($sale->channel ?? '-'),
                'online_order_source' => $sale->online_order_source ? (string) $sale->online_order_source : null,
                'status' => (string) ($sale->status ?? '-'),
                'bill_name' => (string) ($sale->bill_name ?? ''),
                'is_member_customer' => false,
                'print_customer_name' => $sale->bill_name ?: optional($sale->customer)->name ?: null,
                'customer_id' => $sale->customer_id ? (string) $sale->customer_id : null,
                'table_chamber' => $sale->table_chamber ? (string) $sale->table_chamber : null,
                'table_number' => $sale->table_number ? (string) $sale->table_number : null,
                'customer' => $sale->relationLoaded('customer') && $sale->customer ? [
                    'id' => (string) $sale->customer->id,
                    'outlet_id' => (string) ($sale->customer->outlet_id ?? ''),
                    'name' => (string) ($sale->customer->name ?? ''),
                    'phone' => (string) ($sale->customer->phone ?? ''),
                ] : null,
                'cashier_id' => (string) ($sale->cashier_id ?? ''),
                'cashier_name' => (string) ($sale->cashier_name ?? ''),
                'outlet_name' => (string) optional($sale->outlet)->name,
                'outlet_name_snapshot' => (string) (optional($sale->outlet)->name ?? ''),
                'outlet_address' => (string) optional($sale->outlet)->address,
                'outlet' => $sale->relationLoaded('outlet') && $sale->outlet ? [
                    'id' => (string) $sale->outlet->id,
                    'name' => (string) ($sale->outlet->name ?? ''),
                    'address' => (string) ($sale->outlet->address ?? ''),
                    'timezone' => (string) ($sale->outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
                ] : null,
                'payment_method_name' => (string) ($sale->payment_method_name ?? '-'),
                'payment_method_type' => (string) ($sale->payment_method_type ?? ''),
                'subtotal' => (int) ($sale->subtotal ?? 0),
                'discount_type' => (string) ($sale->discount_type ?? 'NONE'),
                'discount_value' => (int) ($sale->discount_value ?? 0),
                'discount_amount' => (int) ($sale->discount_amount ?? 0),
                'discount_reason' => $sale->discount_reason,
                'discount_total' => (int) ($sale->discount_total ?? 0),
                'tax_id' => $sale->tax_id ? (string) $sale->tax_id : null,
                'tax_name' => (string) ($sale->tax_name_snapshot ?? 'Tax'),
                'tax_percent' => (int) ($sale->tax_percent_snapshot ?? 0),
                'tax_total' => (int) ($sale->tax_total ?? 0),
                'service_charge_total' => (int) ($sale->service_charge_total ?? 0),
                'total_before_rounding' => max(0, (int) ($sale->grand_total ?? 0) - (int) ($sale->rounding_total ?? 0)),
                'rounding_total' => (int) ($sale->rounding_total ?? 0),
                'grand_total' => (int) ($sale->grand_total ?? 0),
                'paid_total' => (int) ($sale->paid_total ?? 0),
                'change_total' => (int) ($sale->change_total ?? 0),
                'marking' => (int) ($sale->marking ?? 1),
                'note' => $sale->note,
                'items' => $sale->relationLoaded('items') ? $sale->items->map(function ($item) {
                    return [
                        'id' => (string) $item->id,
                        'channel' => (string) ($item->channel ?? ''),
                        'product_id' => (string) ($item->product_id ?? ''),
                        'variant_id' => (string) ($item->variant_id ?? ''),
                        'product_name' => (string) ($item->product_name ?? ''),
                        'variant_name' => (string) ($item->variant_name ?? ''),
                        'category_kind' => (string) ($item->category_kind_snapshot ?? 'OTHER'),
                        'category_name' => (string) optional(optional($item->product)->category)->name,
                        'category_slug' => (string) optional(optional($item->product)->category)->slug,
                        'qty' => (int) ($item->qty ?? 0),
                        'unit_price' => (int) ($item->unit_price ?? 0),
                        'line_total' => (int) ($item->line_total ?? 0),
                        'is_voided' => !is_null($item->voided_at),
                        'voided_at' => optional($item->voided_at)->toISOString(),
                        'voided_by_user_id' => $item->voided_by_user_id ? (string) $item->voided_by_user_id : null,
                        'voided_by_name' => $item->voided_by_name ?: null,
                        'void_reason' => $item->void_reason ?: null,
                        'original_unit_price_before_void' => (int) ($item->original_unit_price_before_void ?? 0),
                        'original_line_total_before_void' => (int) ($item->original_line_total_before_void ?? 0),
                        'note' => $item->note ?? null,
                        'addons' => $item->relationLoaded('addons') ? $item->addons->map(fn ($addon) => [
                            'id' => (string) $addon->id,
                            'addon_id' => $addon->addon_id ? (string) $addon->addon_id : null,
                            'addon_name' => (string) ($addon->addon_name ?? ''),
                            'qty_per_item' => (int) ($addon->qty_per_item ?? 0),
                            'unit_price' => (int) ($addon->unit_price ?? 0),
                            'line_total' => (int) ($addon->line_total ?? 0),
                        ])->values()->all() : [],
                    ];
                })->values()->all() : [],
                'payments' => $sale->relationLoaded('payments') ? $sale->payments->map(fn ($payment) => [
                    'id' => (string) $payment->id,
                    'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
                    'payment_method_name' => (string) ($payment->payment_method_name ?? ''),
                    'payment_method_type' => (string) ($payment->payment_method_type ?? ''),
                    'amount' => (int) ($payment->amount ?? 0),
                    'paid_at' => optional($payment->paid_at)->toISOString(),
                    'reference_no' => $payment->reference_no ?: null,
                    'meta' => $payment->meta,
                ])->values()->all() : [],
                'cancel_requests' => $sale->relationLoaded('cancelRequests') ? $sale->cancelRequests->map(fn ($request) => [
                    'id' => (string) $request->id,
                    'status' => (string) ($request->status ?? ''),
                    'reason' => $request->reason,
                    'requested_by' => $request->requested_by ? (string) $request->requested_by : null,
                    'approved_by' => $request->approved_by ? (string) $request->approved_by : null,
                    'created_at' => optional($request->created_at)->toISOString(),
                    'updated_at' => optional($request->updated_at)->toISOString(),
                ])->values()->all() : [],
                'created_at' => optional($sale->created_at)->toISOString(),
                'updated_at' => optional($sale->updated_at)->toISOString(),
            ];

            return $payload;
        }
    }

    private function normalizeSaleDetailPayload(array $sale): array
    {
        $items = collect($sale['items'] ?? [])
            ->filter(fn ($item) => !($item['is_voided'] ?? false));
        $payments = collect($sale['payments'] ?? []);

        $itemsSubtotal = (int) $items->sum(fn ($item) => max(0, (int) ($item['line_total'] ?? 0)));
        $paymentTotal = (int) $payments->sum(fn ($payment) => max(0, (int) ($payment['amount'] ?? 0)));

        $subtotal = max(0, (int) ($sale['subtotal'] ?? 0));
        if ($subtotal <= 0 && $itemsSubtotal > 0) {
            $subtotal = $itemsSubtotal;
        }

        $discountTotal = max(0, (int) ($sale['discount_total'] ?? 0));
        if ($discountTotal <= 0) {
            $discountTotal = max(0, (int) ($sale['discount_amount'] ?? 0));
        }

        $taxTotal = max(0, (int) ($sale['tax_total'] ?? 0));
        $taxPercent = max(0, (int) ($sale['tax_percent'] ?? 0));
        if ($taxTotal <= 0 && $taxPercent > 0 && $subtotal > 0) {
            $taxBase = max(0, $subtotal - $discountTotal);
            $taxTotal = (int) round($taxBase * $taxPercent / 100);
        }

        $serviceChargeTotal = max(0, (int) ($sale['service_charge_total'] ?? 0));
        $roundingTotal = (int) ($sale['rounding_total'] ?? 0);
        $grandTotal = max(0, (int) ($sale['grand_total'] ?? 0));

        $computedGrand = max(0, $subtotal - $discountTotal + $taxTotal + $serviceChargeTotal + $roundingTotal);
        if ($grandTotal <= 0 && $computedGrand > 0) {
            $grandTotal = $computedGrand;
        }
        if ($grandTotal <= 0 && $paymentTotal > 0) {
            $grandTotal = $paymentTotal;
        }

        $paidTotal = max(0, (int) ($sale['paid_total'] ?? 0));
        if ($paidTotal <= 0 && $paymentTotal > 0) {
            $paidTotal = $paymentTotal;
        }
        if ($paidTotal <= 0 && $grandTotal > 0) {
            $paidTotal = $grandTotal;
        }

        $changeTotal = max(0, (int) ($sale['change_total'] ?? 0));
        if ($changeTotal <= 0 && $paidTotal > $grandTotal) {
            $changeTotal = max(0, $paidTotal - $grandTotal);
        }

        $sale['subtotal'] = $subtotal;
        $sale['discount_total'] = $discountTotal;
        $sale['tax_total'] = $taxTotal;
        $sale['service_charge_total'] = $serviceChargeTotal;
        $sale['rounding_total'] = $roundingTotal;
        $sale['grand_total'] = $grandTotal;
        $sale['paid_total'] = $paidTotal;
        $sale['change_total'] = $changeTotal;
        $sale['total_before_rounding'] = max(0, $grandTotal - $roundingTotal);

        if (empty($sale['tax_name']) && $taxTotal > 0) {
            $sale['tax_name'] = 'Tax';
        }

        return $sale;
    }
}
