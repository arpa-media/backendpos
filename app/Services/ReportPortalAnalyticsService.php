<?php

namespace App\Services;

use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Services\CashierAlignedSaleScopeService;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\TransactionDate;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReportPortalAnalyticsService
{
    private ?string $contextTimezone = null;

    public function __construct(
        private readonly ReportPortalScopeService $scopeService,
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
    ) {
    }


    private function setScopeTimezone(array $scope): void
    {
        $this->contextTimezone = $this->resolveScopeTimezone($scope);
    }

    private function resolveScopeTimezone(array $scope): string
    {
        $defaultTimezone = TransactionDate::normalizeTimezone(config('app.timezone', 'Asia/Jakarta'));
        $allowed = collect($scope['allowed_outlets'] ?? []);
        $selectedOutletId = (string) ($scope['selected_outlet_id'] ?? '');

        if ($selectedOutletId !== '') {
            $selected = $allowed->first(fn ($outlet) => (string) ($outlet['id'] ?? '') === $selectedOutletId);
            if (!empty($selected['timezone'])) {
                return TransactionDate::normalizeTimezone((string) $selected['timezone'], $defaultTimezone);
            }
        }

        if ($allowed->count() === 1 && !empty($allowed->first()['timezone'])) {
            return TransactionDate::normalizeTimezone((string) $allowed->first()['timezone'], $defaultTimezone);
        }

        return $defaultTimezone;
    }

    private function resolveWindow(?string $dateFrom, ?string $dateTo): array
    {
        return TransactionDate::businessDateWindow($dateFrom, $dateTo, $this->contextTimezone ?: config('app.timezone', 'Asia/Jakarta'));
    }

    private function applyDateRange(object $query, string $column, ?string $dateFrom, ?string $dateTo, ?string $saleNumberColumn = null): void
    {
        $saleNumberColumn = $saleNumberColumn ?: preg_replace('/created_at$/', 'sale_number', $column);

        TransactionDate::applyExactBusinessDateScope(
            $query,
            $column,
            $dateFrom,
            $dateTo,
            $this->contextTimezone ?: config('app.timezone', 'Asia/Jakarta'),
            is_string($saleNumberColumn) ? $saleNumberColumn : null
        );
    }

    private function effectiveScopeOutletIds(array $scope): array
    {
        $selectedOutletId = (string) ($scope['selected_outlet_id'] ?? '');
        if ($selectedOutletId !== '') {
            return [$selectedOutletId];
        }

        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scope['allowed_outlet_ids'] ?? []))));
        sort($outletIds);

        return $outletIds;
    }

    private function resolveEligibleSalesScope(array $scope, array $params): array
    {
        $outletIds = $this->effectiveScopeOutletIds($scope);
        $timezone = (string) ($this->contextTimezone ?: config('app.timezone', 'Asia/Jakarta'));

        if ($outletIds === []) {
            return [
                'scope_key' => 'report-portal:empty',
                'has_rows' => false,
            ];
        }

        $fingerprint = [
            'outlets' => $outletIds,
            'date_from' => (string) ($params['date_from'] ?? ''),
            'date_to' => (string) ($params['date_to'] ?? ''),
            'timezone' => $timezone,
            'marked_only' => ! empty($scope['marked_only']),
        ];

        return $this->reportSaleScopeCache->remember(
            'report-portal.sales-scope',
            $fingerprint,
            fn () => $this->cashierAlignedSaleScope->eligibleSaleIds(
                $outletIds,
                $params['date_from'] ?? null,
                $params['date_to'] ?? null,
                $timezone,
            ),
            360,
        );
    }

    private function applyCachedSaleScope(QueryBuilder $query, string $saleAlias, array $saleScope, string $cacheAlias = 'rssc'): void
    {
        if (! ($saleScope['has_rows'] ?? false)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->join('report_sale_scope_cache as ' . $cacheAlias, function ($join) use ($saleAlias, $cacheAlias, $saleScope) {
            $join->on($cacheAlias . '.sale_id', '=', $saleAlias . '.id')
                ->where($cacheAlias . '.scope_key', '=', (string) ($saleScope['scope_key'] ?? ''))
                ->where($cacheAlias . '.expires_at', '>', now());
        });
    }

    public function dashboard(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $recentLimit = max(1, min(20, (int) ($params['recent_limit'] ?? 5)));
        $topLimit = max(1, min(10, (int) ($params['top_limit'] ?? 5)));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $outletIds = $this->effectiveScopeOutletIds($scope);

        if (! ($saleScope['has_rows'] ?? false)) {
            return [
                'scope' => $this->scopeMeta($scope),
                'range' => [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ],
                'metrics' => [
                    'gross_sales' => 0,
                    'transaction_count' => 0,
                    'items_sold' => 0,
                    'avg_ticket' => 0,
                ],
                'breakdowns' => [
                    'by_channel' => [],
                    'by_payment_method_snapshot' => [],
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

        $scopeKey = (string) ($saleScope['scope_key'] ?? '');
        $paymentBreakdownRows = $this->resolvePaymentBreakdown($saleScope, $outletIds);

        $metricsRow = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($metricsRow, $scope, 's');
        $metricsRow = $metricsRow
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as gross_sales')
            ->first();

        $itemsSoldQuery = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($itemsSoldQuery, $scope, 's');
        $itemsSold = (int) $itemsSoldQuery->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sum')->value('qty_sum');

        $trxCount = (int) ($metricsRow->trx_count ?? 0);
        $grossSales = (int) ($metricsRow->gross_sales ?? 0);
        $avgTicket = $trxCount > 0 ? (int) floor($grossSales / $trxCount) : 0;

        $byChannel = $this->resolveChannelBreakdown($saleScope, $scope);
        usort($byChannel, fn (array $a, array $b) => ($b['gross_sales'] <=> $a['gross_sales']) ?: (($b['trx_count'] ?? 0) <=> ($a['trx_count'] ?? 0)) ?: strcmp((string) ($a['channel'] ?? ''), (string) ($b['channel'] ?? '')));

        $byPaymentMethod = $paymentBreakdownRows;
        usort($byPaymentMethod, fn (array $a, array $b) => ($b['gross_sales'] <=> $a['gross_sales']) ?: (($b['trx_count'] ?? 0) <=> ($a['trx_count'] ?? 0)) ?: strcmp((string) ($a['payment_method_name'] ?? ''), (string) ($b['payment_method_name'] ?? '')));

        $topVariantsQuery = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($topVariantsQuery, $scope, 's');
        $topVariants = $topVariantsQuery
            ->select('si.product_name', 'si.variant_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as revenue')
            ->groupBy('si.product_name', 'si.variant_name')
            ->orderByDesc('qty_sold')
            ->orderBy('si.product_name')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? ''),
                'variant_name' => (string) ($row->variant_name ?? ''),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $topProductsQuery = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($topProductsQuery, $scope, 's');
        $topProducts = $topProductsQuery
            ->select('si.product_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as revenue')
            ->groupBy('si.product_name')
            ->orderByDesc('qty_sold')
            ->orderBy('si.product_name')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? ''),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $recentSalesQuery = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($recentSalesQuery, $scope, 's');
        $recentSales = $recentSalesQuery
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id')
            ->limit($recentLimit)
            ->get([
                's.id as sale_id',
                's.outlet_id',
                's.sale_number',
                's.channel',
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
                DB::raw('COALESCE(payments.has_payment_rows, 0) as has_payment_rows'),
            ])
            ->map(fn ($row) => $this->mapSaleListRow($row))
            ->values()
            ->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'metrics' => [
                'gross_sales' => $grossSales,
                'transaction_count' => $trxCount,
                'items_sold' => $itemsSold,
                'avg_ticket' => $avgTicket,
            ],
            'breakdowns' => [
                'by_channel' => $byChannel,
                'by_payment_method_snapshot' => $byPaymentMethod,
            ],
            'top_items' => [
                'variants' => $topVariants,
                'products' => $topProducts,
            ],
            'recent_sales' => [
                'items' => $recentSales,
                'meta' => [
                    'limit' => $recentLimit,
                    'total' => $trxCount,
                ],
            ],
        ];
    }

    public function ledger(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        return $this->saleListing($scope, $params, max(1, min(100, (int) ($params['per_page'] ?? 10))));
    }

    public function recentSales(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        return $this->saleListing($scope, $params, max(1, min(100, (int) ($params['per_page'] ?? 5))));
    }

    public function itemSold(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 10)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');

        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->scopeService->applySalesScope($query, $scope, 's');

        $query
            ->select('si.product_name', 'si.variant_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty')
            ->selectRaw('COALESCE(AVG(si.unit_price), 0) as unit_price')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as total')
            ->groupBy('si.product_name', 'si.variant_name')
            ->orderByDesc('qty')
            ->orderBy('si.product_name')
            ->orderBy('si.variant_name');

        $paginator = $this->paginate($query, $perPage, $page);

        $items = collect($paginator->items())->map(fn ($row) => [
            'item' => (string) ($row->product_name ?? ''),
            'variant' => (string) ($row->variant_name ?? ''),
            'qty' => (int) ($row->qty ?? 0),
            'unit_price' => (int) ($row->unit_price ?? 0),
            'total' => (int) ($row->total ?? 0),
        ])->values()->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    public function itemByProduct(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 10)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');

        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->scopeService->applySalesScope($query, $scope, 's');

        $query
            ->select('si.product_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as total')
            ->groupBy('si.product_name')
            ->orderByDesc('qty')
            ->orderBy('si.product_name');

        $paginator = $this->paginate($query, $perPage, $page);

        $items = collect($paginator->items())->map(fn ($row) => [
            'product_name' => (string) ($row->product_name ?? ''),
            'qty' => (int) ($row->qty ?? 0),
            'total' => (int) ($row->total ?? 0),
        ])->values()->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    public function itemByVariant(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 10)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');

        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->scopeService->applySalesScope($query, $scope, 's');

        $query
            ->select('si.product_name', 'si.variant_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as total')
            ->groupBy('si.product_name', 'si.variant_name')
            ->orderByDesc('qty')
            ->orderBy('si.product_name')
            ->orderBy('si.variant_name');

        $paginator = $this->paginate($query, $perPage, $page);

        $items = collect($paginator->items())->map(fn ($row) => [
            'product_name' => (string) ($row->product_name ?? ''),
            'variant_name' => (string) ($row->variant_name ?? ''),
            'qty' => (int) ($row->qty ?? 0),
            'total' => (int) ($row->total ?? 0),
        ])->values()->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    public function tax(array $scope, array $params): array
    {
        $this->setScopeTimezone($scope);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 10)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');

        $query = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->scopeService->applySalesScope($query, $scope, 's');

        if (!empty($params['sale_number'])) {
            $query->where('s.sale_number', 'like', '%' . trim((string) $params['sale_number']) . '%');
        }
        if (!empty($params['channel'])) {
            $query->where('s.channel', '=', (string) $params['channel']);
        }
        if (!empty($params['payment_method_name'])) {
            $query->where('s.payment_method_name', '=', (string) $params['payment_method_name']);
        }

        $query
            ->select([
                's.id as sale_id',
                's.sale_number',
                's.channel',
                's.payment_method_name',
                's.payment_method_type',
                DB::raw("COALESCE(s.tax_name_snapshot, 'Tax') as tax_name_snapshot"),
                DB::raw('COALESCE(s.tax_percent_snapshot, 0) as tax_percent_snapshot'),
                DB::raw('COALESCE(s.tax_total, 0) as tax_total'),
                DB::raw('COALESCE(s.grand_total, 0) as grand_total'),
                's.marking',
                's.created_at',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.timezone as outlet_timezone',
                DB::raw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) as display_channel"),
                DB::raw("COALESCE(NULLIF(payments.payment_method_display, ''), NULLIF(payments.payment_method_names, ''), NULLIF(s.payment_method_name, ''), '-') as payment_method_display"),
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $paginator = $this->paginate($query, $perPage, $page);

        $items = collect($paginator->items())->map(function ($row) {
            $timezone = (string) ($row->outlet_timezone ?? config('app.timezone', 'Asia/Jakarta'));
            $createdAtText = TransactionDate::formatSaleLocal($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? ''));
            return [
                'sale_id' => (string) $row->sale_id,
                'sale_number' => (string) ($row->sale_number ?? ''),
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? ''),
                'outlet_timezone' => $timezone,
                'channel' => (string) ($row->display_channel ?? $row->channel ?? '-'),
                'payment_method_name' => (string) ($row->payment_method_display ?? $row->payment_method_name ?? '-'),
                'tax_name' => (string) ($row->tax_name_snapshot ?? 'Tax'),
                'tax_percent' => (int) ($row->tax_percent_snapshot ?? 0),
                'tax_total' => (int) ($row->tax_total ?? 0),
                'grand_total' => (int) ($row->grand_total ?? 0),
                'marking' => (int) ($row->marking ?? 1),
                'created_at' => TransactionDate::toSaleIso($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? '')),
                'created_at_text' => $createdAtText,
                'created_at_time' => $createdAtText ? substr($createdAtText, 11, 5) : '-',
            ];
        })->values()->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    public function saleDetail(array $scope, string $saleId): array
    {
        $this->setScopeTimezone($scope);
        $params = [
            'date_from' => request('date_from'),
            'date_to' => request('date_to'),
        ];
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);

        if (! ($saleScope['has_rows'] ?? false)) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada scope report ini.',
                'error_code' => 'REPORT_SALE_NOT_FOUND',
            ];
        }

        $visible = DB::table('report_sale_scope_cache as rssc_visible')
            ->where('rssc_visible.scope_key', (string) $saleScope['scope_key'])
            ->where('rssc_visible.sale_id', $saleId)
            ->where('rssc_visible.expires_at', '>', now())
            ->exists();

        if (! $visible) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada scope report ini.',
                'error_code' => 'REPORT_SALE_NOT_FOUND',
            ];
        }

        $sale = Sale::query()
            ->with(['outlet', 'items.product.category', 'items.addons', 'payments', 'customer', 'cancelRequests'])
            ->where('id', $saleId)
            ->where('status', '=', 'PAID')
            ->whereIn('outlet_id', $scope['allowed_outlet_ids'] ?? []);

        if (!empty($scope['selected_outlet_id'])) {
            $sale->where('outlet_id', '=', $scope['selected_outlet_id']);
        }

        if (!empty($scope['marked_only'])) {
            $sale->whereRaw('COALESCE(CAST(marking AS SIGNED), 0) = 1');
        }

        $sale = $sale->first();

        if (!$sale) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Transaksi tidak ditemukan pada scope report ini.',
                'error_code' => 'REPORT_SALE_NOT_FOUND',
            ];
        }

        return [
            'ok' => true,
            'scope' => $this->scopeMeta($scope),
            'sale' => $this->transformSaleDetail($sale),
        ];
    }

    private function saleListing(array $scope, array $params, int $defaultPerPage): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? $defaultPerPage)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $saleScope = $this->resolveEligibleSalesScope($scope, $params);
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');

        $query = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery($scopeKey), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->scopeService->applySalesScope($query, $scope, 's');

        if (!empty($params['channel'])) {
            $query->where('s.channel', '=', (string) $params['channel']);
        }
        if (!empty($params['payment_method_name'])) {
            $query->where('s.payment_method_name', '=', (string) $params['payment_method_name']);
        }
        if (!empty($params['sale_number'])) {
            $query->where('s.sale_number', 'like', '%' . trim((string) $params['sale_number']) . '%');
        }

        $query
            ->select([
                's.id as sale_id',
                's.outlet_id',
                's.sale_number',
                's.channel',
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
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id');

        $paginator = $this->paginate($query, $perPage, $page);
        $items = collect($paginator->items())->map(fn ($row) => $this->mapSaleListRow($row))->values()->all();

        return [
            'scope' => $this->scopeMeta($scope),
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function resolveRange(?string $dateFrom, ?string $dateTo): array
    {
        $window = $this->resolveWindow($dateFrom, $dateTo);

        return [$window['requested_from'], $window['requested_to']];
    }

    private function paginate(QueryBuilder $query, int $perPage, int $page): LengthAwarePaginator
    {
        return $query->paginate(perPage: $perPage, page: $page);
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }


    private function resolveChannelBreakdown(array $saleScope, array $scope): array
    {
        $scopeKey = (string) ($saleScope['scope_key'] ?? '');
        if ($scopeKey === '' || ! ($saleScope['has_rows'] ?? false)) {
            return [];
        }

        $normalizedRows = DB::table('sales as s')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery($scopeKey), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->scopeService->applySalesScope($normalizedRows, $scope, 's');

        return DB::query()
            ->fromSub(
                $normalizedRows
                    ->selectRaw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) as channel")
                    ->selectRaw('COALESCE(s.grand_total, 0) as gross_sales'),
                'channel_rows'
            )
            ->selectRaw('channel')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(gross_sales), 0) as gross_sales')
            ->groupBy('channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => (string) ($row->channel ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();
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

    private function paymentSummarySubquery(string $scopeKey): QueryBuilder
    {
        return DB::table('sale_payments as sp')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'sp.sale_id'))
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(pm.name), '') ORDER BY pm.name SEPARATOR ', ') as payment_method_names")
            ->selectRaw("GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(pm.name), ''), NULLIF(TRIM(s.payment_method_name), ''), 'Payment'), CASE WHEN COALESCE(sp.amount, 0) > 0 THEN CONCAT(' (', sp.amount, ')') ELSE '' END) ORDER BY sp.created_at, sp.id SEPARATOR ', ') as payment_method_display")
            ->selectRaw('COUNT(*) as has_payment_rows')
            ->groupBy('sp.sale_id');
    }

    private function channelMapSubquery(string $scopeKey): QueryBuilder
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

    private function saleItemChannelsSubquery(string $scopeKey): QueryBuilder
    {
        return DB::table('sale_items as si')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'si.sale_id'))
            ->selectRaw('si.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->whereNull('si.voided_at')
            ->groupBy('si.sale_id');
    }

    private function scopeSalesSubquery(string $scopeKey): QueryBuilder
    {
        return $this->reportSaleScopeCache->subquery($scopeKey);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (is_string($value)) {
            return str_replace('T', ' ', preg_replace('/\..*$/', '', $value));
        }

        return optional($value)->format('Y-m-d H:i:s');
    }

    private function transformSaleDetail(Sale $sale): array
    {
        try {
            return (new SaleDetailResource($sale))->toArray(request());
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
                    'payment_method_id' => (string) ($payment->payment_method_id ?? ''),
                    'amount' => (int) ($payment->amount ?? 0),
                    'reference' => $payment->reference,
                    'created_at' => optional($payment->created_at)->toISOString(),
                    'updated_at' => optional($payment->updated_at)->toISOString(),
                ])->values()->all() : [],
                'latest_request_type' => null,
                'cancel_requests' => [],
                'created_at' => TransactionDate::toSaleIso(method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('created_at') : $sale->created_at, optional($sale->outlet)->timezone, (string) ($sale->sale_number ?? '')),
                'updated_at' => TransactionDate::toSaleIso(method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('updated_at') : $sale->updated_at, optional($sale->outlet)->timezone, (string) ($sale->sale_number ?? '')),
                'created_at_text' => TransactionDate::formatSaleLocal(method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('created_at') : $sale->created_at, optional($sale->outlet)->timezone, (string) ($sale->sale_number ?? '')),
                'updated_at_text' => TransactionDate::formatSaleLocal(method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('updated_at') : $sale->updated_at, optional($sale->outlet)->timezone, (string) ($sale->sale_number ?? '')),
            ];

            return DeliveryNoTaxReadModel::normalizeSaleArray($payload);
        }
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
            'marking' => (int) ($row->marking ?? 1),
            'created_at' => TransactionDate::toSaleIso($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? '')),
            'created_at_text' => $createdAtText,
            'created_at_time' => $createdAtText ? substr($createdAtText, 11, 5) : '-',
        ];

        $payload['total'] = (int) ($payload['grand_total'] ?? 0);
        $payload['paid'] = (int) ($payload['paid_total'] ?? 0);
        $payload['change'] = (int) ($payload['change_total'] ?? 0);

        return $payload;
    }

    private function scopeMeta(array $scope): array
    {
        $markedOnly = (bool) ($scope['marked_only'] ?? false);

        return [
            'portal_code' => (string) ($scope['portal_code'] ?? ''),
            'portal_name' => (string) ($scope['portal_name'] ?? ''),
            'mode' => (string) ($scope['mode'] ?? ''),
            'marking_rule' => (string) ($scope['marking_rule'] ?? ''),
            'marked_only' => $markedOnly,
            'marking_filter_value' => $markedOnly ? 1 : null,
            'selected_outlet_id' => $scope['selected_outlet_id'] ?? null,
            'selected_outlet_code' => (string) ($scope['selected_outlet_code'] ?? 'ALL'),
            'selected_outlet_name' => (string) ($scope['selected_outlet_name'] ?? 'ALL'),
            'uses_all_outlets' => (bool) ($scope['uses_all_outlets'] ?? false),
            'allowed_outlets' => $scope['allowed_outlets'] ?? [],
        ];
    }
}
