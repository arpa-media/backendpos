<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListSalesCollectedRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Models\Sale;
use App\Services\ReportDailySummaryService;
use App\Support\FinanceOutletFilter;
use App\Support\AnalyticsResponseCache;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\TransactionDate;
use Throwable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesCollectedController extends Controller
{

    public function __construct(
        private readonly ReportDailySummaryService $dailySummaryService,
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
            180,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        return ApiResponse::ok($payload, 'OK');
    }

    public function detail(ListSalesCollectedRequest $request, string $saleId)
    {
        $v = $request->validated();
        @ini_set('max_execution_time', '120');
        @set_time_limit(120);

        $payload = AnalyticsResponseCache::remember(
            'finance-sales-collected.detail.v8i02',
            array_merge($v, ['sale_id' => $saleId]),
            function () use ($request, $saleId, $v) {
                $outletFilter = $this->resolveOutletFilter($v);
                $timezone = $outletFilter['timezone'];
                $outletIds = $outletFilter['outlet_ids'];
                $window = $this->resolveLocalDateRange($v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);

                $visible = $this->canonicalSalesQuery($outletIds, $v, $timezone)
                    ->where('s.id', $saleId)
                    ->exists();

                if (!$visible) {
                    return [
                        '_error' => true,
                        'message' => 'Transaksi tidak ditemukan pada filter Sales Collected ini.',
                        'error_code' => 'SALES_COLLECTED_SALE_NOT_FOUND',
                        'status' => 404,
                    ];
                }

                $sale = Sale::query()
                    ->with(['outlet', 'items.product.category', 'items.addons', 'payments', 'customer', 'cancelRequests'])
                    ->where('id', $saleId)
                    ->whereNull('deleted_at')
                    ->where('status', 'PAID')
                    ->when(!empty($outletIds), fn ($query) => $query->whereIn('outlet_id', $outletIds))
                    ->first();
                if (!$sale) {
                    return [
                        '_error' => true,
                        'message' => 'Transaksi tidak ditemukan pada filter Sales Collected ini.',
                        'error_code' => 'SALES_COLLECTED_SALE_NOT_FOUND',
                        'status' => 404,
                    ];
                }

                return [
                    'sale' => $this->transformSaleDetail($sale, $request),
                    'meta' => [
                        'outlet_scope_id' => $outletFilter['value'],
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'timezone' => $timezone,
                        'query_strategy' => 'report_sale_business_dates',
                    ],
                ];
            },
            120,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        if (($payload['_error'] ?? false) === true) {
            return ApiResponse::error(
                (string) ($payload['message'] ?? 'Transaksi tidak ditemukan.'),
                (string) ($payload['error_code'] ?? 'SALES_COLLECTED_SALE_NOT_FOUND'),
                (int) ($payload['status'] ?? 404)
            );
        }

        return ApiResponse::ok($payload, 'OK');
    }

    private function transformSaleDetail(Sale $sale, $request): array
    {
        try {
            return (new SaleDetailResource($sale))->toArray($request);
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
                    'outlet_id' => (string) $sale->customer->outlet_id,
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

    public function index(ListSalesCollectedRequest $request)
    {
        $v = $request->validated();

        return $this->okCached($request, 'finance-sales-collected.index.v8i02', $v, function () use ($v) {
            $perPage = max(1, min(200, (int) ($v['per_page'] ?? 15)));
            $page = max(1, (int) ($v['page'] ?? 1));
            $sort = (string) ($v['sort'] ?? 'date');
            $dir = strtolower((string) ($v['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
            $isExport = $this->toBool($v['export'] ?? false);
            $includeItems = $this->toBool($v['include_items'] ?? false);
            $includeFilterOptions = $this->toBool($v['include_filter_options'] ?? true);

            $outletFilter = $this->resolveOutletFilter($v);
            $timezone = $outletFilter['timezone'];
            $outletIds = $outletFilter['outlet_ids'];
            $window = $this->resolveLocalDateRange($v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);
            [$fromLocal, $toLocal] = [$window['requested_from'], $window['requested_to']];

            $baseQuery = $this->canonicalSalesQuery($outletIds, $v, $timezone);
            $summary = $this->resolveSummary($outletIds, $v, $timezone, $baseQuery);

            $channelOptions = [];
            $paymentOptions = [];
            if ($includeFilterOptions) {
                $channelOptions = $this->resolveChannelOptions($outletIds, $v, $timezone);
                $paymentOptions = $this->resolvePaymentMethodOptions($outletIds, $v, $timezone);
            }

            $rowsQuery = clone $baseQuery;
            $rowsQuery
                ->join('outlets as o', 'o.id', '=', 's.outlet_id')
                ->select([
                    's.id',
                    's.sale_number',
                    's.outlet_id',
                    'o.name as outlet_name',
                    'o.timezone as outlet_timezone',
                    's.created_at',
                    's.subtotal',
                    's.discount_total',
                    's.payment_method_type',
                    's.payment_method_name',
                    's.channel',
                    's.online_order_source',
                    's.tax_total',
                    's.rounding_total',
                    's.grand_total',
                    's.paid_total',
                    's.cashier_name',
                ])
                ->selectRaw('GREATEST(COALESCE(s.subtotal, 0) - COALESCE(s.discount_total, 0), 0) as net_sales');

            $this->applySorting($rowsQuery, $sort, $dir);

            // V8 I02 contract: even export reads are paged. The frontend iterates
            // pages, so a 1-year export never hydrates the whole period in one PHP request.
            $knownTotal = max(0, (int) ($summary->transaction_count ?? 0));
            $pageRows = (clone $rowsQuery)->forPage($page, $perPage)->get();
            $paginator = new LengthAwarePaginator($pageRows, $knownTotal, $perPage, $page);
            $rows = collect($paginator->items());
            $paginationPayload = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];

            $saleIds = $rows->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
            $paymentMap = $this->resolvePaymentDisplayBySaleIds($saleIds);
            $mixedChannelMap = $this->resolveMixedChannelDisplayBySaleIds($saleIds);
            $itemsMap = $includeItems ? $this->resolveItemsTextBySaleIds($saleIds) : [];

            $items = $rows->map(function ($row) use ($paymentMap, $mixedChannelMap, $itemsMap, $includeItems) {
                $transactionTimezone = $row->outlet_timezone ?: config('app.timezone', 'Asia/Jakarta');
                $saleNumber = (string) ($row->sale_number ?? '');
                $date = TransactionDate::formatSaleLocal($row->created_at, $transactionTimezone, $saleNumber, 'Y-m-d');
                $time = TransactionDate::formatSaleLocal($row->created_at, $transactionTimezone, $saleNumber, 'H:i:s');
                $createdAt = TransactionDate::toSaleIso($row->created_at, $transactionTimezone, $saleNumber);
                $saleId = (string) $row->id;

                $displayChannel = $this->displayChannelForRow($row, $mixedChannelMap[$saleId] ?? null);
                $paymentDisplay = $paymentMap[$saleId]
                    ?? (trim((string) ($row->payment_method_name ?? '')) !== '' ? (string) $row->payment_method_name : '-');

                return [
                    'id' => $saleId,
                    'sale_id' => $saleId,
                    'sale_number' => $saleNumber,
                    'sale_number_short' => mb_substr($saleNumber, -8),
                    'outlet' => (string) ($row->outlet_name ?? '-'),
                    'date' => $date,
                    'time' => $time,
                    'created_at' => $createdAt,
                    'gross_sales' => (int) ($row->subtotal ?? 0),
                    'discount' => (int) ($row->discount_total ?? 0),
                    'net_sales' => (int) ($row->net_sales ?? 0),
                    'tax' => (int) ($row->tax_total ?? 0),
                    'total_collected' => (int) ($row->grand_total ?? 0),
                    'rounding_total' => (int) ($row->rounding_total ?? 0),
                    'grand_total' => (int) ($row->grand_total ?? 0),
                    'paid_total' => (int) ($row->paid_total ?? $row->grand_total ?? 0),
                    'collected_by' => (string) ($row->cashier_name ?? '-'),
                    'items' => $includeItems ? ($itemsMap[$saleId] ?? '-') : '',
                    'channel' => $displayChannel,
                    'payment_method' => $paymentDisplay,
                ];
            })->values();

            $summaryPayload = [
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'gross_sales' => (int) ($summary->total_gross_sales ?? 0),
                'discount' => (int) ($summary->total_discount ?? 0),
                'net_sales' => (int) ($summary->total_net_sales ?? 0),
                'tax' => (int) ($summary->total_tax ?? 0),
                'total_collected' => (int) ($summary->total_collected ?? 0),
            ];

            $filterPayload = [
                'date_from' => $fromLocal->format('Y-m-d'),
                'date_to' => $toLocal->format('Y-m-d'),
                'channels' => $channelOptions,
                'payment_methods' => $paymentOptions,
                'selected_channel' => (string) ($v['channel'] ?? ''),
                'selected_payment_method' => (string) ($v['payment_method_name'] ?? ''),
                'search' => (string) ($v['q'] ?? ''),
                'outlet_filter' => (string) $outletFilter['value'],
            ];

            $metaPayload = [
                'timezone' => $timezone,
                'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                'outlet_scope_id' => $outletFilter['value'],
                'outlet_scope_name' => $outletFilter['label'],
                'sort' => $sort,
                'dir' => $dir,
                'items_loaded' => $includeItems,
                'filter_options_loaded' => $includeFilterOptions,
                'performance_notes' => [
                    'range_scope' => 'report_sale_business_dates_direct',
                    'summary_source' => $this->canUseMaterializedSummary($v) && $this->hasCompleteDailySummaryCoverage($outletIds, $v, $timezone)
                        ? 'report_daily_sales_summaries'
                        : 'canonical_filtered_sales',
                    'row_decoration_scope' => 'current_page_sale_ids_only',
                    'filter_options_source' => 'report_daily_channel/payment_summaries',
                    'export_mode' => 'paged_200_rows_max_per_request',
                ],
            ];

            $payload = [
                'items' => $items,
                'summary' => $summaryPayload,
                'filters' => $filterPayload,
                'meta' => $metaPayload,
                'pagination' => $paginationPayload,
            ];

            if ($isExport) {
                $payload['export'] = [
                    'filename' => $this->buildExportFilename($metaPayload['outlet_scope_name'], $filterPayload['date_from'], $filterPayload['date_to']),
                    'paged' => true,
                    'total_rows' => $paginationPayload['total'],
                    'current_page_rows' => $items->count(),
                ];
            }

            return $payload;
        });
    }

    public function items(ListSalesCollectedRequest $request)
    {
        $v = $request->validated();
        $saleIds = collect($v['sale_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values()->all();
        if (empty($saleIds)) {
            return ApiResponse::ok(['items_map' => []], 'OK');
        }

        $outletFilter = $this->resolveOutletFilter($v);
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        $visibleSaleIds = $this->canonicalSalesQuery($outletIds, $v, $timezone)
            ->whereIn('s.id', $saleIds)
            ->pluck('s.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        return ApiResponse::ok([
            'items_map' => $this->resolveItemsTextBySaleIds($visibleSaleIds),
        ], 'OK');
    }

    private function resolveOutletFilter(array $filters): array
    {
        return FinanceOutletFilter::resolve((string) ($filters['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
    }

    private function resolveLocalDateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        return TransactionDate::businessDateWindow($dateFrom, $dateTo, $timezone);
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function canonicalSalesQuery(array $outletIds, array $filters, string $timezone): Builder
    {
        $window = $this->resolveLocalDateRange(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );

        $query = DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->whereBetween('rsbd.business_date', [
                $window['requested_from']->format('Y-m-d'),
                $window['requested_to']->format('Y-m-d'),
            ])
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($q) => $q->whereIn('rsbd.outlet_id', $outletIds));

        $this->applySaleNumberFilter($query, (string) ($filters['q'] ?? ''));

        if (!empty($filters['channel'])) {
            $this->applyChannelFilter($query, trim((string) $filters['channel']));
        }

        if (!empty($filters['payment_method_name'])) {
            $this->applyPaymentMethodFilter($query, trim((string) $filters['payment_method_name']));
        }

        return $query;
    }

    private function hasCompleteDailySummaryCoverage(array $outletIds, array $filters, string $timezone): bool
    {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        if ($outletIds === []) {
            return false;
        }

        $window = $this->resolveLocalDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);
        $from = $window['requested_from'];
        $to = $window['requested_to'];
        $expectedRows = count($outletIds) * ($from->diffInDays($to) + 1);

        sort($outletIds);
        $cacheKey = 'sales-collected:daily-coverage-ready:v8i02:' . sha1(json_encode([
            'outlets' => $outletIds,
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
        ], JSON_UNESCAPED_SLASHES));

        return (bool) Cache::remember($cacheKey, now()->addSeconds(60), function () use ($outletIds, $from, $to, $expectedRows) {
            return DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id', $outletIds)
                ->whereBetween('business_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->count() >= $expectedRows;
        });
    }

    private function canUseMaterializedSummary(array $filters): bool
    {
        return trim((string) ($filters['q'] ?? '')) === ''
            && trim((string) ($filters['channel'] ?? '')) === ''
            && trim((string) ($filters['payment_method_name'] ?? '')) === '';
    }

    private function resolveSummary(array $outletIds, array $filters, string $timezone, Builder $filteredBase): object
    {
        if ($outletIds !== [] && $this->canUseMaterializedSummary($filters) && $this->hasCompleteDailySummaryCoverage($outletIds, $filters, $timezone)) {
            // I01 warm/scheduler owns materialization. I02 intentionally only reads
            // the fact table here so a browser request never becomes a 370-day backfill worker.
            $window = $this->resolveLocalDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);
            $row = $this->dailySummaryService
                ->salesSummaryQuery(
                    $outletIds,
                    $window['requested_from']->format('Y-m-d'),
                    $window['requested_to']->format('Y-m-d')
                )
                ->selectRaw('COALESCE(SUM(rdss.trx_count), 0) as transaction_count')
                ->selectRaw('COALESCE(SUM(rdss.subtotal_sales), 0) as total_gross_sales')
                ->selectRaw('COALESCE(SUM(rdss.discount_total), 0) as total_discount')
                ->selectRaw('COALESCE(SUM(GREATEST(rdss.subtotal_sales - rdss.discount_total, 0)), 0) as total_net_sales')
                ->selectRaw('COALESCE(SUM(rdss.tax_total), 0) as total_tax')
                ->selectRaw('COALESCE(SUM(rdss.grand_sales), 0) as total_collected')
                ->first();

            if ($row) {
                return $row;
            }
        }

        return (clone $filteredBase)
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.subtotal, 0)), 0) as total_gross_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.discount_total, 0)), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(s.subtotal, 0) - COALESCE(s.discount_total, 0), 0)), 0) as total_net_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as total_tax')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as total_collected')
            ->first() ?? (object) [];
    }

    private function applySaleNumberFilter(Builder $query, string $rawSearch): void
    {
        $needle = trim($rawSearch);
        if ($needle === '') {
            return;
        }

        $digitsOnly = preg_replace('/\D+/', '', $needle);
        $query->where(function ($inner) use ($needle, $digitsOnly) {
            $inner->where('s.sale_number', 'like', '%' . $needle . '%');

            if ($digitsOnly !== '' && mb_strlen($digitsOnly) <= 8) {
                $inner->orWhereRaw('RIGHT(s.sale_number, 8) like ?', ['%' . $digitsOnly . '%']);
            }
        });
    }

    private function applyChannelFilter(Builder $query, string $channel): void
    {
        $normalized = mb_strtoupper(trim($channel));
        $deliverySource = mb_strtolower(trim($channel));

        if (in_array($normalized, ['DINE_IN', 'TAKEAWAY', 'DELIVERY'], true)) {
            $query->where(function ($inner) use ($normalized) {
                $inner->where('s.channel', $normalized)
                    ->orWhere(function ($mixed) use ($normalized) {
                        $mixed->where('s.channel', 'MIXED')
                            ->whereExists(function ($exists) use ($normalized) {
                                $exists->selectRaw('1')
                                    ->from('sale_items as si_filter')
                                    ->whereColumn('si_filter.sale_id', 's.id')
                                    ->whereNull('si_filter.voided_at')
                                    ->where('si_filter.channel', $normalized);
                            });
                    });
            });
            return;
        }

        if ($normalized === 'MIXED') {
            $query->where('s.channel', 'MIXED');
            return;
        }

        $query->where(function ($inner) use ($channel, $deliverySource) {
            $inner->where(function ($delivery) use ($deliverySource) {
                $delivery->where('s.channel', 'DELIVERY')
                    ->whereRaw("LOWER(TRIM(COALESCE(s.online_order_source, ''))) = ?", [$deliverySource]);
            })->orWhere(function ($mixed) use ($channel) {
                $mixed->where('s.channel', 'MIXED')
                    ->whereRaw("(SELECT GROUP_CONCAT(DISTINCT si_filter.channel ORDER BY FIELD(si_filter.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si_filter.channel SEPARATOR ' + ') FROM sale_items si_filter WHERE si_filter.sale_id = s.id AND si_filter.voided_at IS NULL) = ?", [$channel]);
            });
        });
    }

    private function applyPaymentMethodFilter(Builder $query, string $paymentMethod): void
    {
        if ($paymentMethod === '') {
            return;
        }

        $query->where(function ($inner) use ($paymentMethod) {
            $inner->where('s.payment_method_name', $paymentMethod)
                ->orWhereExists(function ($exists) use ($paymentMethod) {
                    $exists->selectRaw('1')
                        ->from('sale_payments as sp_filter')
                        ->join('payment_methods as pm_filter', 'pm_filter.id', '=', 'sp_filter.payment_method_id')
                        ->whereColumn('sp_filter.sale_id', 's.id')
                        ->where('pm_filter.name', $paymentMethod);
                });
        });
    }

    private function resolveChannelOptions(array $outletIds, array $filters, string $timezone): array
    {
        if ($outletIds === []) {
            return [];
        }

        $window = $this->resolveLocalDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        return $this->dailySummaryService
            ->channelSummaryQuery($outletIds, $window['requested_from']->format('Y-m-d'), $window['requested_to']->format('Y-m-d'))
            ->selectRaw('TRIM(rdcs.display_channel) as channel_value')
            ->whereRaw("TRIM(COALESCE(rdcs.display_channel, '')) <> ''")
            ->distinct()
            ->orderBy('channel_value')
            ->pluck('channel_value')
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
    }

    private function resolvePaymentMethodOptions(array $outletIds, array $filters, string $timezone): array
    {
        if ($outletIds === []) {
            return [];
        }

        $window = $this->resolveLocalDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        return $this->dailySummaryService
            ->paymentSummaryQuery($outletIds, $window['requested_from']->format('Y-m-d'), $window['requested_to']->format('Y-m-d'))
            ->selectRaw('TRIM(rdps.payment_method_name) as payment_method_name')
            ->whereRaw("TRIM(COALESCE(rdps.payment_method_name, '')) <> ''")
            ->distinct()
            ->orderBy('payment_method_name')
            ->pluck('payment_method_name')
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
    }

    private function resolvePaymentDisplayBySaleIds(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        return DB::table('sale_payments as sp')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereIn('sp.sale_id', $saleIds)
            ->selectRaw('sp.sale_id')
            ->selectRaw("GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(pm.name), ''), 'Payment'), CASE WHEN COALESCE(sp.amount, 0) > 0 THEN CONCAT(' (', sp.amount, ')') ELSE '' END) ORDER BY sp.created_at, sp.id SEPARATOR ', ') as payment_method_display")
            ->groupBy('sp.sale_id')
            ->pluck('payment_method_display', 'sale_id')
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])
            ->all();
    }

    private function resolveMixedChannelDisplayBySaleIds(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        return DB::table('sale_items as si')
            ->whereIn('si.sale_id', $saleIds)
            ->whereNull('si.voided_at')
            ->selectRaw('si.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->groupBy('si.sale_id')
            ->pluck('channel_display', 'sale_id')
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])
            ->all();
    }

    private function displayChannelForRow(object $row, ?string $mixedChannel): string
    {
        $channel = mb_strtoupper(trim((string) ($row->channel ?? '')));
        $source = mb_strtolower(trim((string) ($row->online_order_source ?? '')));

        if ($channel === 'DELIVERY' && $source !== '') {
            return $source;
        }
        if ($channel === 'MIXED' && trim((string) $mixedChannel) !== '') {
            return trim((string) $mixedChannel);
        }

        return $channel !== '' ? $channel : '-';
    }

    private function buildExportFilename(string $outletScopeName, string $dateFrom, string $dateTo): string
    {
        $safeOutlet = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($outletScopeName)), '_');
        if ($safeOutlet === '') {
            $safeOutlet = 'semua_outlet';
        }

        return sprintf('sales_collected_%s_%s_to_%s.xlsx', $safeOutlet, $dateFrom, $dateTo);
    }

    private function applySorting(Builder $query, string $sort, string $dir): void
    {
        switch ($sort) {
            case 'sale_number':
                $query->orderBy('s.sale_number', $dir);
                break;
            case 'outlet':
                $query->orderBy('o.name', $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'time':
            case 'date':
                $query->orderBy('s.created_at', $dir);
                break;
            case 'gross_sales':
                $query->orderBy('s.subtotal', $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'discount':
                $query->orderBy('s.discount_total', $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'net_sales':
                $query->orderByRaw('GREATEST(s.subtotal - s.discount_total, 0) ' . $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'tax':
                $query->orderByRaw('COALESCE(s.tax_total, 0) ' . $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'total_collected':
                $query->orderByRaw('COALESCE(s.grand_total, 0) ' . $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'collected_by':
                $query->orderBy('s.cashier_name', $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'channel':
                // Sort from sale snapshot fields only. Full MIXED display decoration is
                // intentionally resolved after pagination to avoid a year-wide item GROUP BY.
                $query->orderByRaw("CASE WHEN UPPER(COALESCE(s.channel, '')) = 'DELIVERY' AND NULLIF(TRIM(COALESCE(s.online_order_source, '')), '') IS NOT NULL THEN LOWER(TRIM(s.online_order_source)) ELSE UPPER(COALESCE(s.channel, '')) END " . $dir)
                    ->orderBy('s.created_at', 'desc');
                break;
            case 'payment_method':
                // Primary snapshot is sortable without aggregating every sale_payment row.
                $query->orderByRaw("COALESCE(NULLIF(TRIM(s.payment_method_name), ''), '-') " . $dir)
                    ->orderBy('s.created_at', 'desc');
                break;
            case 'items':
                $query->orderBy('s.created_at', 'desc');
                break;
            default:
                $query->orderBy('s.created_at', 'desc');
                break;
        }

        $query->orderBy('s.id', $dir === 'asc' ? 'asc' : 'desc');
    }

    private function resolveItemsTextBySaleIds(array $saleIds): array
    {
        if (empty($saleIds)) {
            return [];
        }

        $addonSub = DB::table('sale_item_addons as sia')
            ->selectRaw("sia.sale_item_id, GROUP_CONCAT(DISTINCT sia.addon_name ORDER BY sia.addon_name SEPARATOR ' + ') as addon_names")
            ->groupBy('sia.sale_item_id');

        $grouped = [];
        $orderMap = [];

        foreach (array_chunk($saleIds, 500) as $saleIdChunk) {
            $rows = DB::table('sale_items as si')
                ->leftJoinSub($addonSub, 'addon_items', fn ($join) => $join->on('addon_items.sale_item_id', '=', 'si.id'))
                ->select([
                    'si.sale_id',
                    'si.product_name',
                    'si.variant_name',
                    'si.note',
                    'si.qty as total_qty',
                    'addon_items.addon_names',
                    'si.created_at',
                    'si.id',
                ])
                ->whereIn('si.sale_id', $saleIdChunk)
                ->whereNull('si.voided_at')
                ->orderBy('si.created_at')
                ->orderBy('si.id')
                ->get();

            foreach ($rows as $row) {
                $saleId = (string) $row->sale_id;
                $label = $this->formatItemLabel(
                    (string) ($row->product_name ?? ''),
                    (string) ($row->variant_name ?? ''),
                    (string) ($row->addon_names ?? ''),
                    (string) ($row->note ?? '')
                );
                if ($label === '') {
                    continue;
                }

                if (!isset($grouped[$saleId])) {
                    $grouped[$saleId] = [];
                    $orderMap[$saleId] = [];
                }

                if (!array_key_exists($label, $grouped[$saleId])) {
                    $grouped[$saleId][$label] = 0;
                    $orderMap[$saleId][] = $label;
                }

                $grouped[$saleId][$label] += (int) ($row->total_qty ?? 0);
            }
        }

        $result = [];
        foreach ($grouped as $saleId => $labels) {
            $parts = [];
            foreach ($orderMap[$saleId] as $label) {
                $qty = (int) ($labels[$label] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $parts[] = $qty > 1 ? sprintf('%s x %d', $label, $qty) : $label;
            }
            $result[$saleId] = !empty($parts) ? implode(', ', $parts) : '-';
        }

        return $result;
    }

    private function formatItemLabel(string $productName, string $variantName, string $addonNames, string $note = ''): string
    {
        $productName = trim($productName);
        if ($productName === '') {
            return '';
        }

        $qualifiers = [];
        $variantName = trim($variantName);
        $variantLower = strtolower($variantName);
        if ($variantName !== '' && !in_array($variantLower, ['regular', 'default', '-'], true)) {
            $qualifiers[] = $variantName;
        }

        $addonNames = trim($addonNames);
        if ($addonNames !== '') {
            $qualifiers[] = $addonNames;
        }

        $note = trim((string) preg_replace('/\s+/', ' ', $note));
        if ($note !== '') {
            $qualifiers[] = $note;
        }

        if (!empty($qualifiers)) {
            return sprintf('%s (%s)', $productName, implode(', ', $qualifiers));
        }

        return $productName;
    }
}
