<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\CashierAlignedSaleScopeService;
use App\Support\SaleStatuses;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope)
    {
    }

    private function rawCreatedAtValue($value)
    {
        if ($value instanceof Sale) {
            if (method_exists($value, 'getRawOriginal')) {
                $raw = $value->getRawOriginal('created_at');
                if ($raw !== null && $raw !== '') {
                    return $raw;
                }
            }

            if ($value->created_at) {
                return $value->created_at;
            }
        }

        return $value;
    }

    /**
     * Build dashboard summary for an outlet in date range (inclusive).
     *
     * If $outletId is null => summarize ALL outlets (admin "All").
     */
    public function summary(?string $outletId, array $filters): array
    {
        $status = $filters['status'] ?? SaleStatuses::PAID;
        $recentLimit = (int) ($filters['recent_limit'] ?? 10);
        $timezone = $this->resolveTimezone($outletId, $filters);
        $window = $this->resolveDashboardBusinessWindow(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );

        $selectedOutletIds = $this->selectedOutletIds($outletId, $filters);
        if (empty($selectedOutletIds)) {
            $selectedOutletIds = DB::table('outlets')->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        $eligibleSaleIds = $this->cashierAlignedSaleScope->eligibleSaleIds($selectedOutletIds, $filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        $salesBase = Sale::query()->where('status', $status)
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('id', $eligibleSaleIds));
        $this->applyOutletSelection($salesBase, $outletId, $filters, 'outlet_id');

        [$from, $to, $fromQuery, $toQuery] = $this->applyBusinessDateScope($salesBase, $outletId, $filters);

        $metrics = (clone $salesBase)
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(paid_total),0) as paid_total')
            ->selectRaw('COALESCE(SUM(change_total),0) as change_total')
            ->first();

        $trxCount = (int) ($metrics->trx_count ?? 0);
        $grossSales = (int) ($metrics->gross_sales ?? 0);

        $itemsSoldQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', $status)
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('sales.id', $eligibleSaleIds));
        $this->applyOutletSelection($itemsSoldQuery, $outletId, $filters, 'sales.outlet_id');
        $this->applyBusinessDateScope($itemsSoldQuery, $outletId, $filters, 'sales.created_at', 'sales.sale_number');
        $itemsSold = (int) $itemsSoldQuery
            ->selectRaw('COALESCE(SUM(sale_items.qty),0) as qty_sum')
            ->value('qty_sum');

        $avgTicket = $trxCount > 0 ? (int) floor($grossSales / $trxCount) : 0;

        $byChannel = (clone $salesBase)
            ->select('channel')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->groupBy('channel')
            ->orderBy('gross_sales', 'desc')
            ->get()
            ->map(fn ($r) => [
                'channel' => (string) $r->channel,
                'trx_count' => (int) $r->trx_count,
                'gross_sales' => (int) $r->gross_sales,
            ])
            ->values()
            ->all();

        $byPayment = (clone $salesBase)
            ->select('payment_method_type', 'payment_method_name')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->groupBy('payment_method_type', 'payment_method_name')
            ->orderBy('gross_sales', 'desc')
            ->get()
            ->map(fn ($r) => [
                'payment_method_type' => (string) ($r->payment_method_type ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? ''),
                'trx_count' => (int) $r->trx_count,
                'gross_sales' => (int) $r->gross_sales,
            ])
            ->values()
            ->all();

        $topItemsQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', $status)
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('sales.id', $eligibleSaleIds));
        $this->applyOutletSelection($topItemsQuery, $outletId, $filters, 'sales.outlet_id');
        $this->applyBusinessDateScope($topItemsQuery, $outletId, $filters, 'sales.created_at', 'sales.sale_number');
        $topItems = $topItemsQuery
            ->select('sale_items.variant_id', 'sale_items.product_name', 'sale_items.variant_name')
            ->selectRaw('COALESCE(SUM(sale_items.qty),0) as qty_sold')
            ->selectRaw('COALESCE(SUM(sale_items.line_total),0) as revenue')
            ->groupBy('sale_items.variant_id', 'sale_items.product_name', 'sale_items.variant_name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'variant_id' => (string) $r->variant_id,
                'product_name' => (string) $r->product_name,
                'variant_name' => (string) $r->variant_name,
                'qty_sold' => (int) $r->qty_sold,
                'revenue' => (int) $r->revenue,
            ])
            ->values()
            ->all();

        $recentSales = (clone $salesBase)
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get([
                'id',
                'outlet_id',
                'sale_number',
                'channel',
                'status',
                'cashier_name',
                'grand_total',
                'paid_total',
                'change_total',
                'payment_method_name',
                'payment_method_type',
                'created_at',
            ])
            ->map(function ($sale) use ($timezone) {
                $rawCreatedAt = $this->rawCreatedAtValue($sale);

                return [
                    'id' => (string) $sale->id,
                    'outlet_id' => (string) $sale->outlet_id,
                    'sale_number' => (string) $sale->sale_number,
                    'channel' => (string) $sale->channel,
                    'status' => (string) $sale->status,
                    'cashier_name' => $sale->cashier_name,
                    'payment_method_name' => $sale->payment_method_name,
                    'payment_method_type' => $sale->payment_method_type,
                    'grand_total' => (int) $sale->grand_total,
                    'paid_total' => (int) $sale->paid_total,
                    'change_total' => (int) $sale->change_total,
                    'created_at' => TransactionDate::formatSaleLocal($rawCreatedAt, $timezone, (string) $sale->sale_number),
                ];
            })
            ->values()
            ->all();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'status' => (string) $status,
                'outlet_id' => $outletId,
                'outlet_filter' => (string) ($filters['outlet_filter'] ?? ($outletId ?: 'ALL')),
                'outlet_scope_name' => (string) ($filters['scope_label'] ?? ($outletId ?: 'All Outlet')),
            ],
            'metrics' => [
                'trx_count' => $trxCount,
                'gross_sales' => $grossSales,
                'items_sold' => $itemsSold,
                'avg_ticket' => $avgTicket,
            ],
            'by_channel' => $byChannel,
            'by_payment_method' => $byPayment,
            'top_items' => $topItems,
            'recent_sales' => $recentSales,
        ];
    }


    public function summaryCsv(?string $outletId, array $filters): array
    {
        $status = $filters['status'] ?? SaleStatuses::PAID;
        $timezone = $this->resolveTimezone($outletId, $filters);
        $window = TransactionDate::businessDateWindow($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        $selectedOutletIds = $this->selectedOutletIds($outletId, $filters);
        if (empty($selectedOutletIds)) {
            $selectedOutletIds = DB::table('outlets')
                ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        $eligibleSaleIds = $this->cashierAlignedSaleScope->eligibleSaleIds(
            $selectedOutletIds,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );

        $salesBase = Sale::query()
            ->where('status', $status)
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('id', $eligibleSaleIds));
        $this->applyOutletSelection($salesBase, $outletId, $filters, 'outlet_id');
        TransactionDate::applyExactBusinessDateScope($salesBase, 'created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone, 'sale_number');

        $summaryPayload = $this->summary($outletId, $filters);
        $metrics = $summaryPayload['metrics'] ?? [];
        $byChannel = $summaryPayload['by_channel'] ?? [];
        $byPayment = $summaryPayload['by_payment_method'] ?? [];

        $dailyRows = $this->buildDailySummaryRows($salesBase, $eligibleSaleIds, $outletId, $filters, $window['timezone'], $window);

        $csvRows = [];
        $csvRows[] = ['Summary'];
        $csvRows[] = ['Gross Sales', (int) ($metrics['gross_sales'] ?? 0)];
        $csvRows[] = ['Transaction', (int) ($metrics['trx_count'] ?? 0)];
        $csvRows[] = ['Item Sold', (int) ($metrics['items_sold'] ?? 0)];
        $csvRows[] = ['Avg Ticket', (int) ($metrics['avg_ticket'] ?? 0)];
        $csvRows[] = [];

        $csvRows[] = ['Channel Summary'];
        $csvRows[] = ['Channel', 'Trx', 'Gross'];
        foreach ($byChannel as $row) {
            $csvRows[] = [
                (string) ($row['channel'] ?? ''),
                (int) ($row['trx_count'] ?? 0),
                (int) ($row['gross_sales'] ?? 0),
            ];
        }
        $csvRows[] = [];

        $csvRows[] = ['Payment Method Summary'];
        $csvRows[] = ['Payment Method', 'Trx', 'Gross'];
        foreach ($byPayment as $row) {
            $csvRows[] = [
                (string) (($row['payment_method_name'] ?? '') ?: ($row['payment_method_type'] ?? '-')),
                (int) ($row['trx_count'] ?? 0),
                (int) ($row['gross_sales'] ?? 0),
            ];
        }
        $csvRows[] = [];

        $dailyHeader = [
            'Date',
            'Transaction',
            'Item Sold',
            'Avg Ticket',
            'Gross Sales',
            'Discount',
            'Net Sales',
            'Tax',
            'Rounding',
            'Total Collected',
            'Tunai',
            'Qris BCA',
            'EDC BCA',
            'TF BCA',
            'QRIS BRI',
            'EDC BRI',
            'TF BRI',
            'GoFood',
            'GrabFood',
            'Debit/Card',
        ];
        $csvRows[] = ['Daily Summary'];
        $csvRows[] = $dailyHeader;
        foreach ($dailyRows as $row) {
            $csvRows[] = [
                $row['date'],
                $row['transaction'],
                $row['item_sold'],
                $row['avg_ticket'],
                $row['gross_sales'],
                $row['discount'],
                $row['net_sales'],
                $row['tax'],
                $row['rounding'],
                $row['total_collected'],
                $row['tunai'],
                $row['qris_bca'],
                $row['edc_bca'],
                $row['tf_bca'],
                $row['qris_bri'],
                $row['edc_bri'],
                $row['tf_bri'],
                $row['gofood'],
                $row['grabfood'],
                $row['debit_card'],
            ];
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($csvRows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        $filename = sprintf(
            'omzet-summary-%s-to-%s.csv',
            $window['requested_from']->toDateString(),
            $window['requested_to']->toDateString()
        );

        return [
            'filename' => $filename,
            'content' => $csv,
        ];
    }

    private function buildDailySummaryRows($salesBase, array $eligibleSaleIds, ?string $outletId, array $filters, string $timezone, array $window): array
    {
        $dateExpr = TransactionDate::resolvedSaleLocalSqlExpression('created_at', 'sale_number', $timezone);
        $paymentBucketExpr = $this->paymentBucketSqlExpression('channel', 'online_order_source', 'payment_method_type', 'payment_method_name');

        $salesRows = (clone $salesBase)
            ->selectRaw("DATE({$dateExpr}) as sale_date")
            ->selectRaw('COUNT(*) as transaction')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(discount_total),0) as discount')
            ->selectRaw('COALESCE(SUM(subtotal - discount_total),0) as net_sales')
            ->selectRaw('COALESCE(SUM(tax_total),0) as tax')
            ->selectRaw('COALESCE(SUM(rounding_total),0) as rounding')
            ->selectRaw('COALESCE(SUM(paid_total - change_total),0) as total_collected')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'tunai' THEN grand_total ELSE 0 END),0) as tunai")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'qris_bca' THEN grand_total ELSE 0 END),0) as qris_bca")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'edc_bca' THEN grand_total ELSE 0 END),0) as edc_bca")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'tf_bca' THEN grand_total ELSE 0 END),0) as tf_bca")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'qris_bri' THEN grand_total ELSE 0 END),0) as qris_bri")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'edc_bri' THEN grand_total ELSE 0 END),0) as edc_bri")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'tf_bri' THEN grand_total ELSE 0 END),0) as tf_bri")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'gofood' THEN grand_total ELSE 0 END),0) as gofood")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'grabfood' THEN grand_total ELSE 0 END),0) as grabfood")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paymentBucketExpr} = 'debit_card' THEN grand_total ELSE 0 END),0) as debit_card")
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->sale_date);

        $itemDateExpr = TransactionDate::resolvedSaleLocalSqlExpression('sales.created_at', 'sales.sale_number', $timezone);
        $itemRowsQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', $filters['status'] ?? SaleStatuses::PAID)
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('sales.id', $eligibleSaleIds));
        $this->applyOutletSelection($itemRowsQuery, $outletId, $filters, 'sales.outlet_id');
        TransactionDate::applyExactBusinessDateScope($itemRowsQuery, 'sales.created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone, 'sales.sale_number');

        $itemRows = $itemRowsQuery
            ->selectRaw("DATE({$itemDateExpr}) as sale_date")
            ->selectRaw('COALESCE(SUM(sale_items.qty),0) as item_sold')
            ->groupBy('sale_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->sale_date);

        $rows = [];
        for ($cursor = $window['requested_from']->startOfDay(); $cursor->lessThanOrEqualTo($window['requested_to']->startOfDay()); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $saleRow = $salesRows->get($date);
            $itemRow = $itemRows->get($date);
            $transaction = (int) ($saleRow->transaction ?? 0);
            $grossSales = (int) ($saleRow->gross_sales ?? 0);

            $rows[] = [
                'date' => $date,
                'transaction' => $transaction,
                'item_sold' => (int) ($itemRow->item_sold ?? 0),
                'avg_ticket' => $transaction > 0 ? (int) floor($grossSales / $transaction) : 0,
                'gross_sales' => $grossSales,
                'discount' => (int) ($saleRow->discount ?? 0),
                'net_sales' => (int) ($saleRow->net_sales ?? 0),
                'tax' => (int) ($saleRow->tax ?? 0),
                'rounding' => (int) ($saleRow->rounding ?? 0),
                'total_collected' => (int) ($saleRow->total_collected ?? 0),
                'tunai' => (int) ($saleRow->tunai ?? 0),
                'qris_bca' => (int) ($saleRow->qris_bca ?? 0),
                'edc_bca' => (int) ($saleRow->edc_bca ?? 0),
                'tf_bca' => (int) ($saleRow->tf_bca ?? 0),
                'qris_bri' => (int) ($saleRow->qris_bri ?? 0),
                'edc_bri' => (int) ($saleRow->edc_bri ?? 0),
                'tf_bri' => (int) ($saleRow->tf_bri ?? 0),
                'gofood' => (int) ($saleRow->gofood ?? 0),
                'grabfood' => (int) ($saleRow->grabfood ?? 0),
                'debit_card' => (int) ($saleRow->debit_card ?? 0),
            ];
        }

        return $rows;
    }

    private function paymentBucketSqlExpression(string $channelColumn, string $onlineOrderSourceColumn, string $paymentMethodTypeColumn, string $paymentMethodNameColumn): string
    {
        $haystack = "LOWER(CONCAT_WS(' ', COALESCE({$channelColumn}, ''), COALESCE({$onlineOrderSourceColumn}, ''), COALESCE({$paymentMethodTypeColumn}, ''), COALESCE({$paymentMethodNameColumn}, '')))";

        return implode(' ', [
            'CASE',
            "WHEN {$haystack} LIKE '%gofood%' OR {$haystack} LIKE '%go food%' THEN 'gofood'",
            "WHEN {$haystack} LIKE '%grabfood%' OR {$haystack} LIKE '%grab food%' THEN 'grabfood'",
            "WHEN ({$haystack} LIKE '%qris%' OR {$haystack} LIKE '%qr%') AND {$haystack} LIKE '%bca%' THEN 'qris_bca'",
            "WHEN {$haystack} LIKE '%edc%' AND {$haystack} LIKE '%bca%' THEN 'edc_bca'",
            "WHEN ({$haystack} LIKE '%tf%' OR {$haystack} LIKE '%transfer%') AND {$haystack} LIKE '%bca%' THEN 'tf_bca'",
            "WHEN ({$haystack} LIKE '%qris%' OR {$haystack} LIKE '%qr%') AND {$haystack} LIKE '%bri%' THEN 'qris_bri'",
            "WHEN {$haystack} LIKE '%edc%' AND {$haystack} LIKE '%bri%' THEN 'edc_bri'",
            "WHEN ({$haystack} LIKE '%tf%' OR {$haystack} LIKE '%transfer%') AND {$haystack} LIKE '%bri%' THEN 'tf_bri'",
            "WHEN {$haystack} LIKE '%tunai%' OR {$haystack} LIKE '%cash%' THEN 'tunai'",
            "WHEN {$haystack} LIKE '%debit%' OR {$haystack} LIKE '%card%' OR {$haystack} LIKE '%credit%' OR {$haystack} LIKE '%kartu%' THEN 'debit_card'",
            "ELSE 'other'",
            'END',
        ]);
    }

    private function summaryForMakassarBusinessWindow(string $outletId, array $filters, string $status, int $recentLimit, string $timezone, array $window): array
    {
        $candidateQuery = Sale::query()->where('status', $status);
        $this->applyOutletSelection($candidateQuery, $outletId, $filters, 'outlet_id');

        $candidateFilters = $filters;
        $candidateFilters['date_from'] = $window['requested_from']->toDateString();
        $candidateFilters['date_to'] = $window['requested_to']->addDay()->toDateString();
        $this->applyBusinessDateScope($candidateQuery, $outletId, $candidateFilters);

        $candidateSales = $candidateQuery
            ->get([
                'id',
                'outlet_id',
                'sale_number',
                'channel',
                'status',
                'cashier_name',
                'grand_total',
                'paid_total',
                'change_total',
                'payment_method_name',
                'payment_method_type',
                'created_at',
            ]);

        $sales = $candidateSales
            ->filter(fn (Sale $sale) => $this->saleFallsWithinDashboardBusinessWindow($sale, $window['from_local'], $window['to_exclusive_local'], $timezone))
            ->values();

        $saleIds = $sales->pluck('id')->filter()->values()->all();

        $trxCount = $sales->count();
        $grossSales = (int) $sales->sum('grand_total');
        $itemsSold = 0;
        $topItems = [];
        $byChannel = [];
        $byPayment = [];

        if (!empty($saleIds)) {
            $itemsSold = (int) SaleItem::query()->whereIn('sale_id', $saleIds)->sum('qty');

            $topItems = SaleItem::query()
                ->whereIn('sale_id', $saleIds)
                ->select('variant_id', 'product_name', 'variant_name')
                ->selectRaw('COALESCE(SUM(qty),0) as qty_sold')
                ->selectRaw('COALESCE(SUM(line_total),0) as revenue')
                ->groupBy('variant_id', 'product_name', 'variant_name')
                ->orderByDesc('qty_sold')
                ->limit(5)
                ->get()
                ->map(fn ($r) => [
                    'variant_id' => (string) $r->variant_id,
                    'product_name' => (string) $r->product_name,
                    'variant_name' => (string) $r->variant_name,
                    'qty_sold' => (int) $r->qty_sold,
                    'revenue' => (int) $r->revenue,
                ])
                ->values()
                ->all();
        }

        $avgTicket = $trxCount > 0 ? (int) floor($grossSales / $trxCount) : 0;

        $byChannel = $sales
            ->groupBy(fn ($sale) => (string) ($sale->channel ?? ''))
            ->map(fn ($group, $channel) => [
                'channel' => (string) $channel,
                'trx_count' => $group->count(),
                'gross_sales' => (int) $group->sum('grand_total'),
            ])
            ->sortByDesc('gross_sales')
            ->values()
            ->all();

        $byPayment = $sales
            ->groupBy(fn ($sale) => implode('|', [
                (string) ($sale->payment_method_type ?? ''),
                (string) ($sale->payment_method_name ?? ''),
            ]))
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'payment_method_type' => (string) ($first->payment_method_type ?? ''),
                    'payment_method_name' => (string) ($first->payment_method_name ?? ''),
                    'trx_count' => $group->count(),
                    'gross_sales' => (int) $group->sum('grand_total'),
                ];
            })
            ->sortByDesc('gross_sales')
            ->values()
            ->all();

        $recentSales = $sales
            ->sortByDesc(function ($sale) {
                return (string) ($sale->created_at ?: (method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('created_at') : ''));
            })
            ->take($recentLimit)
            ->values()
            ->map(function ($sale) use ($timezone) {
                $rawCreatedAt = $this->rawCreatedAtValue($sale);

                return [
                    'id' => (string) $sale->id,
                    'outlet_id' => (string) $sale->outlet_id,
                    'sale_number' => (string) $sale->sale_number,
                    'channel' => (string) $sale->channel,
                    'status' => (string) $sale->status,
                    'cashier_name' => $sale->cashier_name,
                    'payment_method_name' => $sale->payment_method_name,
                    'payment_method_type' => $sale->payment_method_type,
                    'grand_total' => (int) $sale->grand_total,
                    'paid_total' => (int) $sale->paid_total,
                    'change_total' => (int) $sale->change_total,
                    'created_at' => TransactionDate::formatSaleLocal($rawCreatedAt, $timezone, (string) $sale->sale_number),
                ];
            })
            ->all();

        return [
            'range' => [
                'date_from' => $window['requested_from']->toDateString(),
                'date_to' => $window['requested_to']->toDateString(),
                'status' => (string) $status,
                'outlet_id' => $outletId,
            ],
            'metrics' => [
                'trx_count' => $trxCount,
                'gross_sales' => $grossSales,
                'items_sold' => $itemsSold,
                'avg_ticket' => $avgTicket,
            ],
            'by_channel' => $byChannel,
            'by_payment_method' => $byPayment,
            'top_items' => $topItems,
            'recent_sales' => $recentSales,
        ];
    }

    private function isMakassarDashboardBusinessTimezone(?string $timezone = null): bool
    {
        return TransactionDate::normalizeTimezone($timezone, config('app.timezone', 'Asia/Jakarta')) === 'Asia/Makassar';
    }

    private function resolveDashboardBusinessToday(?string $timezone = null): string
    {
        $tz = TransactionDate::normalizeTimezone($timezone, config('app.timezone', 'Asia/Jakarta'));
        $now = CarbonImmutable::now($tz);

        if ($this->isMakassarDashboardBusinessTimezone($tz)) {
            return $now->subHour()->toDateString();
        }

        return $now->toDateString();
    }

    private function resolveDashboardBusinessWindow(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = TransactionDate::normalizeTimezone($timezone, config('app.timezone', 'Asia/Jakarta'));
        $today = CarbonImmutable::parse($this->resolveDashboardBusinessToday($tz), $tz)->startOfDay();

        try {
            $requestedFrom = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedFrom = $today;
        }

        try {
            $requestedTo = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedTo = $today;
        }

        if ($requestedTo->lessThan($requestedFrom)) {
            [$requestedFrom, $requestedTo] = [$requestedTo, $requestedFrom];
        }

        if ($this->isMakassarDashboardBusinessTimezone($tz)) {
            $fromLocal = $requestedFrom->addHour();
            $toExclusiveLocal = $requestedTo->addDay()->addHour();
        } else {
            $fromLocal = $requestedFrom->startOfDay();
            $toExclusiveLocal = $requestedTo->addDay()->startOfDay();
        }

        return [
            'timezone' => $tz,
            'requested_from' => $requestedFrom,
            'requested_to' => $requestedTo,
            'from_local' => $fromLocal,
            'to_exclusive_local' => $toExclusiveLocal,
        ];
    }

    private function saleLocalMomentForDashboardWindow(Sale $sale, ?string $timezone = null): ?CarbonImmutable
    {
        $localIso = TransactionDate::toSaleIso(
            $this->rawCreatedAtValue($sale),
            $timezone,
            (string) $sale->sale_number
        );

        if (!$localIso) {
            return null;
        }

        try {
            return CarbonImmutable::parse($localIso);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saleFallsWithinDashboardBusinessWindow(Sale $sale, CarbonImmutable $fromLocal, CarbonImmutable $toExclusiveLocal, ?string $timezone = null): bool
    {
        $moment = $this->saleLocalMomentForDashboardWindow($sale, $timezone);
        if (!$moment) {
            return false;
        }

        return $moment->greaterThanOrEqualTo($fromLocal) && $moment->lessThan($toExclusiveLocal);
    }

    private function applyBusinessDateScope($query, ?string $outletId, array $filters, string $createdAtColumn = 'created_at', string $saleNumberColumn = 'sale_number'): array
    {
        $timezone = $this->resolveTimezone($outletId, $filters);
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = TransactionDate::dateRange(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );
        $tokens = TransactionDate::dateTokens($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $tokens, $fromQuery, $toQuery) {
            $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
                foreach ($tokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
                }
            })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromQuery, $toQuery) {
                $fallbackScope
                    ->where(function ($legacyScope) use ($saleNumberColumn) {
                        $legacyScope
                            ->whereNull($saleNumberColumn)
                            ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                    })
                    ->whereBetween($createdAtColumn, [$fromQuery->toDateTimeString(), $toQuery->toDateTimeString()]);
            });
        });

        return [$fromLocal, $toLocal, $fromQuery, $toQuery, $timezone];
    }

    private function selectedOutletIds(?string $outletId, array $filters = []): array
    {
        $ids = array_values(array_filter(array_map('strval', $filters['scope_outlet_ids'] ?? [])));
        if (!empty($ids)) {
            return $ids;
        }

        return $outletId ? [(string) $outletId] : [];
    }

    private function applyOutletSelection($query, ?string $outletId, array $filters = [], string $column = 'outlet_id'): void
    {
        $ids = $this->selectedOutletIds($outletId, $filters);
        if (count($ids) === 1) {
            $query->where($column, $ids[0]);
            return;
        }

        if (count($ids) > 1) {
            $query->whereIn($column, $ids);
        }
    }

    private function resolveTimezone(?string $outletId, array $filters = []): string
    {
        $defaultTimezone = TransactionDate::normalizeTimezone((string) config('app.timezone', 'Asia/Jakarta'), 'Asia/Jakarta');

        if (!empty($filters['scope_timezone'])) {
            return TransactionDate::normalizeTimezone((string) $filters['scope_timezone'], $defaultTimezone);
        }

        if (!$outletId) {
            return $defaultTimezone;
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone(filled($timezone) ? (string) $timezone : $defaultTimezone, $defaultTimezone);
    }
}
