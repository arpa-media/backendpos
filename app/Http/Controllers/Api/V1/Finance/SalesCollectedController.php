<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListSalesCollectedRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\OutletScope;
use App\Support\TransactionDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesCollectedController extends Controller
{
    public function index(ListSalesCollectedRequest $request)
    {
        $v = $request->validated();
        $perPage = (int) ($v['per_page'] ?? 15);
        $page = max(1, (int) ($v['page'] ?? 1));
        $sort = (string) ($v['sort'] ?? 'date');
        $dir = strtolower((string) ($v['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $isExport = filter_var($v['export'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $outletId = OutletScope::id($request);
        $outletInfo = $this->resolveOutletScopeInfo($outletId);
        $timezone = $outletInfo['timezone'];

        $window = $this->resolveLocalDateRange(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = [$window['requested_from'], $window['requested_to'], $window['from_utc'], $window['to_utc']];

        $summaryQuery = $this->buildBaseQuery($outletId, $fromQuery, $toQuery, $v, true, true);
        $summary = (clone $summaryQuery)
            ->selectRaw('COALESCE(SUM(s.subtotal), 0) as total_gross_sales')
            ->selectRaw('COALESCE(SUM(s.discount_total), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(GREATEST(s.subtotal - s.discount_total, 0)), 0) as total_net_sales')
            ->selectRaw('COALESCE(SUM(s.tax_total), 0) as total_tax')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as total_collected')
            ->first();

        $channelOptions = $this->resolveChannelOptions($outletId, $fromQuery, $toQuery, $v);
        $paymentOptions = $this->resolvePaymentMethodOptions($outletId, $fromQuery, $toQuery, $v);

        $rowsQuery = $this->buildBaseQuery($outletId, $fromQuery, $toQuery, $v, true, true)
            ->select([
                's.id',
                's.sale_number',
                's.outlet_id',
                'o.name as outlet_name',
                'o.timezone as outlet_timezone',
                's.created_at',
                's.subtotal',
                's.discount_total',
                's.tax_total',
                's.rounding_total',
                's.grand_total',
                's.paid_total',
                's.cashier_name',
            ])
            ->selectRaw('GREATEST(s.subtotal - s.discount_total, 0) as net_sales')
            ->selectRaw('s.grand_total as total_collected')
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

        $saleIds = $rows->pluck('id')->filter()->values()->all();
        $itemsMap = $this->resolveItemsTextBySaleIds($saleIds);

        $items = $rows->map(function ($row) use ($itemsMap) {
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
                'tax' => (int) ($row->tax_total ?? 0),
                'total_collected' => (int) ($row->total_collected ?? 0),
                'rounding_total' => (int) ($row->rounding_total ?? 0),
                'grand_total' => (int) ($row->grand_total ?? 0),
                'paid_total' => (int) ($row->paid_total ?? 0),
                'collected_by' => (string) ($row->cashier_name ?? '-'),
                'items' => $itemsMap[(string) $row->id] ?? '-',
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
        ];

        $metaPayload = [
            'timezone' => $timezone,
            'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
            'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
            'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'outlet_scope_id' => $outletId,
            'outlet_scope_name' => $outletInfo['name'],
            'sort' => $sort,
            'dir' => $dir,
            'performance_notes' => [
                'payment_options_query' => 'distinct-union on filtered sales scope',
                'items_query' => 'chunked sale id batches',
                'recommended_indexes_migration' => '2026_03_26_100500_add_sales_collected_indexes',
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

    private function resolveOutletScopeInfo(?string $outletId): array
    {
        $defaultTimezone = config('app.timezone', 'Asia/Jakarta');

        if (!$outletId) {
            return [
                'name' => 'Semua Outlet',
                'timezone' => $defaultTimezone,
            ];
        }

        $outlet = DB::table('outlets')
            ->select(['name', 'timezone'])
            ->where('id', $outletId)
            ->first();

        return [
            'name' => (string) ($outlet->name ?? 'Outlet'),
            'timezone' => TransactionDate::normalizeTimezone((string) ($outlet->timezone ?: $defaultTimezone), $defaultTimezone),
        ];
    }

    private function resolveLocalDateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        return TransactionDate::businessDateWindow($dateFrom, $dateTo, $timezone);
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

    private function buildBaseQuery(?string $outletId, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, bool $applyChannelFilter = true, bool $applyPaymentFilter = true): Builder
    {
        $query = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoinSub($this->paymentSummarySubquery(), 'payments', fn ($join) => $join->on('payments.sale_id', '=', 's.id'))
            ->leftJoinSub($this->channelMapSubquery(), 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 's.id'))
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when($outletId, fn ($q) => $q->where('s.outlet_id', $outletId));

        $this->applyBusinessDateScope($query, $fromQuery, $toQuery, $filters, $outletId ? $this->resolveOutletScopeInfo($outletId)['timezone'] : config('app.timezone', 'Asia/Jakarta'));

        $this->applySaleNumberFilter($query, (string) ($filters['q'] ?? ''));

        if ($applyChannelFilter && !empty($filters['channel'])) {
            $channel = trim((string) $filters['channel']);
            $this->applyChannelFilter($query, $channel);
        }

        if ($applyPaymentFilter && !empty($filters['payment_method_name'])) {
            $paymentMethod = trim((string) $filters['payment_method_name']);
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

    private function buildFilteredSalesIdSubquery(?string $outletId, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, bool $applyChannelFilter = true, bool $applyPaymentFilter = true): Builder
    {
        return $this->buildBaseQuery($outletId, $fromQuery, $toQuery, $filters, $applyChannelFilter, $applyPaymentFilter)
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

    private function resolveChannelOptions(?string $outletId, CarbonInterface $fromLocal, CarbonInterface $toLocal, array $filters): array
    {
        return (clone $this->buildBaseQuery($outletId, $fromLocal, $toLocal, $filters, false, true))
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

    private function paymentSummarySubquery(): Builder
    {
        return DB::table('sale_payments as sp')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(pm.name), '') ORDER BY pm.name SEPARATOR ', ') as payment_method_names")
            ->selectRaw("GROUP_CONCAT(CONCAT(COALESCE(NULLIF(TRIM(pm.name), ''), 'Payment'), CASE WHEN COALESCE(sp.amount, 0) > 0 THEN CONCAT(' (', sp.amount, ')') ELSE '' END) ORDER BY sp.created_at, sp.id SEPARATOR ', ') as payment_method_display")
            ->groupBy('sp.sale_id');
    }

    private function channelMapSubquery(): Builder
    {
        return DB::table('sales as s1')
            ->leftJoinSub($this->saleItemChannelsSubquery(), 'item_channels', fn ($join) => $join->on('item_channels.sale_id', '=', 's1.id'))
            ->selectRaw('s1.id as sale_id')
            ->selectRaw("CASE
                WHEN UPPER(COALESCE(s1.channel, '')) = 'DELIVERY' AND NULLIF(TRIM(COALESCE(s1.online_order_source, '')), '') IS NOT NULL THEN LOWER(TRIM(s1.online_order_source))
                WHEN UPPER(COALESCE(s1.channel, '')) = 'MIXED' AND NULLIF(TRIM(COALESCE(item_channels.channel_display, '')), '') IS NOT NULL THEN item_channels.channel_display
                ELSE UPPER(COALESCE(s1.channel, ''))
            END as display_channel");
    }

    private function saleItemChannelsSubquery(): Builder
    {
        return DB::table('sale_items as si')
            ->selectRaw('si.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel, 'DINE_IN', 'TAKEAWAY', 'DELIVERY'), si.channel SEPARATOR ' + ') as channel_display")
            ->whereNull('si.voided_at')
            ->groupBy('si.sale_id');
    }

    private function resolvePaymentMethodOptions(?string $outletId, CarbonInterface $fromLocal, CarbonInterface $toLocal, array $filters): array
    {
        $filteredSales = $this->buildFilteredSalesIdSubquery($outletId, $fromLocal, $toLocal, $filters, true, false);

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
                $query->orderBy('s.tax_total', $dir)->orderBy('s.created_at', 'desc');
                break;
            case 'total_collected':
                $query->orderByRaw('(GREATEST(s.subtotal - s.discount_total, 0) + s.tax_total) ' . $dir)->orderBy('s.created_at', 'desc');
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
