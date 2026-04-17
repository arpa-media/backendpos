<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListSalesCollectedRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Models\Sale;
use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleScopeCacheService;
use App\Support\FinanceOutletFilter;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\TransactionDate;
use Throwable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesCollectedController extends Controller
{
    public function __construct(
        private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope,
        private readonly ReportSaleScopeCacheService $reportSaleScopeCache,
    ) {
    }

    public function detail(ListSalesCollectedRequest $request, string $saleId)
    {
        $v = $request->validated();
        $outletFilter = $this->resolveOutletFilter($v);
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        $window = $this->resolveLocalDateRange(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $v, $timezone);
        if (!($saleScope['has_rows'] ?? false)) {
            return ApiResponse::error('Transaksi tidak ditemukan pada filter Sales Collected ini.', 'SALES_COLLECTED_SALE_NOT_FOUND', 404);
        }

        $visible = DB::table('report_sale_scope_cache as rssc')
            ->where('rssc.scope_key', (string) $saleScope['scope_key'])
            ->where('rssc.sale_id', $saleId)
            ->where('rssc.expires_at', '>', now())
            ->exists();

        if (!$visible) {
            return ApiResponse::error('Transaksi tidak ditemukan pada filter Sales Collected ini.', 'SALES_COLLECTED_SALE_NOT_FOUND', 404);
        }

        $sale = Sale::query()
            ->with(['outlet', 'items.product.category', 'items.addons', 'payments', 'customer', 'cancelRequests'])
            ->where('id', $saleId)
            ->whereNull('deleted_at')
            ->where('status', 'PAID')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('outlet_id', $outletIds))
            ->first();
        if (!$sale) {
            return ApiResponse::error('Transaksi tidak ditemukan pada filter Sales Collected ini.', 'SALES_COLLECTED_SALE_NOT_FOUND', 404);
        }

        return ApiResponse::ok([
            'sale' => $this->transformSaleDetail($sale, $request),
            'meta' => [
                'outlet_scope_id' => $outletFilter['value'],
                'outlet_scope_name' => $outletFilter['label'],
                'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ],
        ], 'OK');
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
        $perPage = (int) ($v['per_page'] ?? 15);
        $page = max(1, (int) ($v['page'] ?? 1));
        $sort = (string) ($v['sort'] ?? 'date');
        $dir = strtolower((string) ($v['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $isExport = $this->toBool($v['export'] ?? false);
        $includeItems = $isExport || $this->toBool($v['include_items'] ?? false);
        $includeFilterOptions = $isExport || $this->toBool($v['include_filter_options'] ?? true);

        $outletFilter = $this->resolveOutletFilter($v);
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        $window = $this->resolveLocalDateRange(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );
        [$fromLocal, $toLocal] = [$window['requested_from'], $window['requested_to']];

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $v, $timezone);

        $summary = ($saleScope['has_rows'] ?? false)
            ? (clone $this->buildBaseQuery($outletIds, $saleScope, $v, $timezone, true, true, false))
                ->selectRaw('COALESCE(SUM(s.subtotal), 0) as total_gross_sales')
                ->selectRaw('COALESCE(SUM(s.discount_total), 0) as total_discount')
                ->selectRaw('COALESCE(SUM(GREATEST(s.subtotal - s.discount_total, 0)), 0) as total_net_sales')
                ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total, 0)), 0) as total_tax')
                ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total, 0)), 0) as total_collected')
                ->first()
            : (object) [
                'total_gross_sales' => 0,
                'total_discount' => 0,
                'total_net_sales' => 0,
                'total_tax' => 0,
                'total_collected' => 0,
            ];

        $channelOptions = [];
        $paymentOptions = [];
        if ($includeFilterOptions && ($saleScope['has_rows'] ?? false)) {
            $channelOptions = $this->resolveChannelOptions($outletIds, $saleScope, $v, $timezone);
            $paymentOptions = $this->resolvePaymentMethodOptions($outletIds, $saleScope, $v, $timezone);
        }

        $rowsQuery = $this->buildBaseQuery($outletIds, $saleScope, $v, $timezone, true, true, true)
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
                's.tax_total',
                's.rounding_total',
                's.grand_total',
                's.paid_total',
                's.cashier_name',
            ])
            ->selectRaw('GREATEST(COALESCE(s.subtotal, 0) - COALESCE(s.discount_total, 0), 0) as net_sales')
            ->selectRaw('COALESCE(s.tax_total, 0) as tax_total_cashier')
            ->selectRaw('COALESCE(s.rounding_total, 0) as rounding_total_cashier')
            ->selectRaw('COALESCE(s.grand_total, 0) as grand_total_cashier')
            ->selectRaw('COALESCE(s.grand_total, 0) as total_collected')
            ->selectRaw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) as display_channel")
            ->selectRaw("COALESCE(NULLIF(payments.payment_method_display, ''), NULLIF(payments.payment_method_names, ''), NULLIF(s.payment_method_name, ''), '-') as payment_method_display");

        $this->applySorting($rowsQuery, $sort, $dir);

        $paginationPayload = null;
        if ($isExport) {
            $rows = $rowsQuery->get();
        } else {
            $paginator = $rowsQuery->paginate($perPage, ['*'], 'page', $page)->withQueryString();
            $rows = collect($paginator->items());
            $paginationPayload = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        $saleIds = $rows->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $itemsMap = $includeItems ? $this->resolveItemsTextBySaleIds($saleIds) : [];

        $items = $rows->map(function ($row) use ($itemsMap, $includeItems) {
            $transactionTimezone = $row->outlet_timezone ?: config('app.timezone', 'Asia/Jakarta');
            $saleNumber = (string) ($row->sale_number ?? '');
            $date = TransactionDate::formatSaleLocal($row->created_at, $transactionTimezone, $saleNumber, 'Y-m-d');
            $time = TransactionDate::formatSaleLocal($row->created_at, $transactionTimezone, $saleNumber, 'H:i:s');
            $createdAt = TransactionDate::toSaleIso($row->created_at, $transactionTimezone, $saleNumber);

            return [
                'id' => (string) $row->id,
                'sale_id' => (string) $row->id,
                'sale_number' => $saleNumber,
                'sale_number_short' => mb_substr($saleNumber, -8),
                'outlet' => (string) ($row->outlet_name ?? '-'),
                'date' => $date,
                'time' => $time,
                'created_at' => $createdAt,
                'gross_sales' => (int) ($row->subtotal ?? 0),
                'discount' => (int) ($row->discount_total ?? 0),
                'net_sales' => (int) ($row->net_sales ?? 0),
                'tax' => (int) ($row->tax_total_cashier ?? $row->tax_total ?? 0),
                'total_collected' => (int) ($row->total_collected ?? $row->grand_total_cashier ?? $row->grand_total ?? 0),
                'rounding_total' => (int) ($row->rounding_total_cashier ?? $row->rounding_total ?? 0),
                'grand_total' => (int) ($row->grand_total_cashier ?? $row->grand_total ?? 0),
                'paid_total' => (int) ($row->paid_total ?? $row->grand_total_cashier ?? $row->grand_total ?? 0),
                'collected_by' => (string) ($row->cashier_name ?? '-'),
                'items' => $includeItems ? ($itemsMap[(string) $row->id] ?? '-') : '',
                'channel' => (string) ($row->display_channel ?? '-'),
                'payment_method' => (string) ($row->payment_method_display ?? '-'),
            ];
        })->values();

        $summaryPayload = [
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
                'list_mode' => $includeItems ? 'full' : 'lite',
                'filter_options' => $includeFilterOptions ? 'loaded' : 'deferred',
                'items_endpoint' => '/finance/sales-collected/items',
                'scope_cache_strategy' => 'exact_cashier_sale_ids_materialized',
            ],
        ];

        $payload = [
            'items' => $items,
            'summary' => $summaryPayload,
            'filters' => $filterPayload,
            'meta' => $metaPayload,
        ];

        if (!$isExport) {
            $payload['pagination'] = $paginationPayload;
        } else {
            $payload['export'] = [
                'filename' => $this->buildExportFilename($metaPayload['outlet_scope_name'], $filterPayload['date_from'], $filterPayload['date_to']),
                'columns' => [
                    'Sale Number (8 digit terakhir)',
                    'Outlet',
                    'Date',
                    'Time',
                    'Gross Sales',
                    'Discount',
                    'Net Sales',
                    'Tax',
                    'Total Collected',
                    'Collected by',
                    'Items',
                    'Channel',
                    'Payment Method',
                ],
                'total_rows' => $items->count(),
            ];
        }

        return ApiResponse::ok($payload, 'OK');
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

        $saleScope = $this->resolveEligibleSalesScope($outletIds, $v, $timezone);

        if (!($saleScope['has_rows'] ?? false)) {
            return ApiResponse::ok([
                'items_map' => [],
            ], 'OK');
        }

        $visibleSaleIds = $this->buildBaseQuery($outletIds, $saleScope, $v, $timezone, true, true, false)
            ->whereIn('s.id', $saleIds)
            ->select('s.id')
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

    private function resolveEligibleSalesScope(array $outletIds, array $filters, string $timezone): array
    {
        return $this->reportSaleScopeCache->remember(
            'sales_collected_cashier_aligned',
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

    private function scopeSalesSubquery(string $scopeKey): Builder
    {
        return $this->reportSaleScopeCache->subquery($scopeKey);
    }

    private function buildBaseQuery(array $outletIds, array $saleScope, array $filters, string $timezone, bool $applyChannelFilter = true, bool $applyPaymentFilter = true, bool $includeDecorations = true): Builder
    {
        $needsChannelJoin = $includeDecorations || ($applyChannelFilter && !empty($filters['channel']));
        $needsPaymentJoin = $includeDecorations;

        $query = DB::table('sales as s')
            ->join('report_sale_scope_cache as rssc', function ($join) use ($saleScope) {
                $join->on('rssc.sale_id', '=', 's.id')
                    ->where('rssc.scope_key', '=', (string) ($saleScope['scope_key'] ?? ''))
                    ->where('rssc.expires_at', '>', now());
            })
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($q) => $q->whereIn('s.outlet_id', $outletIds));

        if ($needsPaymentJoin) {
            $query->leftJoinSub($this->paymentSummarySubquery((string) ($saleScope['scope_key'] ?? '')), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'));
        }

        if ($needsChannelJoin) {
            $query->leftJoinSub($this->channelMapSubquery((string) ($saleScope['scope_key'] ?? '')), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'));
        }

        $this->applySaleNumberFilter($query, (string) ($filters['q'] ?? ''));

        if ($applyChannelFilter && !empty($filters['channel'])) {
            $channel = trim((string) $filters['channel']);
            $this->applyChannelFilter($query, $channel);
        }

        if ($applyPaymentFilter && !empty($filters['payment_method_name'])) {
            $paymentMethod = trim((string) ($filters['payment_method_name'] ?? ''));
            $query->where(function ($inner) use ($paymentMethod) {
                $inner->where('s.payment_method_name', $paymentMethod)
                    ->orWhereExists(function ($exists) use ($paymentMethod) {
                        $exists->selectRaw('1')
                            ->from('sale_payments as sp')
                            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
                            ->whereColumn('sp.sale_id', 's.id')
                            ->where('pm.name', $paymentMethod);
                    });
            });
        }

        return $query;
    }

    private function buildFilteredSalesIdSubquery(array $outletIds, array $saleScope, array $filters, string $timezone, bool $applyChannelFilter = true, bool $applyPaymentFilter = true): Builder
    {
        return $this->buildBaseQuery($outletIds, $saleScope, $filters, $timezone, $applyChannelFilter, $applyPaymentFilter, false)
            ->select('s.id')
            ->distinct();
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

    private function resolveChannelOptions(array $outletIds, array $saleScope, array $filters, string $timezone): array
    {
        return (clone $this->buildBaseQuery($outletIds, $saleScope, $filters, $timezone, false, true, true))
            ->selectRaw("TRIM(COALESCE(NULLIF(channel_map.display_channel, ''), '')) as channel_value")
            ->distinct()
            ->orderBy('channel_value')
            ->pluck('channel_value')
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
    }

    private function applyChannelFilter(Builder $query, string $channel): void
    {
        $normalized = mb_strtoupper(trim($channel));
        $deliverySource = mb_strtolower(trim($channel));

        if ($normalized === 'DINE_IN' || $normalized === 'TAKEAWAY' || $normalized === 'DELIVERY') {
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
            $inner->whereRaw("TRIM(COALESCE(channel_map.display_channel, '')) = ?", [$channel])
                ->orWhere(function ($delivery) use ($deliverySource) {
                    $delivery->where('s.channel', 'DELIVERY')
                        ->whereRaw("LOWER(TRIM(COALESCE(s.online_order_source, ''))) = ?", [$deliverySource]);
                });
        });
    }

    private function paymentSummarySubquery(string $scopeKey): Builder
    {
        return DB::table('sale_payments as sp')
            ->joinSub($this->scopeSalesSubquery($scopeKey), 'scope_sales', fn ($join) => $join->on('scope_sales.sale_id', '=', 'sp.sale_id'))
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(pm.name), '') ORDER BY pm.name SEPARATOR ', ') as payment_method_names")
            ->selectRaw("GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(pm.name), ''), 'Payment'), CASE WHEN COALESCE(sp.amount, 0) > 0 THEN CONCAT(' (', sp.amount, ')') ELSE '' END) ORDER BY sp.created_at, sp.id SEPARATOR ', ') as payment_method_display")
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

    private function resolvePaymentMethodOptions(array $outletIds, array $saleScope, array $filters, string $timezone): array
    {
        $filteredSales = $this->buildFilteredSalesIdSubquery($outletIds, $saleScope, $filters, $timezone, true, false);

        $snapshotOptions = DB::query()
            ->fromSub($filteredSales, 'fs')
            ->join('sales as s', 's.id', '=', 'fs.id')
            ->selectRaw('DISTINCT TRIM(s.payment_method_name) as payment_method_name')
            ->whereNotNull('s.payment_method_name')
            ->whereRaw("TRIM(s.payment_method_name) <> ''");

        $paymentOptions = DB::query()
            ->fromSub($filteredSales, 'fs')
            ->join('sale_payments as sp', 'sp.sale_id', '=', 'fs.id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('DISTINCT TRIM(pm.name) as payment_method_name')
            ->whereNotNull('pm.name')
            ->whereRaw("TRIM(pm.name) <> ''");

        return $snapshotOptions
            ->union($paymentOptions)
            ->orderBy('payment_method_name')
            ->pluck('payment_method_name')
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
    }

    private function buildExportFilename(string $outletScopeName, string $dateFrom, string $dateTo): string
    {
        $safeOutlet = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($outletScopeName)), '_');
        if ($safeOutlet === '') {
            $safeOutlet = 'semua_outlet';
        }

        return sprintf('sales_collected_%s_%s_to_%s.csv', $safeOutlet, $dateFrom, $dateTo);
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
                $query->orderByRaw("COALESCE(NULLIF(channel_map.display_channel, ''), UPPER(COALESCE(s.channel, ''))) " . $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'payment_method':
                $query->orderByRaw("COALESCE(NULLIF(payments.payment_method_display, ''), NULLIF(payments.payment_method_names, ''), NULLIF(s.payment_method_name, ''), '-') " . $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'items':
                $query->orderBy('s.created_at', 'desc');
                break;
            default:
                $query->orderBy('s.created_at', 'desc');
                break;
        }
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
